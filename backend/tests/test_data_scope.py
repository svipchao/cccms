"""数据权限的核心语义（E-01）。

这层是"越权"与"查不到数据"的唯一分界线，但它只在运行时由一堆 if 决定，
接口测试很难覆盖全（要连库、造多部门数据）。这里用**纯逻辑**锁住判定表：

* 哪几个模型注册了 `__data_scope__`（新增模型必须显式登记，否则列表不受范围约束）；
* 数据范围 1/2/3/4/5 分别生成什么子句；
* 自定义规则配置损坏时是 fail-closed 还是 fail-open；
* `without_scope` 必须给理由。
"""

from __future__ import annotations

from typing import Any

import pytest
from sqlalchemy import false, select

from cccms.core.context import (
    RequestContext,
    TenantContext,
    UserContext,
    reset_context,
    set_context,
)
from cccms.core.errors import Forbidden
from cccms.core.scope import (
    ScopePlan,
    apply_scope,
    assert_scope_reason,
    field_actions,
    redact_field_value,
    scope_clause,
    without_scope,
)
from cccms.models.crontab import Crontab
from cccms.models.file import FileRecord
from cccms.models.log import OperationLog
from cccms.models.rule import DataRule
from cccms.models.user import User


def _user(**overrides: Any) -> UserContext:
    values: dict[str, Any] = {
        "id": 5,
        "username": "alice",
        "nickname": "Alice",
        "tenant_id": 1,
        "home_tenant_id": 1,
        "is_super_admin": False,
        "permissions": frozenset(),
        "role_ids": (2,),
        "effective_role_ids": (2,),
        "dept_ids": (10,),
        "dept_subtree_ids": (10, 11),
        "post_ids": (7,),
        "data_scope": 4,
        "jti": "t",
        "token_exp": 0,
        "token_iat": 0,
    }
    values.update(overrides)
    return UserContext(**values)


def _plan(*, data_scope: int | None = None, user: UserContext | None = None, **overrides: Any) -> ScopePlan:
    person = user or _user()
    values: dict[str, Any] = {
        "user": person,
        "data_scope": person.data_scope if data_scope is None else data_scope,
        "dept_ids": person.dept_ids,
        "dept_subtree_ids": person.dept_subtree_ids,
        "post_ids": person.post_ids,
        "effective_role_ids": person.effective_role_ids,
        "row_rules": [],
        "field_rules": [],
        "scope_tables": set(),
    }
    values.update(overrides)
    return ScopePlan(**values)


def _sql(clause: Any) -> str:
    return str(clause.compile(compile_kwargs={"literal_binds": True}))


def _false_sql() -> str:
    return _sql(false())


def _rule(**overrides: Any) -> DataRule:
    values: dict[str, Any] = {
        "tenant_id": 1,
        "table_name": "",
        "field": "",
        "operator": "=",
        "value": "",
        "value_type": "static",
        "action": "row",
        "bind_mode": "or",
    }
    values.update(overrides)
    return DataRule(**values)


def _ctx(*, tenant_id: int | None = 1, plan: ScopePlan | None = None) -> Any:
    ctx = RequestContext(
        trace_id="t",
        action_id="a",
        session_id="s",
        method="GET",
        path="/api/x",
        ip="127.0.0.1",
        ua="pytest",
        tenant=None
        if tenant_id is None
        else TenantContext(id=tenant_id, code="platform", name="平台", is_platform=True, domain=None),
        scope_plan=plan,
    )
    return set_context(ctx)


# --- 注册面 ---------------------------------------------------------------


def test_expected_models_are_scoped() -> None:
    """注册写在各 service 模块里，app 启动（api → service）后必须齐全。

    没注册的模型在 `scope_clause()` 里直接返回 None —— 也就是**列表完全不受数据范围约束**，
    所以新增模型时忘了登记，表现是"看不到任何异常，但能看到全表"。
    """
    import cccms.main  # noqa: F401, PLC0415 - 触发 api → services 的注册副作用

    scoped = {
        mapper.class_.__name__
        for mapper in User.registry.mappers
        if getattr(mapper.class_, "__data_scope__", None) is not None
    }
    assert scoped == {"User", "Dept", "OperationLog", "FileRecord", "Crontab"}


# --- 数据范围 1~5 ---------------------------------------------------------


def test_super_admin_and_scope_all_bypass() -> None:
    assert scope_clause(User, _plan(user=_user(is_super_admin=True))) is None
    assert scope_clause(User, _plan(data_scope=1)) is None


def test_self_scope_filters_by_owner_column() -> None:
    clause = scope_clause(OperationLog, _plan(data_scope=4))
    assert clause is not None
    assert "sys_log.user_id = 5" in _sql(clause)


def test_dept_scope_uses_direct_depts() -> None:
    clause = scope_clause(FileRecord, _plan(data_scope=3))
    assert clause is not None
    text = _sql(clause)
    # 部门范围查的是"创建人属于这些部门"，不是附件自己的 dept 列。
    assert "sys_file.create_by IN" in text
    assert "sys_user_dept.dept_id IN (10)" in text


def test_dept_subtree_scope_uses_subtree_ids() -> None:
    clause = scope_clause(FileRecord, _plan(data_scope=2))
    assert clause is not None
    assert "sys_user_dept.dept_id IN (10, 11)" in _sql(clause)


def test_custom_scope_without_rules_denies_everything() -> None:
    """范围 5 = 只认自定义规则；一条都没配上时必须**全拒**，不能退化成"看全表"。"""
    clause = scope_clause(Crontab, _plan(data_scope=5))
    assert clause is not None
    assert _sql(clause) == _false_sql()


