from __future__ import annotations

from collections import defaultdict

from sqlalchemy import ColumnElement, false, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound, ValidationFailed
from cccms.core.scope import ScopeConfig, apply_field_actions, apply_scope, blocked_write_fields
from cccms.core.time import now
from cccms.models.user import Dept, UserDept
from cccms.schemas.dept import DeptNode, DeptSave


def _dept_owner(model: type[Dept], user: UserContext) -> ColumnElement[bool]:
    if not user.dept_ids:
        return false()
    return model.id.in_(user.dept_ids)


def _dept_subtree(model: type[Dept], dept_ids: tuple[int, ...]) -> ColumnElement[bool]:
    if not dept_ids:
        return false()
    return model.id.in_(dept_ids)


Dept.__data_scope__ = ScopeConfig(owner=_dept_owner, dept=_dept_subtree)


def _to_node(row: Dept, children: list[DeptNode] | None = None) -> DeptNode:
    out: DeptNode = apply_field_actions(
        Dept,
        DeptNode(
            id=row.id,
            parent_id=row.parent_id,
            name=row.name,
            leader=row.leader,
            phone=row.phone,
            email=row.email,
            sort=row.sort,
            status=row.status,
            path=row.path,
            level=row.level,
            create_time=row.create_time,
            children=children or [],
        ),
    )
    return out


async def _rows(
    session: AsyncSession,
    actor: UserContext,
    *,
    scoped: bool = True,
    include_deleted: bool = False,
    trashed: bool = False,
) -> list[Dept]:
    statement = select(Dept)
    if scoped:
        statement = apply_scope(
            statement,
            Dept,
            include_deleted=include_deleted or trashed,
        )
    else:
        statement = statement.where(Dept.tenant_id == actor.tenant_id)
    if trashed:
        statement = statement.where(Dept.delete_time.is_not(None))
    elif not include_deleted:
        statement = statement.where(Dept.delete_time.is_(None))
    return list((await session.scalars(statement.order_by(Dept.sort.asc(), Dept.id.asc()))).all())


def _build(rows: list[Dept]) -> list[DeptNode]:
    by_parent: dict[int | None, list[Dept]] = defaultdict(list)
    for row in rows:
        by_parent[row.parent_id].append(row)

    def walk(parent_id: int | None) -> list[DeptNode]:
        return [_to_node(row, walk(row.id)) for row in by_parent.get(parent_id, [])]

    roots = walk(None)
    known = {node.id for node in roots}
    for row in rows:
        if row.parent_id and row.parent_id not in {item.id for item in rows} and row.id not in known:
            roots.append(_to_node(row, walk(row.id)))
    return roots


async def tree(session: AsyncSession, actor: UserContext, *, trashed: bool = False) -> list[DeptNode]:
    return _build(await _rows(session, actor, trashed=trashed))


async def tree_all(session: AsyncSession, actor: UserContext) -> list[DeptNode]:
    """选择器用的部门树同样受数据范围约束，避免越权读取组织架构。"""
    return _build(await _rows(session, actor))


async def get_dept(session: AsyncSession, actor: UserContext, dept_id: int) -> Dept:
    """写操作与详情统一走可见性校验，防止「看不到却能改」。"""
    row = await session.scalar(apply_scope(select(Dept).where(Dept.id == dept_id), Dept))
    if row is None:
        raise NotFound("部门不存在")
    return row


async def _assert_parent(
    session: AsyncSession, actor: UserContext, parent_id: int | None, *, self_id: int | None = None
) -> Dept | None:
    if parent_id is None:
        return None
    if self_id is not None and parent_id == self_id:
        raise ValidationFailed("上级部门不能是自己")
    parent = await get_dept(session, actor, parent_id)
    if self_id is not None:
        current: Dept | None = parent
        while current is not None:
            if current.id == self_id:
                raise ValidationFailed("上级部门不能是自己的子部门")
            current = await get_dept(session, actor, current.parent_id) if current.parent_id else None
    return parent


async def create_dept(session: AsyncSession, actor: UserContext, payload: DeptSave) -> DeptNode:
    parent = await _assert_parent(session, actor, payload.parent_id)
    row = Dept(
        tenant_id=actor.tenant_id,
        parent_id=payload.parent_id,
        name=payload.name,
        leader=payload.leader,
        phone=payload.phone,
        email=payload.email,
        sort=payload.sort,
        status=payload.status,
        path="/",
        level=(parent.level + 1) if parent else 1,
    )
    session.add(row)
    await session.flush()
    row.path = f"{parent.path}{row.id}/" if parent else f"/{row.id}/"
    await session.commit()
    await session.refresh(row)
    return _to_node(row)


async def update_dept(session: AsyncSession, actor: UserContext, dept_id: int, payload: DeptSave) -> DeptNode:
    row = await get_dept(session, actor, dept_id)
    parent = await _assert_parent(session, actor, payload.parent_id, self_id=dept_id)
    old_path = row.path
    blocked = blocked_write_fields(Dept)
    for key, value in payload.model_dump().items():
        if key not in blocked:
            setattr(row, key, value)
    row.level = (parent.level + 1) if parent else 1
    row.path = f"{parent.path}{row.id}/" if parent else f"/{row.id}/"
    row.update_time = now()
    if old_path != row.path:
        descendants = list(
            (
                await session.scalars(
                    select(Dept).where(
                        Dept.tenant_id == actor.tenant_id,
                        Dept.path.like(f"{old_path}%"),
                        Dept.id != row.id,
                    )
                )
            ).all()
        )
        for item in descendants:
            item.path = item.path.replace(old_path, row.path, 1)
    await session.commit()
    await session.refresh(row)
    return _to_node(row)


async def delete_dept(session: AsyncSession, actor: UserContext, dept_id: int) -> None:
    row = await get_dept(session, actor, dept_id)
    child = await session.scalar(
        select(Dept.id).where(Dept.parent_id == dept_id, Dept.delete_time.is_(None)).limit(1)
    )
    if child:
        raise Conflict("请先删除子部门")
    used = await session.scalar(select(UserDept.id).where(UserDept.dept_id == dept_id).limit(1))
    if used:
        raise Conflict("部门下仍有用户")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()
