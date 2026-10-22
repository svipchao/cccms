"""服务层排序接线：``sort``/``order`` 必须真的进到 ORDER BY。

这是 P1-1 的回归点——参数被 ``StrictModel``/函数签名吞掉时不会报错，
只会"箭头翻转、数据不动"。用 stub session 捕获 SQL，不依赖 MySQL。
"""

from __future__ import annotations

from dataclasses import replace
from typing import Any

import pytest

from cccms.core.context import (
    RequestContext,
    TenantContext,
    UserContext,
    reset_context,
    set_context,
)
from cccms.schemas.crontab import CrontabQuery
from cccms.schemas.file import FileQuery
from cccms.schemas.log import LogQuery
from cccms.services import crontab as crontab_service
from cccms.services import file as file_service
from cccms.services import log as log_service
from cccms.services import notice as notice_service
from cccms.services import post as post_service
from cccms.services import tenant as tenant_service


class _EmptyResult:
    def all(self) -> list[Any]:
        return []


class _StubSession:
    """只捕获语句、永远返回空结果集。"""

    def __init__(self) -> None:
        self.statements: list[Any] = []

    async def scalar(self, statement: Any) -> int:
        self.statements.append(statement)
        return 0

    async def scalars(self, statement: Any) -> _EmptyResult:
        self.statements.append(statement)
        return _EmptyResult()

    async def execute(self, statement: Any) -> _EmptyResult:
        self.statements.append(statement)
        return _EmptyResult()


def _actor() -> UserContext:
    return UserContext(
        id=1,
        username="tester",
        nickname="Tester",
        tenant_id=1,
        home_tenant_id=1,
        is_super_admin=False,
        permissions=frozenset({"cccms:notice:index"}),
        role_ids=(1,),
        effective_role_ids=(1,),
        dept_ids=(1,),
        dept_subtree_ids=(1,),
        post_ids=(),
        data_scope=1,
        jti="test",
        token_exp=0,
        token_iat=0,
    )


def _order_by(session: _StubSession) -> str:
    # 第 1 条是 count 查询（无排序），取最后一条取行查询。
    return str(session.statements[-1]).rsplit("ORDER BY", maxsplit=1)[-1].split("LIMIT")[0].strip()


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("cost", "desc", "sys_log.cost DESC, sys_log.id ASC"),
        ("create_time", "asc", "sys_log.create_time ASC, sys_log.id ASC"),
        (None, "asc", "sys_log.id DESC"),
    ],
)
async def test_log_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    session = _StubSession()
    await log_service.list_logs(session, _actor(), LogQuery(sort=sort, order=order))
    assert _order_by(session) == expected


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("size", "desc", "sys_file.size DESC, sys_file.id ASC"),
        (None, "asc", "sys_file.id DESC"),
    ],
)
async def test_file_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    session = _StubSession()
    await file_service.list_files(session, _actor(), FileQuery(sort=sort, order=order))
    assert _order_by(session) == expected


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("read_count", "desc", "sys_notice.read_count DESC, sys_notice.id ASC"),
        ("publish_at", "asc", "sys_notice.publish_at ASC, sys_notice.id ASC"),
        # 无排序或非白名单列：保留"置顶优先"的原顺序，且不把非法列拼进 SQL。
        (None, "asc", "sys_notice.pinned DESC, sys_notice.id DESC"),
        ("title", "desc", "sys_notice.pinned DESC, sys_notice.id DESC"),
    ],
)
async def test_notice_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    session = _StubSession()
    await notice_service.list_notices(session, _actor(), sort=sort, order=order)
    assert _order_by(session) == expected


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("sort", "desc", "sys_post.sort DESC, sys_post.id ASC"),
        (None, "asc", "sys_post.sort ASC, sys_post.id ASC"),
    ],
)
async def test_post_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    session = _StubSession()
    await post_service.list_posts(session, _actor(), sort=sort, order=order)
    assert _order_by(session) == expected


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("expire_at", "desc", "sys_tenant.expire_at DESC, sys_tenant.id ASC"),
        (None, "asc", "sys_tenant.id ASC"),
    ],
)
async def test_tenant_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    # 租户管理要求"超管 + 当前生效租户是平台租户"，没有请求上下文会直接 Forbidden。
    actor = replace(_actor(), is_super_admin=True)
    ctx = RequestContext(
        trace_id="t1",
        action_id="a1",
        session_id="s1",
        method="GET",
        path="/api/tenant",
        ip="127.0.0.1",
        ua="pytest",
        tenant=TenantContext(id=1, code="platform", name="平台", is_platform=True, domain=None),
    )
    token = set_context(ctx)
    try:
        session = _StubSession()
        await tenant_service.list_tenants(session, actor, sort=sort, order=order)
        assert _order_by(session) == expected
    finally:
        reset_context(token)


@pytest.mark.parametrize(
    ("sort", "order", "expected"),
    [
        ("last_run_time", "desc", "sys_crontab.last_run_time DESC, sys_crontab.id ASC"),
        (None, "asc", "sys_crontab.id ASC"),
    ],
)
async def test_crontab_list_applies_server_side_sort(sort: str | None, order: str, expected: str) -> None:
    session = _StubSession()
    await crontab_service.list_crontab(session, _actor(), CrontabQuery(sort=sort, order=order or "asc"))
    assert _order_by(session) == expected
