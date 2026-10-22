from __future__ import annotations

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.scope import OPERATORS
from cccms.core.time import now
from cccms.db.base import Base
from cccms.models.rule import DataRule, DataScopeTable
from cccms.models.user import Dept, Post, Role, User, UserDept
from cccms.schemas.rule import (
    DataRuleFieldOption,
    DataRuleOptionItem,
    DataRuleOptionsOut,
    DataRuleOut,
    DataRuleQuery,
    DataRuleSave,
    DataRuleTableOption,
    DataRuleUserItem,
    ScopeTableOut,
    ScopeTableQuery,
    ScopeTableSave,
)

ACTIONS = ("row", "hidden", "readonly", "mask", "encrypt")

_TYPE_NAMES: dict[str, str] = {
    "BigInteger": "bigint",
    "Integer": "int",
    "SmallInteger": "smallint",
    "String": "varchar",
    "Text": "text",
    "DateTime": "datetime",
    "Date": "date",
    "Boolean": "tinyint",
    "Numeric": "decimal",
    "Float": "double",
    "JSON": "json",
}


def _type_name(column: object) -> str:
    return _TYPE_NAMES.get(type(getattr(column, "type", None)).__name__, "varchar")


def _table_label(table_name: str, title: str = "") -> str:
    table = Base.metadata.tables.get(table_name)
    return title or (str(table.comment) if table is not None and table.comment else table_name)


def _field_options(table_name: str) -> list[DataRuleFieldOption]:
    table = Base.metadata.tables.get(table_name)
    if table is None:
        return []
    return [
        DataRuleFieldOption(field=name, label=str(column.comment or name), type=_type_name(column))
        for name, column in table.columns.items()
    ]


def _to_out(rule: DataRule) -> DataRuleOut:
    return DataRuleOut(
        id=rule.id,
        name=rule.name,
        user_id=rule.user_id,
        post_id=rule.post_id,
        role_id=rule.role_id,
        dept_ids=list(rule.dept_ids or []),
        bind_mode=rule.bind_mode,
        table_name=rule.table_name,
        action=rule.action,
        field=rule.field,
        operator=rule.operator,
        value=rule.value,
        value_type=rule.value_type,
        status=rule.status,
        create_time=rule.create_time,
        update_time=rule.update_time,
    )


async def _decorate(session: AsyncSession, actor: UserContext, rows: list[DataRule]) -> list[DataRuleOut]:
    user_ids = {row.user_id for row in rows if row.user_id}
    post_ids = {row.post_id for row in rows if row.post_id}
    role_ids = {row.role_id for row in rows if row.role_id}
    dept_ids = {item for row in rows for item in (row.dept_ids or [])}

    users = (
        dict((await session.execute(select(User.id, User.nickname).where(User.id.in_(user_ids)))).all())
        if user_ids
        else {}
    )
    posts = (
        dict((await session.execute(select(Post.id, Post.name).where(Post.id.in_(post_ids)))).all())
        if post_ids
        else {}
    )
    roles = (
        dict((await session.execute(select(Role.id, Role.name).where(Role.id.in_(role_ids)))).all())
        if role_ids
        else {}
    )
    depts = (
        dict((await session.execute(select(Dept.id, Dept.name).where(Dept.id.in_(dept_ids)))).all())
        if dept_ids
        else {}
    )

    result: list[DataRuleOut] = []
    for row in rows:
        item = _to_out(row)
        item.user_name = str(users.get(row.user_id or 0, ""))
        item.post_name = str(posts.get(row.post_id or 0, ""))
        item.role_name = str(roles.get(row.role_id or 0, ""))
        item.dept_names = [
            str(depts.get(dept_id, "")) for dept_id in (row.dept_ids or []) if dept_id in depts
        ]
        item.bind_mode_label = "全部命中" if row.bind_mode == "and" else "任一命中"
        table = Base.metadata.tables.get(row.table_name or "")
        item.table_label = str(table.comment or "") if table is not None else ""
        result.append(item)
    return result


async def list_rules(
    session: AsyncSession,
    actor: UserContext,
    params: DataRuleQuery,
) -> tuple[int, list[DataRuleOut]]:
    statement = select(DataRule).where(
        DataRule.tenant_id == actor.tenant_id,
        DataRule.delete_time.is_not(None) if params.trashed else DataRule.delete_time.is_(None),
    )
    if params.keyword or params.name:
        statement = statement.where(DataRule.name.like(f"%{(params.name or params.keyword or '').strip()}%"))
    if params.table_name:
        statement = statement.where(DataRule.table_name == params.table_name)
    if params.field:
        statement = statement.where(DataRule.field == params.field)
    if params.action:
        statement = statement.where(DataRule.action == params.action)
    if params.status is not None:
        statement = statement.where(DataRule.status == params.status)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(DataRule.id.desc())
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    return total, await _decorate(session, actor, rows)