def test_custom_scope_with_matching_rule() -> None:
    plan = _plan(
        data_scope=5,
        row_rules=[_rule(table_name="sys_crontab", field="status", operator="=", value="1")],
        scope_tables={"sys_crontab"},
    )
    clause = scope_clause(Crontab, plan)
    assert clause is not None
    assert "sys_crontab.status = 1" in _sql(clause)


def test_rule_for_other_table_is_not_applied() -> None:
    # 规则写的是 sys_log，却拿去约束 sys_crontab：不能命中，于是范围 5 全拒。
    plan = _plan(
        data_scope=5,
        row_rules=[_rule(table_name="sys_log", field="status", operator="=", value="1")],
        scope_tables={"sys_log"},
    )
    clause = scope_clause(Crontab, plan)
    assert clause is not None
    assert _sql(clause) == _false_sql()


def test_rule_bound_to_other_user_does_not_match() -> None:
    plan = _plan(
        data_scope=5,
        row_rules=[_rule(table_name="sys_crontab", field="status", value="1", user_id=999)],
        scope_tables={"sys_crontab"},
    )
    clause = scope_clause(Crontab, plan)
    assert clause is not None
    assert _sql(clause) == _false_sql()


# --- fail-closed ----------------------------------------------------------


@pytest.mark.parametrize(
    ("field", "operator"),
    [
        ("1=1 OR 1", "="),  # 字段名不是标识符
        ("not_a_column", "="),  # 模型上没有这一列
        ("status", "regexp"),  # 操作符不在白名单
    ],
)
def test_broken_rule_fails_closed(field: str, operator: str) -> None:
    plan = _plan(
        data_scope=5,
        row_rules=[_rule(table_name="sys_crontab", field=field, operator=operator, value="1")],
        scope_tables={"sys_crontab"},
    )
    clause = scope_clause(Crontab, plan)
    assert clause is not None
    # 配置损坏宁可查不到，也不能静默放行整表。
    assert _sql(clause) == _false_sql()


def test_empty_rule_value_fails_closed() -> None:
    plan = _plan(
        data_scope=5,
        row_rules=[_rule(table_name="sys_crontab", field="status", operator="in", value="")],
        scope_tables={"sys_crontab"},
    )
    clause = scope_clause(Crontab, plan)
    assert clause is not None
    assert _sql(clause) == _false_sql()


def test_dynamic_value_expands_owner_id() -> None:
    plan = _plan(
        data_scope=5,
        row_rules=[
            _rule(
                table_name="sys_user",
                field="id",
                operator="in",
                value="{user.id}",
                value_type="dynamic",
            )
        ],
        scope_tables={"sys_user"},
    )
    clause = scope_clause(User, plan)
    assert clause is not None
    assert "sys_user.id IN (5)" in _sql(clause)


# --- 逃逸口 ---------------------------------------------------------------


def test_without_scope_needs_a_reason() -> None:
    with pytest.raises(ValueError), without_scope("  "):
        pytest.fail("空理由不应该放行")


def test_without_scope_bypasses_clause() -> None:
    plan = _plan(data_scope=4)
    assert scope_clause(OperationLog, plan) is not None
    with without_scope("定时任务无用户上下文"):
        assert scope_clause(OperationLog, plan) is None


def test_assert_scope_reason_rejects_blank() -> None:
    assert_scope_reason("导出快照")
    with pytest.raises(Forbidden):
        assert_scope_reason(" ")


# --- apply_scope ----------------------------------------------------------


def test_apply_scope_adds_tenant_and_soft_delete() -> None:
    token = _ctx(plan=_plan(data_scope=4))
    try:
        statement = apply_scope(select(FileRecord), FileRecord)
    finally:
        reset_context(token)

    text = _sql(statement)
    assert "sys_file.tenant_id = 1" in text
    # 软删过滤只对带 delete_time 的模型生效；回收站功能依赖它。
    assert "sys_file.delete_time IS NULL" in text
    assert "sys_file.create_by = 5" in text


def test_apply_scope_skips_soft_delete_for_append_only_tables() -> None:
    """`sys_log` 没有 delete_time（只追加），不该被拼上 `delete_time IS NULL`。"""
    token = _ctx(plan=_plan(data_scope=1))
    try:
        text = _sql(apply_scope(select(OperationLog), OperationLog))
    finally:
        reset_context(token)
    assert "delete_time" not in text.split("FROM sys_log", 1)[1]


def test_apply_scope_without_tenant_context_keeps_no_tenant_filter() -> None:
    """没有租户上下文时只加软删条件：真正的 fail-closed 在中间件（域名解析失败直接 503）。"""
    token = _ctx(tenant_id=None, plan=_plan(data_scope=1))
    try:
        text = _sql(apply_scope(select(FileRecord), FileRecord))
    finally:
        reset_context(token)
    where = text.split("WHERE", 1)[1] if "WHERE" in text else ""
    assert "tenant_id" not in where
    assert "delete_time IS NULL" in where


# --- 字段级规则 -----------------------------------------------------------


def test_field_rule_masks_phone_on_read() -> None:
    plan = _plan(
        field_rules=[_rule(table_name="sys_user", field="phone", action="mask")],
        scope_tables={"sys_user"},
    )
    assert field_actions(User, plan) == {"phone": "mask"}
    assert redact_field_value("phone", "mask", "13800138000") == "138****8000"
    assert redact_field_value("phone", "hidden", "13800138000") == ""


def test_super_admin_has_no_field_rules() -> None:
    plan = _plan(
        user=_user(is_super_admin=True),
        field_rules=[_rule(table_name="sys_user", field="phone", action="mask")],
        scope_tables={"sys_user"},
    )
    assert field_actions(User, plan) == {}
