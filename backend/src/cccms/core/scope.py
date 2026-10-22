from __future__ import annotations

import contextvars
import re
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from dataclasses import dataclass, field
from typing import Any, cast

from sqlalchemy import ColumnElement, Select, false, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext, current_context
from cccms.core.errors import Forbidden
from cccms.models.rule import DataRule, DataScopeTable

FIELD_PATTERN = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
OPERATORS = {"=", "!=", "<>", ">", ">=", "<", "<=", "like", "in", "between"}
DYNAMIC_PATTERN = re.compile(r"\{([a-z_.]+)\}")
SCOPE_BYPASS: contextvars.ContextVar[tuple[bool, str]] = contextvars.ContextVar(
    "cccms_scope_bypass", default=(False, "")
)


@dataclass(frozen=True, slots=True)
class ScopeConfig:
    owner: str | Callable[[type[Any], UserContext], ColumnElement[bool]] | None = None
    dept: str | Callable[[type[Any], tuple[int, ...]], ColumnElement[bool]] | None = None
    no_baseline: bool = False


@dataclass(slots=True)
class ScopePlan:
    user: UserContext
    data_scope: int
    dept_ids: tuple[int, ...]
    dept_subtree_ids: tuple[int, ...]
    post_ids: tuple[int, ...]
    effective_role_ids: tuple[int, ...]
    row_rules: list[DataRule] = field(default_factory=list)
    field_rules: list[DataRule] = field(default_factory=list)
    scope_tables: set[str] = field(default_factory=set)


async def build_plan(session: AsyncSession, user: UserContext) -> ScopePlan:
    if user.is_super_admin:
        return ScopePlan(
            user=user,
            data_scope=1,
            dept_ids=user.dept_ids,
            dept_subtree_ids=user.dept_subtree_ids,
            post_ids=user.post_ids,
            effective_role_ids=user.effective_role_ids,
            scope_tables=set(),
        )

    rules = list(
        (
            await session.scalars(
                select(DataRule)
                .where(
                    DataRule.tenant_id == user.tenant_id,
                    DataRule.status == 1,
                )
                .order_by(DataRule.id.asc())
            )
        ).all()
    )
    scope_tables = set(
        (
            await session.scalars(
                select(DataScopeTable.table_name).where(
                    DataScopeTable.tenant_id == user.tenant_id,
                    DataScopeTable.status == 1,
                )
            )
        ).all()
    )
    return ScopePlan(
        user=user,
        data_scope=user.data_scope,
        dept_ids=user.dept_ids,
        dept_subtree_ids=user.dept_subtree_ids,
        post_ids=user.post_ids,
        effective_role_ids=user.effective_role_ids,
        row_rules=[rule for rule in rules if rule.action == "row"],
        field_rules=[rule for rule in rules if rule.action != "row"],
        scope_tables=scope_tables,
    )


def get_plan() -> ScopePlan | None:
    ctx = current_context()
    return ctx.scope_plan if ctx else None


@contextmanager
def without_scope(reason: str) -> Iterator[None]:
    """Explicit escape hatch.  The reason is mandatory and shows up in audit."""
    if not reason.strip():
        raise ValueError("without_scope(reason) requires a non-empty reason")
    token = SCOPE_BYPASS.set((True, reason))
    try:
        yield
    finally:
        SCOPE_BYPASS.reset(token)


def scope_bypassed() -> bool:
    return SCOPE_BYPASS.get()[0]


def tenant_clause(model: type[Any]) -> ColumnElement[bool] | None:
    ctx = current_context()
    if ctx is None or ctx.tenant is None:
        return None
    if not hasattr(model, "tenant_id"):
        return None
    clause: ColumnElement[bool] = model.tenant_id == ctx.tenant.id
    return clause


def _owner_clause(model: type[Any], cfg: ScopeConfig, user: UserContext) -> ColumnElement[bool] | None:
    owner = cfg.owner
    if owner is None:
        return None
    if callable(owner):
        return owner(model, user)
    column = getattr(model, owner, None)
    if column is None:
        return None
    clause: ColumnElement[bool] = column == user.id
    return clause


def _dept_clause(model: type[Any], cfg: ScopeConfig, ids: tuple[int, ...]) -> ColumnElement[bool] | None:
    dept = cfg.dept
    if dept is None:
        return false()
    if not ids:
        return false()
    if callable(dept):
        return dept(model, ids)
    column = getattr(model, dept, None)
    if column is None:
        return false()
    clause: ColumnElement[bool] = column.in_(ids)
    return clause