async def _get_rule(session: AsyncSession, actor: UserContext, rule_id: int) -> DataRule:
    row = await session.scalar(
        select(DataRule).where(
            DataRule.id == rule_id,
            DataRule.tenant_id == actor.tenant_id,
            DataRule.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("数据规则不存在")
    return row


async def _validate(session: AsyncSession, actor: UserContext, payload: DataRuleSave) -> None:
    if payload.table_name:
        registered = await session.scalar(
            select(DataScopeTable.id).where(
                DataScopeTable.tenant_id == actor.tenant_id,
                DataScopeTable.table_name == payload.table_name,
                DataScopeTable.status == 1,
            )
        )
        if registered is None:
            raise ValidationFailed("目标表未登记为受控表")
        table = Base.metadata.tables.get(payload.table_name)
        if table is None:
            raise ValidationFailed("目标表不存在")
        if payload.action == "row":
            if not payload.field or payload.field not in table.c:
                raise ValidationFailed("字段不在目标表中")
            if (payload.operator or "").lower() not in OPERATORS:
                raise ValidationFailed("操作符不在白名单内")
        elif payload.action in ACTIONS and payload.field and payload.field not in table.c:
            raise ValidationFailed("字段不在目标表中")
    elif payload.action == "row":
        raise ValidationFailed("行级规则必须选择受控表")


async def create_rule(session: AsyncSession, actor: UserContext, payload: DataRuleSave) -> DataRuleOut:
    await _validate(session, actor, payload)
    data = payload.model_dump()
    if payload.action != "row":
        data.update({"operator": None, "value": None, "value_type": "static"})
    row = DataRule(tenant_id=actor.tenant_id, **data)
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return (await _decorate(session, actor, [row]))[0]


async def update_rule(
    session: AsyncSession, actor: UserContext, rule_id: int, payload: DataRuleSave
) -> DataRuleOut:
    row = await _get_rule(session, actor, rule_id)
    await _validate(session, actor, payload)
    data = payload.model_dump()
    if payload.action != "row":
        data.update({"operator": None, "value": None, "value_type": "static"})
    for key, value in data.items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    return (await _decorate(session, actor, [row]))[0]


async def delete_rule(session: AsyncSession, actor: UserContext, rule_id: int) -> None:
    row = await _get_rule(session, actor, rule_id)
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def options(session: AsyncSession, actor: UserContext) -> DataRuleOptionsOut:
    users = list(
        (
            await session.scalars(
                select(User)
                .where(User.tenant_id == actor.tenant_id, User.delete_time.is_(None), User.status == 1)
                .order_by(User.id.asc())
                .limit(500)
            )
        ).all()
    )
    posts = list(
        (
            await session.scalars(
                select(Post)
                .where(Post.tenant_id == actor.tenant_id, Post.delete_time.is_(None), Post.status == 1)
                .order_by(Post.sort.asc(), Post.id.asc())
            )
        ).all()
    )
    roles = list(
        (
            await session.scalars(
                select(Role)
                .where(Role.tenant_id == actor.tenant_id, Role.delete_time.is_(None), Role.status == 1)
                .order_by(Role.sort.asc(), Role.id.asc())
            )
        ).all()
    )
    depts = list(
        (
            await session.scalars(
                select(Dept)
                .where(Dept.tenant_id == actor.tenant_id, Dept.delete_time.is_(None), Dept.status == 1)
                .order_by(Dept.sort.asc(), Dept.id.asc())
            )
        ).all()
    )
    tables = list(
        (
            await session.scalars(
                select(DataScopeTable)
                .where(DataScopeTable.tenant_id == actor.tenant_id, DataScopeTable.status == 1)
                .order_by(DataScopeTable.id.asc())
            )
        ).all()
    )
    return DataRuleOptionsOut(
        users=[
            DataRuleOptionItem(
                id=row.id, name=f"{row.nickname}({row.username})" if row.nickname else row.username
            )
            for row in users
        ],
        posts=[DataRuleOptionItem(id=row.id, name=row.name, extra=row.code) for row in posts],
        roles=[DataRuleOptionItem(id=row.id, name=row.name, extra=row.code) for row in roles],
        depts=[DataRuleOptionItem(id=row.id, name=row.name) for row in depts],
        tables=[
            DataRuleTableOption(
                table=row.table_name,
                full=row.table_name,
                label=_table_label(row.table_name, row.title),
                fields=_field_options(row.table_name),
            )
            for row in tables
        ],
        operators=sorted(OPERATORS),
        actions=list(ACTIONS),
    )


async def search_users(
    session: AsyncSession,
    actor: UserContext,
    keyword: str | None = None,
    ids: list[int] | None = None,
) -> list[DataRuleUserItem]:
    statement = select(User).where(
        User.tenant_id == actor.tenant_id,
        User.delete_time.is_(None),
    )
    if ids:
        statement = statement.where(User.id.in_(ids))
    else:
        statement = statement.where(User.status == 1)
        if keyword:
            value = f"%{keyword.strip()}%"
            statement = statement.where(User.username.like(value) | User.nickname.like(value))
    rows = list((await session.scalars(statement.order_by(User.id.asc()).limit(50))).all())
    dept_map: dict[int, list[str]] = {}
    if rows:
        pairs = (
            await session.execute(
                select(UserDept.user_id, Dept.name)
                .join(Dept, Dept.id == UserDept.dept_id)
                .where(UserDept.user_id.in_({row.id for row in rows}))
            )
        ).all()
        for user_id, name in pairs:
            dept_map.setdefault(int(user_id), []).append(str(name))
    return [
        DataRuleUserItem(
            id=row.id,
            username=row.username,
            nickname=row.nickname,
            dept_names=dept_map.get(row.id, []),
        )
        for row in rows
    ]


# ---- 受控表 --------------------------------------------------------------


def _table_out(row: DataScopeTable, rule_count: int = 0) -> ScopeTableOut:
    table = Base.metadata.tables.get(row.table_name)
    return ScopeTableOut(
        id=row.id,
        table_name=row.table_name,
        title=row.title,
        label=row.title,
        status=row.status,
        remark=row.remark,
        rule_count=rule_count,
        field_count=len(table.columns) if table is not None else 0,
        exists=table is not None,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def list_tables(
    session: AsyncSession,
    actor: UserContext,
    params: ScopeTableQuery,
) -> tuple[int, list[ScopeTableOut]]:
    statement = select(DataScopeTable).where(DataScopeTable.tenant_id == actor.tenant_id)
    if params.keyword:
        value = f"%{params.keyword.strip()}%"
        statement = statement.where(DataScopeTable.title.like(value) | DataScopeTable.table_name.like(value))
    if params.status is not None:
        statement = statement.where(DataScopeTable.status == params.status)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(DataScopeTable.id.asc())
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    counts = (
        dict(
            (
                await session.execute(
                    select(DataRule.table_name, func.count(DataRule.id))
                    .where(
                        DataRule.tenant_id == actor.tenant_id,
                        DataRule.delete_time.is_(None),
                        DataRule.table_name.in_({row.table_name for row in rows}),
                    )
                    .group_by(DataRule.table_name)
                )
            ).all()
        )
        if rows
        else {}
    )
    return total, [_table_out(row, int(counts.get(row.table_name, 0))) for row in rows]


async def _assert_table(
    session: AsyncSession, actor: UserContext, table_name: str, exclude_id: int = 0
) -> None:
    if Base.metadata.tables.get(table_name) is None:
        raise ValidationFailed("数据表不存在")
    exists = await session.scalar(
        select(DataScopeTable.id).where(
            DataScopeTable.tenant_id == actor.tenant_id,
            DataScopeTable.table_name == table_name,
            DataScopeTable.id != exclude_id,
        )
    )
    if exists:
        raise Conflict("该表已登记")


async def create_table(session: AsyncSession, actor: UserContext, payload: ScopeTableSave) -> ScopeTableOut:
    await _assert_table(session, actor, payload.table_name)
    row = DataScopeTable(tenant_id=actor.tenant_id, **payload.model_dump(exclude={"label"}))
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return _table_out(row)


async def update_table(
    session: AsyncSession, actor: UserContext, table_id: int, payload: ScopeTableSave
) -> ScopeTableOut:
    row = await session.scalar(
        select(DataScopeTable).where(
            DataScopeTable.id == table_id,
            DataScopeTable.tenant_id == actor.tenant_id,
        )
    )
    if row is None:
        raise NotFound("受控表不存在")
    data = payload.model_dump(exclude={"label"})
    if payload.table_name:
        await _assert_table(session, actor, payload.table_name, table_id)
    else:
        data.pop("table_name", None)
    for key, value in data.items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    return _table_out(row)


async def delete_table(session: AsyncSession, actor: UserContext, table_id: int) -> tuple[int, int]:
    row = await session.scalar(
        select(DataScopeTable).where(
            DataScopeTable.id == table_id,
            DataScopeTable.tenant_id == actor.tenant_id,
        )
    )
    if row is None:
        raise NotFound("受控表不存在")
    rule_count = int(
        await session.scalar(
            select(func.count(DataRule.id)).where(
                DataRule.tenant_id == actor.tenant_id,
                DataRule.table_name == row.table_name,
                DataRule.delete_time.is_(None),
            )
        )
        or 0
    )
    if rule_count:
        raise Conflict(f"该表仍有 {rule_count} 条规则，请先删除规则")
    await session.delete(row)
    await session.commit()
    return row.id, rule_count


__all__ = [
    "create_rule",
    "create_table",
    "delete_rule",
    "delete_table",
    "list_rules",
    "list_tables",
    "options",
    "search_users",
    "update_rule",
    "update_table",
]
