"""在线升级的同步源：内置源、配置解析、URL 匹配与名字收敛。

只覆盖纯函数与一层被 monkeypatch 掉的 ``_remote_urls`` / ``_git_async``，不触库也不调 git。
"""

from __future__ import annotations

import subprocess

import pytest

from cccms.core.errors import ServiceUnavailable, ValidationFailed
from cccms.services import upgrade as upgrade_service


def test_parse_remote_lines_keeps_first_line_per_remote() -> None:
    output = (
        "cccms\thttps://github.com/poetryiii/cccms.git (fetch)\n"
        "cccms\thttps://github.com/poetryiii/cccms.git (push)\n"
        "gitee\thttps://gitee.com/poetryiii/cccms.git (fetch)\n"
        "gitee\thttps://gitee.com/poetryiii/cccms.git (push)\n"
    )
    assert upgrade_service._parse_remote_lines(output) == [
        ("cccms", "https://github.com/poetryiii/cccms.git"),
        ("gitee", "https://gitee.com/poetryiii/cccms.git"),
    ]


def test_parse_remote_lines_skips_blank_lines() -> None:
    assert upgrade_service._parse_remote_lines("\n  \norigin\t/u/git/repo.git (fetch)\n") == [
        ("origin", "/u/git/repo.git")
    ]


def test_sanitize_url_drops_credentials_and_query() -> None:
    # 远端 URL 里嵌 token 很常见，回传前端前必须摘掉 userinfo 与查询串。
    assert (
        upgrade_service._sanitize_url("https://user:token@github.com/a/b.git?token=x#frag")
        == "https://github.com/a/b.git"
    )
    assert (
        upgrade_service._sanitize_url("https://github.com:8443/a/b.git") == "https://github.com:8443/a/b.git"
    )
    # scp 形式（git@host:path）没有 netloc，原样保留：其中的用户名不是秘密。
    assert upgrade_service._sanitize_url("git@github.com:a/b.git") == "git@github.com:a/b.git"


def test_parse_sources_defaults_to_builtin() -> None:
    sources = upgrade_service._parse_sources("")
    assert [name for name, _ in sources] == ["github", "gitee"]
    assert sources == list(upgrade_service.BUILTIN_SOURCES)


def test_parse_sources_replaces_builtin_when_configured() -> None:
    assert upgrade_service._parse_sources("lab=https://git.example.com/a/b,mirror=https://m.example.com/a/b") == [
        ("lab", "https://git.example.com/a/b"),
        ("mirror", "https://m.example.com/a/b"),
    ]


@pytest.mark.parametrize("raw", ["github", "github=", "=https://x/y", "bad name=https://x/y"])
def test_parse_sources_rejects_bad_format(raw: str) -> None:
    with pytest.raises(ServiceUnavailable):
        upgrade_service._parse_sources(raw)


def test_normalize_url_ignores_git_suffix_case_and_trailing_slash() -> None:
    assert (
        upgrade_service._normalize_url("https://GitHub.com/poetryiii/cccms.git/")
        == "https://github.com/poetryiii/cccms"
    )


def test_find_remote_by_url_matches_normalized_url() -> None:
    urls = {"cccms": "https://github.com/poetryiii/cccms.git"}
    assert upgrade_service._find_remote_by_url("https://github.com/poetryiii/cccms", urls) == "cccms"
    assert upgrade_service._find_remote_by_url("https://gitee.com/poetryiii/cccms", urls) == ""


def test_choose_remote_prefers_requested_then_configured_then_first_source() -> None:
    sources = {"github": "u1", "gitee": "u2"}
    assert upgrade_service._choose_remote("gitee", sources, []) == "gitee"
    assert upgrade_service._choose_remote(None, sources, ["cccms"], "cccms") == "cccms"
    assert upgrade_service._choose_remote(None, sources, ["cccms"], "") == "github"
    assert upgrade_service._choose_remote(None, {}, ["cccms"], "") == "cccms"


@pytest.mark.parametrize("candidate", ["origin", "--upload-pack=calc", "../etc", "gitee;rm -rf /"])
def test_choose_remote_rejects_names_outside_source_list(candidate: str) -> None:
    with pytest.raises(ValidationFailed):
        upgrade_service._choose_remote(candidate, {"github": "u1", "gitee": "u2"}, ["cccms"])


async def test_resolve_remote_reuses_remote_with_same_url(monkeypatch: pytest.MonkeyPatch) -> None:
    """已克隆的仓库里远端叫 cccms，选 github 时不该再多配一个远端。"""

    async def fake_urls() -> dict[str, str]:
        return {"cccms": "https://github.com/poetryiii/cccms.git"}

    monkeypatch.setattr(upgrade_service, "_remote_urls", fake_urls)
    assert await upgrade_service._resolve_remote("github") == "cccms"


async def test_resolve_remote_adds_missing_source(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[tuple[str, ...]] = []

    async def fake_urls() -> dict[str, str]:
        return {"cccms": "https://github.com/poetryiii/cccms.git"}

    async def fake_git(*args: str, timeout_s: int | None = None) -> subprocess.CompletedProcess[str]:
        calls.append(args)
        return subprocess.CompletedProcess(args, 0, "", "")

    monkeypatch.setattr(upgrade_service, "_remote_urls", fake_urls)
    monkeypatch.setattr(upgrade_service, "_git_async", fake_git)
    assert await upgrade_service._resolve_remote("gitee") == "gitee"
    assert calls == [("remote", "add", "gitee", "https://gitee.com/poetryiii/cccms")]


async def test_remotes_lists_builtin_sources_then_extra_remotes(monkeypatch: pytest.MonkeyPatch) -> None:
    async def fake_urls() -> dict[str, str]:
        return {
            "cccms": "https://github.com/poetryiii/cccms.git",
            "origin": "https://example.com/fork.git",
        }

    monkeypatch.setattr(upgrade_service, "_remote_urls", fake_urls)
    rows = await upgrade_service.remotes()
    assert [(row.name, row.is_default) for row in rows] == [
        ("github", True),
        ("gitee", False),
        ("origin", False),
    ]