def _rulematch(rule: DataRule, plan: ScopePlan) -> bool:
    dimensions: list[bool] = []
    if rule.user_id:
        dimensions.append(rule.user_id == plan.user.id)
    if rule.post_id:
        dimensions.append(rule.post_id in plan.post_ids)
    if rule.role_id:
        dimensions.append(rule.role_id in plan.effective_role_ids)
    dept_ids = tuple(rule.dept_ids or ())
    if dept_ids:
        dimensions.append(bool(set(dept_ids) & set(plan.dept_subtree_ids)))
    if not dimensions:
        return True
    if rule.bind_mode == "and":
        return all(dimensions)
    return any(dimensions)


def _coerce(column: Any, value: str) -> Any:
    try:
        python_type = column.type.python_type
    except (AttributeError, NotImplementedError):
        return value
    if python_type is int:
        try:
            return int(value)
        except ValueError:
            return value
    if python_type is float:
        try:
            return float(value)
        except ValueError:
            return value
    if python_type is bool:
        return value.lower() in {"1", "true", "yes", "on"}
    return value


def _expand_dynamic(value: str, plan: ScopePlan) -> list[str]:
    variables = {
        "user.id": [str(plan.user.id)],
        "dept.ids": [str(item) for item in plan.dept_ids],
        "dept.subtree": [str(item) for item in plan.dept_subtree_ids],
        "post.ids": [str(item) for item in plan.post_ids],
        "role.ids": [str(item) for item in plan.effective_role_ids],
    }

    def replace(match: re.Match[str]) -> str:
        return ",".join(variables.get(match.group(1), [match.group(0)]))

    return [part.strip() for part in DYNAMIC_PATTERN.sub(replace, value).split(",") if part.strip()]


def _as_clause(expression: Any) -> ColumnElement[bool]:
    """动态列表达式统一收敛为布尔条件, 避免 Any 泄漏到调用方."""
    return cast(ColumnElement[bool], expression)


def _rule_expression(model: type[Any], rule: DataRule, plan: ScopePlan) -> ColumnElement[bool] | None:
    # 规则配置损坏时 fail-closed：宁可查不到数据，也不能静默放行整表。
    if not rule.field or not FIELD_PATTERN.match(rule.field):
        return false()
    column = getattr(model, rule.field, None)
    if column is None:
        return false()
    operator = (rule.operator or "").lower()
    if operator not in OPERATORS:
        return false()
    raw_value = rule.value or ""
    values = (
        _expand_dynamic(raw_value, plan)
        if rule.value_type == "dynamic"
        else [part.strip() for part in raw_value.split(",") if part.strip()]
    )

    if operator == "in":
        if not values:
            return false()
        return _as_clause(column.in_([_coerce(column, item) for item in values]))
    if operator == "between":
        if len(values) < 2:
            return false()
        return _as_clause(column.between(_coerce(column, values[0]), _coerce(column, values[1])))
    if len(values) > 1:
        return _as_clause(column.in_([_coerce(column, item) for item in values]))
    if not values:
        return false()
    value = values[0]
    if operator == "like":
        return _as_clause(column.like(value))
    if operator in {"!=", "<>"}:
        return _as_clause(column != _coerce(column, value))
    if operator == ">":
        return _as_clause(column > _coerce(column, value))
    if operator == ">=":
        return _as_clause(column >= _coerce(column, value))
    if operator == "<":
        return _as_clause(column < _coerce(column, value))
    if operator == "<=":
        return _as_clause(column <= _coerce(column, value))
    return _as_clause(column == _coerce(column, value))


def _applies_to(rule: DataRule, model: type[Any], plan: ScopePlan) -> bool:
    table = rule.table_name or ""
    if table and table != model.__tablename__:
        return False
    if table and table not in plan.scope_tables:
        return False
    return _rulematch(rule, plan)


