from __future__ import annotations

from collections import defaultdict
from typing import Any

from sqlalchemy import delete, func, or_, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.codes import BizCode
from cccms.core.context import UserContext
from cccms.core.errors import Conflict, Forbidden, NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.scope import without_scope
from cccms.core.time import now
from cccms.models.user import Menu, Role, RoleNode, UserRole
from cccms.schemas.role import RoleCopyRequest, RoleNodeView, RoleOut, RoleSave, RoleUpdate

MAX_DEPTH = 5
SUPER_ADMIN_CODE = "super_admin"


def _to_out(row: Role) -> RoleOut:
    return RoleOut(
        id=row.id,
        tenant_id=row.tenant_id,
        code=row.code,
        name=row.name,
        parent_id=row.parent_id,
        data_scope=row.data_scope,
        is_system=row.is_system,
        status=row.status,
        sort=row.sort,
        remark=row.remark,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def _rows(session: AsyncSession, tenant_id: int, *, include_deleted: bool = False) -> list[Role]:
    statement = select(Role).where(Role.tenant_id == tenant_id)
    if not include_deleted:
        statement = statement.where(Role.delete_time.is_(None))
    return list((await session.scalars(statement.order_by(Role.sort.asc(), Role.id.asc()))).all())


async def list_roles(
    session: AsyncSession,
    actor: UserContext,
    keyword: str | None = None,
    *,
    status: int | None = None,
    data_scope: int | None = None,
    trashed: int = 0,
    node_id: int | None = None,
    page: int = 1,
    limit: int = 15,
) -> tuple[int, list[RoleOut]]:
    statement = select(Role).where(Role.tenant_id == actor.tenant_id)
    if trashed:
        statement = statement.where(Role.delete_time.is_not(None))
    else:
        statement = statement.where(Role.delete_time.is_(None))
    if node_id:
        # 左侧角色树：选中某个角色时只列出它和它的所有下级。
        all_roles = list((await session.scalars(select(Role).where(Role.tenant_id == actor.tenant_id))).all())
        children: dict[int | None, list[int]] = defaultdict(list)
        for item in all_roles:
            children[item.parent_id].append(item.id)
        wanted: set[int] = set()
        queue = [node_id]
        while queue:
            current = queue.pop()
            if current in wanted:
                continue
            wanted.add(current)
            queue.extend(children.get(current, []))
        statement = statement.where(Role.id.in_(wanted))
    if keyword:
        value = f"%{keyword.strip()}%"
        statement = statement.where(or_(Role.name.like(value), Role.code.like(value)))
    if status is not None:
        statement = statement.where(Role.status == status)
    if data_scope is not None:
        statement = statement.where(Role.data_scope == data_scope)
    total = int(await session.scalar(count_query(statement, Role.id)) or 0)
    pages = (await session.scalars(
        statement.order_by(Role.sort.asc(), Role.id.asc())
        .offset((page - 1) * limit)
        .limit(limit)
    )).all()
    return total, [_to_out(row) for row in pages]


async def role_tree(session: AsyncSession, actor: UserContext) -> list[dict[str, Any]]:
    rows = await _rows(session, actor.tenant_id)
    by_parent: dict[int | None, list[Role]] = defaultdict(list)
    for row in rows:
        by_parent[row.parent_id].append(row)

    def walk(parent_id: int | None) -> list[dict[str, Any]]:
        result: list[dict[str, Any]] = []
        for row in by_parent.get(parent_id, []):
            item = _to_out(row).model_dump()
            item["children"] = walk(row.id)
            result.append(item)
        return result

    return walk(None)


async def get_role(session: AsyncSession, actor: UserContext, role_id: int) -> Role:
    row = await session.scalar(
        select(Role).where(
            Role.id == role_id,
            Role.tenant_id == actor.tenant_id,
            Role.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("角色不存在")
    return row


async def _depth(session: AsyncSession, tenant_id: int, parent_id: int | None) -> int:
    if not parent_id:
        return 1
    rows = {row.id: row for row in await _rows(session, tenant_id)}
    depth = 1
    current = rows.get(parent_id)
    seen: set[int] = set()
    while current is not None and current.id not in seen:
        seen.add(current.id)
        depth += 1
        if depth > MAX_DEPTH:
            raise ValidationFailed(f"角色继承深度不能超过 {MAX_DEPTH} 级")
        current = rows.get(current.parent_id) if current.parent_id else None
    return depth


async def _check_parent(
    session: AsyncSession, actor: UserContext, parent_id: int | None, *, self_id: int | None = None
) -> None:
    if parent_id is None:
        return
    if self_id is not None and parent_id == self_id:
        raise ValidationFailed("上级角色不能是自己")
    rows = {row.id: row for row in await _rows(session, actor.tenant_id)}
    parent = rows.get(parent_id)
    if parent is None:
        raise ValidationFailed("上级角色不存在")
    current: Role | None = parent
    seen: set[int] = set()
    depth = 1
    while current is not None and current.id not in seen:
        if self_id is not None and current.id == self_id:
            raise ValidationFailed("上级角色不能是自己的子孙角色")
        seen.add(current.id)
        depth += 1
        if depth > MAX_DEPTH:
            raise ValidationFailed(f"角色继承深度不能超过 {MAX_DEPTH} 级")
        current = rows.get(current.parent_id) if current.parent_id else None


def _assert_reserved_code(code: str) -> None:
    if code == SUPER_ADMIN_CODE:
        raise Forbidden("super_admin 为系统保留编码，不允许创建或复制")


def _assert_scope_delegation(actor: UserContext, data_scope: int | None) -> None:
    """不能授出比操作者自身更宽的数据范围（数字越小范围越大）。"""
    if actor.is_super_admin or data_scope is None:
        return
    if data_scope < actor.data_scope:
        raise Forbidden(
            "不能授予比自身更宽的数据范围",
            code=BizCode.DELEGATION_DENIED,
            message_key="error.delegation_denied",
        )


def _assert_node_delegation(actor: UserContext, nodes: list[str]) -> None:
    """不能授出操作者自己都没有的权限节点。"""
    if actor.is_super_admin:
        return
    extra = sorted(set(nodes) - actor.permissions)
    if extra:
        raise Forbidden(
            f"不能授予自己未拥有的权限：{', '.join(extra[:5])}",
            code=BizCode.DELEGATION_DENIED,
            message_key="error.delegation_denied",
        )


async def create_role(session: AsyncSession, actor: UserContext, payload: RoleSave) -> RoleOut:
    _assert_reserved_code(payload.code)
    _assert_scope_delegation(actor, payload.data_scope)
    exists = await session.scalar(
        select(Role.id).where(
            Role.tenant_id == actor.tenant_id,
            Role.code == payload.code,
            Role.delete_time.is_(None),
        )
    )
    if exists:
        raise Conflict("角色编码已存在")
    await _check_parent(session, actor, payload.parent_id)
    row = Role(tenant_id=actor.tenant_id, **payload.model_dump(exclude={"nodes"}))
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return _to_out(row)


async def update_role(
    session: AsyncSession, actor: UserContext, role_id: int, payload: RoleUpdate
) -> RoleOut:
    row = await get_role(session, actor, role_id)
    if row.is_system or row.code == SUPER_ADMIN_CODE:
        raise Forbidden("系统内置角色不允许修改")
    data = payload.model_dump(exclude_unset=True)
    _assert_scope_delegation(actor, data.get("data_scope"))
    if "parent_id" in data:
        await _check_parent(session, actor, data["parent_id"], self_id=role_id)
    for key, value in data.items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    return _to_out(row)


async def delete_role(session: AsyncSession, actor: UserContext, role_id: int) -> None:
    row = await get_role(session, actor, role_id)
    if row.is_system or row.code == SUPER_ADMIN_CODE:
        raise Forbidden("系统内置角色不允许删除")
    child = await session.scalar(
        select(Role.id).where(Role.parent_id == role_id, Role.delete_time.is_(None)).limit(1)
    )
    if child:
        raise Conflict("请先删除子角色")
    assigned = await session.scalar(select(UserRole.id).where(UserRole.role_id == role_id).limit(1))
    if assigned:
        raise Conflict("角色仍有用户使用")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def own_nodes(session: AsyncSession, role_id: int) -> list[str]:
    return list(
        (
            await session.scalars(
                select(RoleNode.node).where(RoleNode.role_id == role_id).order_by(RoleNode.id.asc())
            )
        ).all()
    )


async def effective_nodes(session: AsyncSession, role: Role) -> list[str]:
    rows = {item.id: item for item in await _rows(session, role.tenant_id)}
    nodes: set[str] = set()
    current: Role | None = role
    seen: set[int] = set()
    while current is not None and current.id not in seen:
        seen.add(current.id)
        nodes.update(await own_nodes(session, current.id))
        current = rows.get(current.parent_id) if current.parent_id else None
    return sorted(nodes)


async def node_view(session: AsyncSession, actor: UserContext, role_id: int) -> RoleNodeView:
    role = await get_role(session, actor, role_id)
    own = await own_nodes(session, role.id)
    all_nodes = set(own)
    current = role
    seen: set[int] = {role.id}
    rows = {item.id: item for item in await _rows(session, actor.tenant_id)}
    while current.parent_id:
        if current.parent_id in seen:
            break
        parent = rows.get(current.parent_id)
        if parent is None:
            break
        seen.add(parent.id)
        all_nodes.update(await own_nodes(session, parent.id))
        current = parent
    return RoleNodeView(own=own, inherited=sorted(all_nodes - set(own)), all=sorted(all_nodes))


async def save_nodes(session: AsyncSession, actor: UserContext, role_id: int, nodes: list[str]) -> None:
    role = await get_role(session, actor, role_id)
    if role.is_system or role.code == SUPER_ADMIN_CODE:
        raise Forbidden("系统内置角色不允许修改授权")
    valid = set(
        (
            await session.scalars(select(Menu.node).where(Menu.delete_time.is_(None), Menu.node.is_not(None)))
        ).all()
    )
    cleaned = [node for node in dict.fromkeys(nodes) if node in valid]
    _assert_node_delegation(actor, cleaned)
    await session.execute(delete(RoleNode).where(RoleNode.role_id == role.id))
    for node in cleaned:
        session.add(RoleNode(role_id=role.id, node=node))
    await session.commit()


async def copy_role(
    session: AsyncSession, actor: UserContext, role_id: int, payload: RoleCopyRequest
) -> RoleOut:
    _assert_reserved_code(payload.code)
    source = await get_role(session, actor, role_id)
    _assert_scope_delegation(actor, source.data_scope)
    exists = await session.scalar(
        select(Role.id).where(
            Role.tenant_id == actor.tenant_id,
            Role.code == payload.code,
            Role.delete_time.is_(None),
        )
    )
    if exists:
        raise Conflict("角色编码已存在")
    await _check_parent(session, actor, source.parent_id)
    row = Role(
        tenant_id=actor.tenant_id,
        code=payload.code,
        name=payload.name or f"{source.name} 副本",
        parent_id=source.parent_id,
        data_scope=source.data_scope,
        is_system=0,
        status=source.status,
        sort=source.sort,
        remark=source.remark,
    )
    session.add(row)
    await session.flush()
    for node in await own_nodes(session, source.id):
        session.add(RoleNode(role_id=row.id, node=node))
    await session.commit()
    await session.refresh(row)
    return _to_out(row)


async def assigned_user_count(session: AsyncSession, role_id: int) -> int:
    with without_scope("role usage count"):
        return int(
            await session.scalar(
                select(func.count()).select_from(UserRole).where(UserRole.role_id == role_id)
            )
            or 0
        )
