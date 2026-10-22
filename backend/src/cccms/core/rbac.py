from __future__ import annotations

from collections import deque
from collections.abc import Iterable
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.cache import Keys, bump, get_json, set_json, version
from cccms.core.context import UserContext
from cccms.models.user import Dept, Menu, Role, RoleNode, User, UserDept, UserPost, UserRole

SUPER_ADMIN_CODE = "super_admin"
MAX_ROLE_DEPTH = 5
# 鉴权上下文缓存 TTL：权限写操作会主动 bump 版本号，这里只兜底漏掉的失效路径。
AUTH_CACHE_TTL = 120
# 数据范围档位：1=全部，2=本部门及以下，3=本部门，4=仅本人，5=自定义。数字越大范围越小。
DEFAULT_DATA_SCOPE = 4


def merge_data_scope(scopes: Iterable[int]) -> int:
    """多角色合并取“最严”档位。

    取 max 而不是 min：任一角色的宽范围不得静默放大整个账号，
    与 docs/06-数据权限.md「避免隐式提权」保持一致。无角色时退化为“仅本人”。
    """
    return max(scopes, default=DEFAULT_DATA_SCOPE)


async def load_user_context(session: AsyncSession, *, user_id: int, tenant_id: int) -> UserContext | None:
    """带 Redis 缓存的鉴权上下文装载。

    权限仍然是“写时失效 + 短 TTL”而不是长期缓存：任何角色/用户/菜单/部门的
    写请求都会 bump ``Keys.AUTH_VERSION``，最坏情况下陈旧窗口等于 TTL。
    """
    cached = await _cached_user(user_id=user_id, tenant_id=tenant_id)
    if cached is not None:
        return cached
    user = await _load_user_context(session, user_id=user_id, tenant_id=tenant_id)
    if user is not None:
        await _store_user(user)
    return user


async def invalidate_auth_cache() -> None:
    await bump(Keys.AUTH_VERSION)


async def _cached_user(*, user_id: int, tenant_id: int) -> UserContext | None:
    auth_version = await version(Keys.AUTH_VERSION)
    payload = await get_json(Keys.auth_context(auth_version, user_id, tenant_id))
    if not isinstance(payload, dict):
        return None
    return _user_from_payload(payload)


async def _store_user(user: UserContext) -> None:
    auth_version = await version(Keys.AUTH_VERSION)
    await set_json(
        Keys.auth_context(auth_version, user.id, user.home_tenant_id),
        _user_payload(user),
        ttl=AUTH_CACHE_TTL,
    )


def _user_payload(user: UserContext) -> dict[str, object]:
    return {
        "id": user.id,
        "username": user.username,
        "nickname": user.nickname,
        "tenant_id": user.tenant_id,
        "home_tenant_id": user.home_tenant_id,
        "is_super_admin": user.is_super_admin,
        "permissions": sorted(user.permissions),
        "role_ids": list(user.role_ids),
        "effective_role_ids": list(user.effective_role_ids),
        "dept_ids": list(user.dept_ids),
        "dept_subtree_ids": list(user.dept_subtree_ids),
        "post_ids": list(user.post_ids),
        "data_scope": user.data_scope,
        "may_decrypt_fields": user.may_decrypt_fields,
    }


def _user_from_payload(payload: dict[str, Any]) -> UserContext | None:
    try:
        return UserContext(
            id=int(payload["id"]),
            username=str(payload["username"]),
            nickname=str(payload["nickname"]),
            tenant_id=int(payload["tenant_id"]),
            home_tenant_id=int(payload["home_tenant_id"]),
            is_super_admin=bool(payload["is_super_admin"]),
            permissions=frozenset(str(item) for item in payload["permissions"]),
            role_ids=tuple(int(item) for item in payload["role_ids"]),
            effective_role_ids=tuple(int(item) for item in payload["effective_role_ids"]),
            dept_ids=tuple(int(item) for item in payload["dept_ids"]),
            dept_subtree_ids=tuple(int(item) for item in payload["dept_subtree_ids"]),
            post_ids=tuple(int(item) for item in payload["post_ids"]),
            data_scope=int(payload["data_scope"]),
            jti="",
            token_exp=0,
            token_iat=0,
            may_decrypt_fields=bool(payload.get("may_decrypt_fields", False)),
        )
    except (KeyError, TypeError, ValueError):
        return None


