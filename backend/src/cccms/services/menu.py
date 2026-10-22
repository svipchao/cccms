from __future__ import annotations

from collections import defaultdict

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import TenantContext, UserContext, current_context
from cccms.core.errors import Conflict, Forbidden, NotFound, ValidationFailed
from cccms.core.time import now
from cccms.models.user import Menu
from cccms.schemas.menu import MenuNode, MenuSave


def _assert_platform_write(actor: UserContext) -> None:
    """菜单与权限节点是全平台共享表，只能由平台租户的超管维护。"""
    ctx = current_context()
    if not actor.is_super_admin:
        raise Forbidden("仅超级管理员可维护菜单")
    if ctx is None or ctx.tenant is None or not ctx.tenant.is_platform:
        raise Forbidden("请先切换到平台租户再维护菜单")


PLATFORM_ONLY_PREFIXES = ("cccms:tenant",)


def _to_node(row: Menu, children: list[MenuNode] | None = None) -> MenuNode:
    return MenuNode(
        id=row.id,
        parent_id=row.parent_id,
        type=row.type,
        title=row.title,
        path=row.path,
        component=row.component,
        icon=row.icon,
        sort=row.sort,
        node=row.node,
        status=row.status,
        keep_alive=row.keep_alive,
        children=children or [],
    )


async def _rows(session: AsyncSession, *, trashed: bool = False) -> list[Menu]:
    statement = select(Menu)
    statement = statement.where(Menu.delete_time.is_not(None) if trashed else Menu.delete_time.is_(None))
    return list((await session.scalars(statement.order_by(Menu.sort.asc(), Menu.id.asc()))).all())


def _visible_ids(rows: list[Menu], user: UserContext, tenant: TenantContext) -> set[int]:
    if user.is_super_admin:
        visible = {row.id for row in rows}
    else:
        visible = {row.id for row in rows if row.node and row.node in user.permissions}
        by_parent: dict[int | None, list[Menu]] = defaultdict(list)
        for row in rows:
            by_parent[row.parent_id].append(row)
        queue = [row for row in rows if row.id in visible]
        while queue:
            current = queue.pop()
            if current.parent_id:
                parent = next(iter(by_parent.get(current.parent_id, [])), None)
                if parent is not None and parent.id not in visible:
                    visible.add(parent.id)
                    queue.append(parent)
    if not tenant.is_platform:
        visible = {
            row.id
            for row in rows
            if row.id in visible
            and not any((row.node or "").startswith(prefix) for prefix in PLATFORM_ONLY_PREFIXES)
        }
        # A platform-only parent must not drag its children in.
        visible = {
            row.id
            for row in rows
            if row.id in visible and (row.parent_id is None or row.parent_id in visible)
        }
    return visible


def build_tree(rows: list[Menu], visible: set[int]) -> list[MenuNode]:
    by_parent: dict[int | None, list[Menu]] = defaultdict(list)
    for row in rows:
        if row.id in visible:
            by_parent[row.parent_id].append(row)

    def walk(parent_id: int | None) -> list[MenuNode]:
        return [_to_node(row, walk(row.id)) for row in by_parent.get(parent_id, [])]

    roots = walk(None)
    # Orphans (parent outside scope) are promoted to roots, matching the
    # "department/subtree" behaviour of the original product.
    known = {node.id for node in roots}
    for row in rows:
        if row.id in visible and row.parent_id and row.parent_id not in visible and row.id not in known:
            roots.append(_to_node(row, walk(row.id)))
    return roots


async def full_tree(session: AsyncSession, *, trashed: bool = False) -> list[MenuNode]:
    rows = await _rows(session, trashed=trashed)
    return build_tree(rows, {row.id for row in rows})


async def user_tree(session: AsyncSession, user: UserContext, tenant: TenantContext) -> list[MenuNode]:
    rows = await _rows(session)
    visible = _visible_ids(rows, user, tenant)
    if not user.is_super_admin:
        visible = {row.id for row in rows if row.id in visible and row.type in (1, 2)}
    return build_tree(rows, visible)


async def create_menu(session: AsyncSession, actor: UserContext, payload: MenuSave) -> Menu:
    _assert_platform_write(actor)
    if payload.node:
        exists = await session.scalar(
            select(Menu.id).where(Menu.node == payload.node, Menu.delete_time.is_(None))
        )
        if exists:
            raise Conflict("权限节点已存在")
    if payload.parent_id:
        parent = await session.get(Menu, payload.parent_id)
        if parent is None or parent.delete_time is not None:
            raise ValidationFailed("上级菜单不存在")
    row = Menu(**payload.model_dump(), delete_time=None, create_time=now(), update_time=now())
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return row


async def update_menu(session: AsyncSession, actor: UserContext, menu_id: int, payload: MenuSave) -> Menu:
    _assert_platform_write(actor)
    row = await session.get(Menu, menu_id)
    if row is None or row.delete_time is not None:
        raise NotFound("菜单不存在")
    if payload.parent_id == menu_id:
        raise ValidationFailed("上级菜单不能是自己")
    if payload.parent_id:
        parent = await session.get(Menu, payload.parent_id)
        if parent is None or parent.delete_time is not None:
            raise ValidationFailed("上级菜单不存在")
        current: Menu | None = parent
        while current is not None and current.parent_id:
            if current.parent_id == menu_id:
                raise ValidationFailed("上级菜单不能是子孙节点")
            current = await session.get(Menu, current.parent_id)
    if payload.node and payload.node != row.node:
        exists = await session.scalar(
            select(Menu.id).where(Menu.node == payload.node, Menu.id != menu_id, Menu.delete_time.is_(None))
        )
        if exists:
            raise Conflict("权限节点已存在")
    for key, value in payload.model_dump().items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    await session.refresh(row)
    return row


async def delete_menu(session: AsyncSession, actor: UserContext, menu_id: int) -> None:
    _assert_platform_write(actor)
    row = await session.get(Menu, menu_id)
    if row is None or row.delete_time is not None:
        raise NotFound("菜单不存在")
    child = await session.scalar(
        select(Menu.id).where(Menu.parent_id == menu_id, Menu.delete_time.is_(None)).limit(1)
    )
    if child:
        raise Conflict("请先删除子菜单")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()
