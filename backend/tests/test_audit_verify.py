"""审计链校验（A-02）：结果压缩与平台边界。

不连库：``verify_chain`` 的真实扫描由 CLI / 集成环境覆盖，这里只锁两件容易回归的事——
命中 id 必须截断成样本，以及非平台超管不得触发全局扫描。
"""

from __future__ import annotations

import pytest

from cccms.core.context import RequestContext, TenantContext, UserContext, reset_context, set_context
from cccms.core.errors import Forbidden
from cccms.services import log as log_service


def _actor(*, super_admin: bool = True) -> UserContext:
    return UserContext(
        id=1,
        username="admin",
        nickname="admin",
        tenant_id=1,
        home_tenant_id=1,
        is_super_admin=super_admin,
        permissions=frozenset({"cccms:log:audit"}),
        role_ids=(1,),
        effective_role_ids=(1,),
        dept_ids=(),
        dept_subtree_ids=(),
        post_ids=(),
        data_scope=1,
        jti="jti",
        token_exp=0,
        token_iat=0,
    )


def _context(*, platform: bool) -> RequestContext:
    ctx = RequestContext(trace_id="t", action_id="a", session_id="s", method="POST", path="/x", ip="1.1.1.1", ua="")
    ctx.tenant = TenantContext(
        id=1, code="platform" if platform else "acme", name="t", is_platform=platform, domain=None
    )
    return ctx


def test_summarize_truncates_ids_but_keeps_counts() -> None:
    raw = {
        "ok": False,
        "total": 1000,
        "unchained": 3,
        "broken": list(range(1, 31)),
        "modified": [7, 8],
        "tail_stale": True,
        "seal_ok": False,
    }

    summary = log_service.summarize_audit(raw, duration_ms=42)

    assert summary["ok"] is False
    assert (summary["total"], summary["unchained"]) == (1000, 3)
    assert (summary["broken_count"], summary["modified_count"]) == (30, 2)
    assert summary["broken_ids"] == list(range(1, 21))
    assert summary["modified_ids"] == [7, 8]
    assert (summary["tail_stale"], summary["seal_ok"], summary["duration_ms"]) == (True, False, 42)


def test_summarize_handles_healthy_chain() -> None:
    summary = log_service.summarize_audit(
        {"ok": True, "total": 10, "unchained": 10, "broken": [], "modified": [], "tail_stale": False, "seal_ok": True},
        duration_ms=1,
    )

    assert summary["ok"] is True
    assert summary["broken_ids"] == []
    assert summary["modified_ids"] == []


async def test_non_platform_admin_cannot_verify() -> None:
    token = set_context(_context(platform=False))
    try:
        with pytest.raises(Forbidden):
            await log_service.verify_audit(_actor())
    finally:
        reset_context(token)


async def test_plain_admin_in_platform_tenant_cannot_verify() -> None:
    token = set_context(_context(platform=True))
    try:
        with pytest.raises(Forbidden):
            await log_service.verify_audit(_actor(super_admin=False))
    finally:
        reset_context(token)