async def _load_user_context(
    session: AsyncSession, *, user_id: int, tenant_id: int
) -> UserContext | None:
    user = await session.scalar(
        select(User).where(
            User.id == user_id,
            User.tenant_id == tenant_id,
            User.delete_time.is_(None),
            User.status == 1,
        )
    )
    if user is None:
        return None

    direct_roles = list(
        (
            await session.scalars(
                select(Role)
                .join(UserRole, UserRole.role_id == Role.id)
                .where(
                    UserRole.user_id == user.id,
                    Role.tenant_id == tenant_id,
                    Role.status == 1,
                    Role.delete_time.is_(None),
                )
            )
        ).all()
    )
    if not direct_roles:
        return None

    all_roles = list(
        (
            await session.scalars(
                select(Role).where(
                    Role.tenant_id == tenant_id,
                    Role.delete_time.is_(None),
                )
            )
        ).all()
    )
    by_id = {role.id: role for role in all_roles}

    effective: dict[int, int] = {}
    queue: deque[tuple[int, int]] = deque((role.id, 0) for role in direct_roles)
    while queue:
        role_id, depth = queue.popleft()
        if role_id in effective or depth > MAX_ROLE_DEPTH:
            continue
        role = by_id.get(role_id)
        if role is None or role.status != 1:
            continue
        effective[role_id] = depth
        if role.parent_id:
            queue.append((role.parent_id, depth + 1))

    effective_ids = tuple(effective.keys())
    is_super_admin = any(role.code == SUPER_ADMIN_CODE for role in direct_roles)

    permissions: set[str] = set()
    if not is_super_admin and effective_ids:
        rows = await session.execute(
            select(RoleNode.node)
            .join(Role, Role.id == RoleNode.role_id)
            .where(
                Role.tenant_id == tenant_id,
                RoleNode.role_id.in_(effective_ids),
            )
        )
        permissions = {str(row[0]) for row in rows.all() if row[0]}

        active_nodes = set(
            (
                await session.scalars(
                    select(Menu.node).where(
                        Menu.node.is_not(None),
                        Menu.node != "",
                        Menu.delete_time.is_(None),
                    )
                )
            ).all()
        )
        permissions = {node for node in permissions if node in active_nodes}

    dept_ids = tuple(
        dict.fromkeys(
            (
                await session.scalars(select(UserDept.dept_id).where(UserDept.user_id == user.id))
            ).all()
        )
    )
    post_ids = tuple(
        dict.fromkeys(
            (
                await session.scalars(select(UserPost.post_id).where(UserPost.user_id == user.id))
            ).all()
        )
    )

    departments = list(
        (
            await session.scalars(
                select(Dept).where(
                    Dept.tenant_id == tenant_id,
                    Dept.delete_time.is_(None),
                )
            )
        ).all()
    )
    children: dict[int, list[int]] = {}
    for dept in departments:
        if dept.parent_id:
            children.setdefault(dept.parent_id, []).append(dept.id)
    subtree: set[int] = set(dept_ids)
    queue_ids: deque[int] = deque(dept_ids)
    while queue_ids:
        current = queue_ids.popleft()
        for child in children.get(current, []):
            if child not in subtree:
                subtree.add(child)
                queue_ids.append(child)

    data_scope = merge_data_scope(role.data_scope for role in direct_roles)
    # 字段解密属于高敏能力：只读审计员不应拿到明文，仅规则维护者与超管可见。
    may_decrypt = is_super_admin or bool(
        permissions & {"cccms:data_rule:save", "cccms:data_rule:update"}
    )

    return UserContext(
        id=user.id,
        username=user.username,
        nickname=user.nickname,
        tenant_id=tenant_id,
        home_tenant_id=tenant_id,
        is_super_admin=is_super_admin,
        permissions=frozenset(permissions),
        role_ids=tuple(role.id for role in direct_roles),
        effective_role_ids=effective_ids,
        dept_ids=dept_ids,
        dept_subtree_ids=tuple(sorted(subtree)),
        post_ids=post_ids,
        data_scope=data_scope,
        jti="",
        token_exp=0,
        token_iat=0,
        may_decrypt_fields=may_decrypt,
    )


def has_permission(user: UserContext | None, slug: str | None) -> bool:
    if user is None:
        return False
    if not slug:
        return True
    if user.is_super_admin:
        return True
    return slug in user.permissions