def scope_clause(model: type[Any], plan: ScopePlan | None = None) -> ColumnElement[bool] | None:
    if scope_bypassed():
        return None
    effective_plan = plan or get_plan()
    if effective_plan is None:
        return None
    if effective_plan.user.is_super_admin:
        return None
    cfg = getattr(model, "__data_scope__", None)
    if cfg is None or cfg is False:
        return None
    if not isinstance(cfg, ScopeConfig):
        raise TypeError(f"{model.__name__}.__data_scope__ must be ScopeConfig or None")

    clauses: list[ColumnElement[bool]] = []
    if effective_plan.data_scope == 1:
        return None

    if not cfg.no_baseline:
        if effective_plan.data_scope == 4:
            owner = _owner_clause(model, cfg, effective_plan.user)
            if owner is None:
                clauses.append(false())
            else:
                clauses.append(owner)
        elif effective_plan.data_scope == 3:
            dept = _dept_clause(model, cfg, effective_plan.dept_ids)
            clauses.append(dept if dept is not None else false())
        elif effective_plan.data_scope == 2:
            dept = _dept_clause(model, cfg, effective_plan.dept_subtree_ids)
            clauses.append(dept if dept is not None else false())
        elif effective_plan.data_scope == 5:
            pass
        else:
            clauses.append(false())

    matched = 0
    for rule in effective_plan.row_rules:
        if not _applies_to(rule, model, effective_plan):
            continue
        expression = _rule_expression(model, rule, effective_plan)
        if expression is None:
            continue
        matched += 1
        clauses.append(expression)

    if effective_plan.data_scope == 5 and matched == 0:
        clauses.append(false())

    if not clauses:
        return None
    result = clauses[0]
    for clause in clauses[1:]:
        result = result & clause
    return result


def apply_scope[StatementT: Select[*tuple[Any, ...]]](
    statement: StatementT,
    model: type[Any],
    *,
    include_deleted: bool = False,
    plan: ScopePlan | None = None,
) -> StatementT:
    tenant = tenant_clause(model)
    if tenant is not None:
        statement = statement.where(tenant)
    if not include_deleted and hasattr(model, "delete_time"):
        statement = statement.where(model.delete_time.is_(None))
    clause = scope_clause(model, plan)
    if clause is not None:
        statement = statement.where(clause)
    return statement


def field_actions(model: type[Any], plan: ScopePlan | None = None) -> dict[str, str]:
    effective_plan = plan or get_plan()
    if effective_plan is None or effective_plan.user.is_super_admin:
        return {}
    result: dict[str, str] = {}
    for rule in effective_plan.field_rules:
        if not _applies_to(rule, model, effective_plan):
            continue
        if not rule.field or not FIELD_PATTERN.match(rule.field):
            continue
        if not hasattr(model, rule.field):
            continue
        if rule.action in {"hidden", "readonly", "mask", "encrypt"}:
            result[rule.field] = rule.action
    return result


def _mask_value(field: str, value: object) -> object:
    if not isinstance(value, str) or not value:
        return value
    lowered = field.lower()
    if any(key in lowered for key in ("phone", "mobile", "tel")):
        return value[:3] + "****" + value[-4:] if len(value) >= 7 else "***"
    if "card" in lowered or "idcard" in lowered:
        return value[:4] + "********" + value[-4:] if len(value) >= 8 else "***"
    if len(value) <= 2:
        return "*" * len(value)
    head = max(1, len(value) // 3)
    tail = max(1, len(value) // 3)
    return value[:head] + "*" * (len(value) - head - tail) + value[-tail:]


def redact_field_value(field: str, action: str, value: object) -> object:
    """按字段级规则改写单个值（列表 / 详情 / 导出共用同一套语义）。"""
    if value is None:
        return value
    if action == "hidden":
        return "" if isinstance(value, str) else None
    if action in ("mask", "encrypt"):
        # encrypt 目前只做展示层遮蔽：前端有解密 helper 但页面尚未接入，
        # 直接回传密文会让用户看到无法理解的 base64。
        return _mask_value(field, value)
    return value


def apply_field_actions(model: type[Any], payload: Any) -> Any:
    """Apply field-level rules to a Pydantic output object in place.

    Scalars are rewritten instead of removed so the response schema stays valid:
    ``hidden`` blanks strings, while ``mask`` / ``encrypt`` keep a displayable
    partial value (the frontend has no page consuming ciphertext yet).
    """
    ctx = current_context()
    actions = field_actions(model)
    if not actions or ctx is None:
        return payload
    for field_name, action in actions.items():
        if not hasattr(payload, field_name):
            continue
        value = getattr(payload, field_name)
        if value is None:
            continue
        setattr(payload, field_name, redact_field_value(field_name, action, value))
    return payload


def blocked_write_fields(model: type[Any]) -> set[str]:
    """Field-level rules are write-protected: masked/hidden values must never round-trip."""
    return set(field_actions(model).keys())


def assert_scope_reason(reason: str) -> None:
    if not reason.strip():
        raise Forbidden("缺少数据权限豁免理由")
