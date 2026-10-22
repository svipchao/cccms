"""Online upgrade for local development.

Git is invoked through a fixed argv (never a shell) behind three independent
gates: ``CCCMS_UPGRADE_ENABLE``, ``dev`` only, and a repository self-check
(``.git`` + ``frontend/package.json``).  The branch name is validated, so the
endpoint cannot be turned into an arbitrary command runner.  All subprocess
calls run in a worker thread so the event loop is never blocked.
"""

from __future__ import annotations

import asyncio
import os
import re
import shutil
import subprocess
from datetime import datetime

from cccms.config import PROJECT_DIR, get_settings
from cccms.core.context import current_context
from cccms.core.errors import Forbidden, ServiceUnavailable, ValidationFailed
from cccms.schemas.upgrade import (
    UpgradeCheckOut,
    UpgradeResultOut,
    UpgradeStatusOut,
    UpgradeTagOut,
)

BRANCH_PATTERN = re.compile(r"^[A-Za-z0-9._/-]{1,120}$")


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


def _commit_time(value: str) -> datetime | None:
    try:
        return datetime.fromisoformat(value.strip())
    except ValueError:
        return None


async def status() -> UpgradeStatusOut:
    settings = get_settings()
    reason = _disabled_reason()
    out = UpgradeStatusOut(
        version="0.1.0",
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
    upstream = await _git_async("rev-parse", "--abbrev-ref", "@{u}")
    if upstream.returncode == 0:
        behind = await _git_async("rev-list", "--count", "HEAD..@{u}")
        ahead = await _git_async("rev-list", "--count", "@{u}..HEAD")
        out.behind = int(_stdout(behind) or 0) if behind.returncode == 0 else 0
        out.ahead = int(_stdout(ahead) or 0) if ahead.returncode == 0 else 0
    out.can_run = out.enabled and not out.dirty
    if out.enabled and out.dirty:
        out.reason = "工作区存在未提交改动，请先提交或暂存"
    return out


async def check() -> UpgradeCheckOut:
    _ensure_enabled()
    await _branch()
    fetched = await _git_async("fetch", "--tags", "--prune", "origin")
    if fetched.returncode != 0:
        raise ServiceUnavailable(f"git fetch 失败：{fetched.stderr.strip()[:500]}")
    upstream = await _git_async("rev-parse", "--abbrev-ref", "@{u}")
    if upstream.returncode != 0:
        raise ValidationFailed("当前分支未设置上游，无法检查更新")
    behind = int(_stdout(await _git_async("rev-list", "--count", "HEAD..@{u}")) or 0)
    ahead = int(_stdout(await _git_async("rev-list", "--count", "@{u}..HEAD")) or 0)
    log = await _git_async("log", "--oneline", "--no-decorate", "-n", "20", "HEAD..@{u}")
    commits = [line for line in _stdout(log).splitlines() if line.strip()]
    tag = _stdout(await _git_async("describe", "--tags", "--abbrev=0", "@{u}"))
    return UpgradeCheckOut(
        behind=behind,
        ahead=ahead,
        latest_tag=tag or "",
        upstream=_stdout(upstream),
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


async def init() -> UpgradeResultOut:
    _ensure_enabled()
    await _branch()
    result = await _git_async("fetch", "--tags", "--prune", "origin")
    if result.returncode != 0:
        raise ServiceUnavailable(f"git fetch 失败：{result.stderr.strip()[:500]}")
    return UpgradeResultOut(
        output=(_stdout(result) or "already up to date")[:8000],
        changed=False,
        restart_required=False,
    )


async def run() -> UpgradeResultOut:
    _ensure_enabled()
    settings = get_settings()
    branch = await _branch()
    if branch != settings.upgrade_branch:
        raise Forbidden(f"当前分支 {branch} 与允许升级的分支 {settings.upgrade_branch} 不一致")
    if not BRANCH_PATTERN.match(branch):
        raise ValidationFailed("非法分支名")
    if _stdout(await _git_async("status", "--porcelain")):
        raise Forbidden("工作区存在未提交改动，无法在线升级")

    before = _stdout(await _git_async("rev-parse", "HEAD"))
    result = await _git_async("pull", "--ff-only", "origin", branch)
    output = "\n".join(part for part in (result.stdout.strip(), result.stderr.strip()) if part)
    if result.returncode != 0:
        raise ServiceUnavailable(f"git pull 失败：{output[:800]}")
    after = _stdout(await _git_async("rev-parse", "HEAD"))
    return UpgradeResultOut(
        output=(output or "already up to date")[:8000],
        changed=before != after,
        restart_required=before != after,
    )
