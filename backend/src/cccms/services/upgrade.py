"""Online upgrade for local development.

Git is invoked through a fixed argv (never a shell) behind three independent
gates: ``CCCMS_UPGRADE_ENABLE``, ``dev`` only, and a repository self-check
(``.git`` + ``frontend/package.json``).  The branch name is validated, so the
endpoint cannot be turned into an arbitrary command runner.  All subprocess
calls run in a worker thread so the event loop is never blocked.
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import shutil
import subprocess
from datetime import datetime
from urllib.parse import urlsplit, urlunsplit

from cccms import __version__
from cccms.config import PROJECT_DIR, get_settings
from cccms.core.context import current_context
from cccms.core.errors import Forbidden, ServiceUnavailable, ValidationFailed
from cccms.schemas.upgrade import (
    UpgradeCheckOut,
    UpgradeRemoteOut,
    UpgradeResultOut,
    UpgradeStatusOut,
    UpgradeTagOut,
)

logger = logging.getLogger("cccms.upgrade")

BRANCH_PATTERN = re.compile(r"^[A-Za-z0-9._/-]{1,120}$")
# git 远端名会作为 argv 传给 git，不允许空格、路径分隔符与以 '-' 开头的名字。
REMOTE_NAME_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")

# 随代码发布的内置同步源：克隆下来只有一个远端（甚至没有远端）时也能在页面上直接选。
# 覆盖方式见 CCCMS_UPGRADE_SOURCES（name=url,name=url，留空即用下面两条）。
BUILTIN_SOURCES: tuple[tuple[str, str], ...] = (
    ("github", "https://github.com/poetryiii/cccms"),
    ("gitee", "https://gitee.com/poetryiii/cccms"),
)


def _git_binary() -> str:
    binary = shutil.which("git")
    if not binary:
        raise ServiceUnavailable("服务器未安装 git")
    return binary


def _git(*args: str, timeout: int | None = None) -> subprocess.CompletedProcess[str]:
    settings = get_settings()
    environment = {**os.environ, "GIT_TERMINAL_PROMPT": "0", "LC_ALL": "C"}
    try:
        # 参数由服务端常量拼接, 未使用 shell=True, 不接受外部输入。
        return subprocess.run(  # noqa: S603
            [_git_binary(), *args],
            cwd=PROJECT_DIR,
            capture_output=True,
            text=True,
            timeout=timeout or settings.upgrade_timeout,
            check=False,
            env=environment,
        )
    except subprocess.TimeoutExpired as exc:
        raise ServiceUnavailable("git 操作超时") from exc


def _disabled_reason() -> str:
    settings = get_settings()
    if not settings.upgrade_enable:
        return "在线升级未启用（CCCMS_UPGRADE_ENABLE=false）"
    if settings.env != "dev":
        return "在线升级仅允许在 dev 环境使用"
    if not (PROJECT_DIR / ".git").exists():
        return "当前目录不是 git 仓库"
    if not (PROJECT_DIR / "frontend" / "package.json").exists():
        return "仓库自检失败：缺少 frontend/package.json"
    return ""


def _ensure_enabled() -> None:
    reason = _disabled_reason()
    if reason:
        raise Forbidden(reason)
    ctx = current_context()
    if (
        ctx is None
        or ctx.user is None
        or not ctx.user.is_super_admin
        or ctx.tenant is None
        or not ctx.tenant.is_platform
    ):
        raise Forbidden("仅平台超级管理员可执行在线升级")


def _stdout(result: subprocess.CompletedProcess[str]) -> str:
    return result.stdout.strip()


async def _git_async(*args: str, timeout_s: int | None = None) -> subprocess.CompletedProcess[str]:
    return await asyncio.to_thread(_git, *args, timeout=timeout_s)


async def _branch() -> str:
    branch = _stdout(await _git_async("rev-parse", "--abbrev-ref", "HEAD"))
    if not branch or branch == "HEAD":
        raise Forbidden("当前处于游离 HEAD，无法在线升级")
    return branch


def _parse_sources(raw: str) -> list[tuple[str, str]]:
    """解析同步源配置；留空用内置源。格式 ``name=url,name=url``。"""
    text = (raw or "").strip()
    if not text:
        return list(BUILTIN_SOURCES)
    rows: list[tuple[str, str]] = []
    for chunk in text.split(","):
        name, sep, url = chunk.partition("=")
        name, url = name.strip(), url.strip()
        if not sep or not url or not REMOTE_NAME_PATTERN.match(name):
            raise ServiceUnavailable(f"CCCMS_UPGRADE_SOURCES 配置格式错误：{chunk.strip() or '（空项）'}")
        rows.append((name, url))
    return rows


def _normalize_url(url: str) -> str:
    """比对远端地址：忽略大小写、尾斜杠与 .git 后缀。"""
    value = url.strip().rstrip("/")
    if value.endswith(".git"):
        value = value[: -len(".git")]
    return value.lower()


def _find_remote_by_url(url: str, urls: dict[str, str]) -> str:
    """仓库里已有同地址远端就复用它，避免同一个仓库配出两份远端。"""
    target = _normalize_url(url)
    for name, existing in urls.items():
        if _normalize_url(existing) == target:
            return name
    return ""


def _choose_remote(
    requested: str | None,
    sources: dict[str, str],
    names: list[str],
    preferred: str = "",
) -> str:
    """把「源名 / 远端名 / 空」收敛成一个可用名字。

    显式传了名字就必须命中源或现有远端；没传时按 preferred → 第一个源 → 唯一远端兜底。
    """
    candidate = (requested or "").strip()
    if candidate:
        if not REMOTE_NAME_PATTERN.match(candidate) or (candidate not in sources and candidate not in names):
            raise ValidationFailed(f"未知的 git 远端：{candidate}")
        return candidate
    preferred = preferred.strip()
    if preferred and (preferred in sources or preferred in names):
        return preferred
    if sources:
        return next(iter(sources))
    if names:
        return names[0]
    raise ServiceUnavailable("当前仓库未配置 git 远端，无法检查更新", message_key=None)


async def _remote_urls() -> dict[str, str]:
    """远端名 → fetch 地址。"""
    result = await _git_async("remote", "-v")
    if result.returncode != 0:
        raise _git_failed("remote", result)
    return dict(_parse_remote_lines(_stdout(result)))


def _sanitize_url(url: str) -> str:
    """去掉 userinfo / 查询串 / 片段：远端 URL 里可能嵌了 token，不能回传前端。"""
    value = url.strip()
    parts = urlsplit(value)
    if not parts.netloc:
        return value
    try:
        port = parts.port
    except ValueError:
        port = None
    netloc = parts.hostname or ""
    if port is not None:
        netloc = f"{netloc}:{port}"
    return urlunsplit((parts.scheme, netloc, parts.path, "", ""))


def _parse_remote_lines(output: str) -> list[tuple[str, str]]:
    """解析 ``git remote -v``：同名远端有 fetch/push 两行，只保留第一行。"""
    rows: list[tuple[str, str]] = []
    seen: set[str] = set()
    for line in output.splitlines():
        name, _, rest = line.partition("\t")
        name = name.strip()
        if not name or name in seen:
            continue
        seen.add(name)
        rows.append((name, rest.replace("(fetch)", "").replace("(push)", "").strip()))
    return rows


async def remotes() -> list[UpgradeRemoteOut]:
    """页面下拉的选项：内置源在前，仓库里其它远端在后。"""
    urls = await _remote_urls()
    sources = _parse_sources(get_settings().upgrade_sources)
    entries: list[tuple[str, str]] = []
    used: set[str] = set()
    for name, url in sources:
        local = _find_remote_by_url(url, urls) or (name if name in urls else "")
        if local:
            used.add(local)
        # 同名远端已存在时展示它的实际地址：页面与「点下去会 fetch 谁」保持一致。
        entries.append((name, urls.get(local) or url))
    for name, url in urls.items():
        if name not in used:
            entries.append((name, url))
    default = _choose_remote(None, dict(sources), list(urls), get_settings().upgrade_remote)
    return [
        UpgradeRemoteOut(name=name, url=_sanitize_url(url), is_default=name == default)
        for name, url in entries
    ]


async def _resolve_remote(requested: str | None) -> str:
    """收敛到「源的本地远端」，返回可直接 fetch / pull 的远端名。

    内置源按地址复用仓库已有远端；仓库里没有时才 ``git remote add``。名字与地址
    都来自服务端配置，前端只传名字，因此不会被用来指向任意 ref 来源。
    """
    urls = await _remote_urls()
    sources = dict(_parse_sources(get_settings().upgrade_sources))
    name = _choose_remote(requested, sources, list(urls), get_settings().upgrade_remote)
    url = sources.get(name, "")
    if not url:
        return name
    existing = _find_remote_by_url(url, urls)
    if existing:
        return existing
    if name in urls:
        return name
    added = await _git_async("remote", "add", name, url)
    if added.returncode != 0:
        raise _git_failed("remote add", added)
    logger.info("upgrade: added git remote %s for %s", name, _sanitize_url(url))
    return name


async def _tracked_ref() -> str | None:
    """分支已设置的上游（形如 cccms/main），未设置时返回 None。"""
    tracked = await _git_async("rev-parse", "--abbrev-ref", "@{u}")
    if tracked.returncode == 0 and _stdout(tracked):
        return _stdout(tracked)
    return None


async def _upstream_ref(remote: str, *, explicit: bool = False) -> str:
    """用于比对的上游引用。

    显式选了远端就必须按 ``<remote>/<branch>``：分支可能跟踪着另一个远端（如 origin），
    此时沿用跟踪分支会「检查 origin、升级 gitee」，两边结论对不上。
    """
    if not explicit:
        tracked = await _tracked_ref()
        if tracked:
            return tracked
    return f"{remote}/{await _branch()}"


async def _require_ref(ref: str) -> None:
    verified = await _git_async("rev-parse", "--verify", "--quiet", f"{ref}^{{commit}}")
    if verified.returncode != 0:
        raise ValidationFailed(f"远端引用 {ref} 不存在，无法检查更新")


def _git_failed(action: str, result: subprocess.CompletedProcess[str]) -> ServiceUnavailable:
    """git 失败：详情进日志，同时原样带回给调用方（统一的「服务暂不可用」没法排障）。"""
    detail = (result.stderr or result.stdout).strip()[:500] or "未知错误"
    logger.warning("upgrade: git %s failed: %s", action, detail)
    return ServiceUnavailable(f"git {action} 失败：{detail}", message_key=None)


def _commit_time(value: str) -> datetime | None:
    try:
        return datetime.fromisoformat(value.strip())
    except ValueError:
        return None


async def status() -> UpgradeStatusOut:
    settings = get_settings()
    reason = _disabled_reason()
    out = UpgradeStatusOut(
        version=__version__,
        enabled=not reason,
        reason=reason,
        branch=settings.upgrade_branch,
        can_run=not reason,
    )
    if not (PROJECT_DIR / ".git").exists():
        return out
    out.branch = _stdout(await _git_async("rev-parse", "--abbrev-ref", "HEAD")) or settings.upgrade_branch
    out.commit = _stdout(await _git_async("rev-parse", "--short", "HEAD"))
    out.commit_time = _commit_time(_stdout(await _git_async("log", "-1", "--format=%cI")))
    out.latest_tag = _stdout(await _git_async("describe", "--tags", "--abbrev=0"))
    out.dirty = bool(_stdout(await _git_async("status", "--porcelain")))
    # 下拉与默认上游都只是展示：配置写错或 git 读不到时降级，不把状态页打成 503。
    ref: str | None = None
    try:
        out.remotes = await remotes()
        # 未设置跟踪分支时按 <remote>/<branch> 兜底，避免「有远端却始终显示 0/0」
        ref = await _tracked_ref()
        if ref is None and (urls := await _remote_urls()):
            sources = dict(_parse_sources(settings.upgrade_sources))
            default = _choose_remote(None, sources, list(urls), settings.upgrade_remote)
            local = _find_remote_by_url(sources.get(default, ""), urls) or default
            candidate = f"{local}/{out.branch}"
            if (await _git_async("rev-parse", "--verify", "--quiet", f"{candidate}^{{commit}}")).returncode == 0:
                ref = candidate
    except ServiceUnavailable as exc:
        logger.warning("upgrade: resolve remotes failed: %s", exc)
    if ref:
        out.upstream = ref
        behind = await _git_async("rev-list", "--count", f"HEAD..{ref}")
        ahead = await _git_async("rev-list", "--count", f"{ref}..HEAD")
        out.behind = int(_stdout(behind) or 0) if behind.returncode == 0 else 0
        out.ahead = int(_stdout(ahead) or 0) if ahead.returncode == 0 else 0
    out.can_run = out.enabled and not out.dirty
    if out.enabled and out.dirty:
        out.reason = "工作区存在未提交改动，请先提交或暂存"
    return out


async def check(remote: str | None = None) -> UpgradeCheckOut:
    _ensure_enabled()
    await _branch()
    remote_name = await _resolve_remote(remote)
    fetched = await _git_async("fetch", "--tags", "--prune", remote_name)
    if fetched.returncode != 0:
        raise _git_failed("fetch", fetched)
    upstream = await _upstream_ref(remote_name, explicit=bool((remote or "").strip()))
    await _require_ref(upstream)
    behind = int(_stdout(await _git_async("rev-list", "--count", f"HEAD..{upstream}")) or 0)
    ahead = int(_stdout(await _git_async("rev-list", "--count", f"{upstream}..HEAD")) or 0)
    log = await _git_async("log", "--oneline", "--no-decorate", "-n", "20", f"HEAD..{upstream}")
    commits = [line for line in _stdout(log).splitlines() if line.strip()]
    tag = _stdout(await _git_async("describe", "--tags", "--abbrev=0", upstream))
    return UpgradeCheckOut(
        behind=behind,
        ahead=ahead,
        latest_tag=tag or "",
        upstream=upstream,
        commits=commits,
    )


async def tags() -> list[UpgradeTagOut]:
    result = await _git_async(
        "for-each-ref",
        "refs/tags",
        "--sort=-creatordate",
        "--format=%(refname:short)|%(subject)|%(creatordate:iso8601)",
        "--count=50",
    )
    if result.returncode != 0:
        return []
    rows: list[UpgradeTagOut] = []
    for line in _stdout(result).splitlines():
        name, _, rest = line.partition("|")
        subject, _, created = rest.partition("|")
        rows.append(UpgradeTagOut(name=name, subject=subject, date=_commit_time(created)))
    return rows


async def init(remote: str | None = None) -> UpgradeResultOut:
    _ensure_enabled()
    await _branch()
    result = await _git_async("fetch", "--tags", "--prune", await _resolve_remote(remote))
    if result.returncode != 0:
        raise _git_failed("fetch", result)
    return UpgradeResultOut(
        output=(_stdout(result) or "already up to date")[:8000],
        changed=False,
        restart_required=False,
    )


async def run(remote: str | None = None) -> UpgradeResultOut:
    _ensure_enabled()
    settings = get_settings()
    branch = await _branch()
    if branch != settings.upgrade_branch:
        raise Forbidden(f"当前分支 {branch} 与允许升级的分支 {settings.upgrade_branch} 不一致")
    if not BRANCH_PATTERN.match(branch):
        raise ValidationFailed("非法分支名")
    if _stdout(await _git_async("status", "--porcelain")):
        raise Forbidden("工作区存在未提交改动，无法在线升级")

    remote_name = await _resolve_remote(remote)
    before = _stdout(await _git_async("rev-parse", "HEAD"))
    result = await _git_async("pull", "--ff-only", remote_name, branch)
    output = "\n".join(part for part in (result.stdout.strip(), result.stderr.strip()) if part)
    if result.returncode != 0:
        raise _git_failed("pull", result)
    after = _stdout(await _git_async("rev-parse", "HEAD"))
    return UpgradeResultOut(
        output=(output or "already up to date")[:8000],
        changed=before != after,
        restart_required=before != after,
    )
