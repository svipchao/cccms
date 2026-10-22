from __future__ import annotations

from collections import defaultdict
from typing import Any

from sqlalchemy import ColumnElement, Select, delete, func, or_, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.codes import BizCode
from cccms.core.context import UserContext
from cccms.core.errors import Conflict, Forbidden, NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.scope import ScopeConfig, apply_field_actions, apply_scope, blocked_write_fields
from cccms.core.security import hash_password_async, validate_password
from cccms.core.time import now, parse
from cccms.models.tenant import Tenant
from cccms.models.user import Dept, Post, Role, RoleNode, User, UserDept, UserPost, UserRole
from cccms.schemas.auth import RoleBrief
from cccms.schemas.user import (
    BatchAssignRequest,
    BatchResult,
    BatchStatusRequest,
    PasswordReset,
    UserCreate,
    UserOut,
    UserQuery,
    UserUpdate,
)

SUPER_ADMIN_CODE = "super_admin"


def _user_owner(model: type[User], user: UserContext) -> ColumnElement[bool]:
    return model.id == user.id


def _user_dept(model: type[User], dept_ids: tuple[int, ...]) -> ColumnElement[bool]:
    return model.id.in_(select(UserDept.user_id).where(UserDept.dept_id.in_(dept_ids)))


User.__data_scope__ = ScopeConfig(owner=_user_owner, dept=_user_dept)

SORTABLE = {
    "id": User.id,
    "username": User.username,
    "nickname": User.nickname,
    "status": User.status,
    "create_time": User.create_time,
    "last_login_at": User.last_login_at,
}


def _base_query(user: UserContext, params: UserQuery) -> Select[Any]:
    statement = apply_scope(select(User), User, include_deleted=params.trashed == 1)
    if params.trashed == 1:
        statement = statement.where(User.delete_time.is_not(None))
    if params.status is not None:
        statement = statement.where(User.status == params.status)
    if params.username:
        statement = statement.where(User.username.like(f"%{params.username.strip()}%"))
    if params.nickname:
        statement = statement.where(User.nickname.like(f"%{params.nickname.strip()}%"))
    if params.keyword:
        keyword = f"%{params.keyword.strip()}%"
        statement = statement.where(
            or_(
                User.username.like(keyword),
                User.nickname.like(keyword),
                User.email.like(keyword),
                User.phone.like(keyword),
            )
        )
    if params.dept_id:
        statement = statement.where(
            User.id.in_(select(UserDept.user_id).where(UserDept.dept_id == params.dept_id))
        )
    if params.post_id:
        statement = statement.where(
            User.id.in_(select(UserPost.user_id).where(UserPost.post_id == params.post_id))
        )
    start = parse(params.start)
    end = parse(params.end)
    if start:
        statement = statement.where(User.create_time >= start)
    if end:
        statement = statement.where(User.create_time <= end)
    return statement


async def _relations(
    session: AsyncSession, user_ids: list[int]
) -> tuple[dict[int, list[RoleBrief]], dict[int, list[int]], dict[int, list[int]], set[int]]:
    roles: dict[int, list[RoleBrief]] = defaultdict(list)
    depts: dict[int, list[int]] = defaultdict(list)
    posts: dict[int, list[int]] = defaultdict(list)
    super_ids: set[int] = set()
    if not user_ids:
        return roles, depts, posts, super_ids
    role_rows = await session.execute(
        select(UserRole.user_id, Role.id, Role.code, Role.name)
        .join(Role, Role.id == UserRole.role_id)
        .where(UserRole.user_id.in_(user_ids), Role.delete_time.is_(None))
        .order_by(Role.sort.asc(), Role.id.asc())
    )
    for user_id, role_id, code, name in role_rows.all():
        roles[int(user_id)].append(RoleBrief(id=int(role_id), code=str(code), name=str(name)))
        if code == "super_admin":
            super_ids.add(int(user_id))
    for user_id, dept_id in (
        await session.execute(
            select(UserDept.user_id, UserDept.dept_id).where(UserDept.user_id.in_(user_ids))
        )
    ).all():
        depts[int(user_id)].append(int(dept_id))
    for user_id, post_id in (
        await session.execute(
            select(UserPost.user_id, UserPost.post_id).where(UserPost.user_id.in_(user_ids))
        )
    ).all():
        posts[int(user_id)].append(int(post_id))
    return roles, depts, posts, super_ids


def _to_out(
    row: User,
    *,
    roles: dict[int, list[RoleBrief]],
    depts: dict[int, list[int]],
    posts: dict[int, list[int]],
    super_ids: set[int],
) -> UserOut:
    out: UserOut = apply_field_actions(
        User,
        UserOut(
            id=row.id,
            tenant_id=row.tenant_id,
            username=row.username,
            nickname=row.nickname,
            avatar=row.avatar,
            email=row.email,
            phone=row.phone,
            status=row.status,
            super_admin=row.id in super_ids,
            roles=roles.get(row.id, []),
            role_ids=[item.id for item in roles.get(row.id, [])],
            dept_ids=depts.get(row.id, []),
            post_ids=posts.get(row.id, []),
            last_login_at=row.last_login_at,
            login_time=row.last_login_at,
            create_time=row.create_time,
            update_time=row.update_time,
        ),
    )
    return out


async def list_users(
    session: AsyncSession, user: UserContext, params: UserQuery
) -> tuple[int, list[UserOut]]:
    statement = _base_query(user, params)
    total = int(await session.scalar(count_query(statement)) or 0)
    column = SORTABLE.get(params.sort or "", User.id)
    ordering = column.desc() if params.order == "desc" else column.asc()
    rows = list(
        (
            await session.scalars(
                statement.order_by(ordering, User.id.asc())
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    roles, depts, posts, super_ids = await _relations(session, [row.id for row in rows])
    return total, [_to_out(row, roles=roles, depts=depts, posts=posts, super_ids=super_ids) for row in rows]


async def get_visible_user(
    session: AsyncSession,
    user: UserContext,
    user_id: int,
    *,
    include_deleted: bool = False,
) -> User:
    statement = apply_scope(select(User), User, include_deleted=include_deleted)
    if not include_deleted:
        statement = statement.where(User.delete_time.is_(None))
    row = await session.scalar(statement.where(User.id == user_id))
    if row is None:
        raise NotFound("用户不存在")
    return row


async def read_user(session: AsyncSession, user: UserContext, user_id: int) -> UserOut:
    row = await get_visible_user(session, user, user_id, include_deleted=True)
    roles, depts, posts, super_ids = await _relations(session, [row.id])
    return _to_out(row, roles=roles, depts=depts, posts=posts, super_ids=super_ids)


async def _exists_username(
    session: AsyncSession, tenant_id: int, username: str, exclude_id: int | None = None
) -> bool:
    # 唯一键含 active_flag：已软删除的行不再占用用户名/邮箱。
    statement = select(User.id).where(
        User.tenant_id == tenant_id,
        User.username == username,
        User.delete_time.is_(None),
    )
    if exclude_id:
        statement = statement.where(User.id != exclude_id)
    return bool(await session.scalar(statement.limit(1)))


async def _owned_ids(
    session: AsyncSession,
    model: Any,
    ids: list[int],
    tenant_id: int,
    *,
    allow_super_admin_role: bool = False,
) -> list[int]:
    """只保留属于当前租户且未删除的关联 id，避免跨租户绑定。"""
    if not ids:
        return []
    statement = select(model.id).where(
        model.id.in_(ids), model.tenant_id == tenant_id, model.delete_time.is_(None)
    )
    if model is Role and not allow_super_admin_role:
        # 只有超管本人可以授予 super_admin，避免租户内提权。
        statement = statement.where(Role.code != SUPER_ADMIN_CODE)
    rows = await session.scalars(statement)
    wanted = set(ids)
    return [item for item in dict.fromkeys(rows.all()) if item in wanted]


DENIED = "不能授予超出自身权限范围的权限"


async def _assert_role_delegation(
    session: AsyncSession, actor: UserContext, role_ids: list[int]
) -> None:
    """给用户分配角色时不允许授出超出操作者自身的权限与数据范围。

    角色维护入口有 ``_assert_node_delegation``，但“给人挂角色”同样是一次授权，
    缺了它，只有 ``user:update`` 的账号就能把自己提升到本租户最强角色。
    """
    if not role_ids or actor.is_super_admin:
        return
    roles = (await session.scalars(select(Role).where(Role.id.in_(role_ids)))).all()
    if any(role.code == SUPER_ADMIN_CODE for role in roles):
        raise Forbidden(DENIED, code=BizCode.DELEGATION_DENIED, message_key="error.delegation_denied")
    wider = sorted(role.code for role in roles if role.data_scope < actor.data_scope)
    if wider:
        raise Forbidden(
            f"不能授予比自身更宽的数据范围：{', '.join(wider[:5])}",
            code=BizCode.DELEGATION_DENIED,
            message_key="error.delegation_denied",
        )
    nodes = set(
        (await session.scalars(select(RoleNode.node).where(RoleNode.role_id.in_(role_ids)))).all()
    )
    extra = sorted(nodes - actor.permissions)
    if extra:
        raise Forbidden(
            f"不能授予自己未拥有的权限：{', '.join(extra[:5])}",
            code=BizCode.DELEGATION_DENIED,
            message_key="error.delegation_denied",
        )


async def _replace_relations(
    session: AsyncSession,
    actor: UserContext,
    user_id: int,
    *,
    role_ids: list[int] | None = None,
    dept_ids: list[int] | None = None,
    post_ids: list[int] | None = None,
) -> None:
    tenant_id = actor.tenant_id
    if role_ids is not None:
        owned_roles = await _owned_ids(
            session,
            Role,
            role_ids,
            tenant_id,
            allow_super_admin_role=actor.is_super_admin,
        )
        await _assert_role_delegation(session, actor, owned_roles)
        await session.execute(delete(UserRole).where(UserRole.user_id == user_id))
        for role_id in owned_roles:
            session.add(UserRole(tenant_id=tenant_id, user_id=user_id, role_id=role_id))
    if dept_ids is not None:
        await session.execute(delete(UserDept).where(UserDept.user_id == user_id))
        for dept_id in await _owned_ids(session, Dept, dept_ids, tenant_id):
            session.add(UserDept(tenant_id=tenant_id, user_id=user_id, dept_id=dept_id))
    if post_ids is not None:
        await session.execute(delete(UserPost).where(UserPost.user_id == user_id))
        for post_id in await _owned_ids(session, Post, post_ids, tenant_id):
            session.add(UserPost(tenant_id=tenant_id, user_id=user_id, post_id=post_id))


async def create_user(session: AsyncSession, actor: UserContext, payload: UserCreate) -> UserOut:
    if await _exists_username(session, actor.tenant_id, payload.username):
        raise Conflict("用户名已存在")
    tenant = await session.get(Tenant, actor.tenant_id)
    if tenant is not None and tenant.max_users > 0 and not tenant.is_platform:
        used = int(
            await session.scalar(
                select(func.count(User.id)).where(
                    User.tenant_id == actor.tenant_id,
                    User.delete_time.is_(None),
                )
            )
            or 0
        )
        if used >= tenant.max_users:
            raise Conflict(f"租户用户数已达上限（{tenant.max_users}）")
    validate_password(
        payload.password, username=payload.username, nickname=payload.nickname, email=payload.email or ""
    )
    row = User(
        tenant_id=actor.tenant_id,
        username=payload.username,
        password_hash=await hash_password_async(payload.password),
        nickname=payload.nickname,
        avatar=payload.avatar,
        email=payload.email,
        phone=payload.phone,
        status=payload.status,
        remark=payload.remark,
        password_changed_at=now(),
    )
    session.add(row)
    try:
        async with session.begin_nested():
            await session.flush()
    except IntegrityError as exc:
        # 并发创建同名账号时唯一键会先于业务校验生效，这里降级为可读的冲突提示。
        raise Conflict("用户名已存在") from exc
    await _replace_relations(
        session,
        actor,
        row.id,
        role_ids=payload.role_ids,
        dept_ids=payload.dept_ids,
        post_ids=payload.post_ids,
    )
    await session.commit()
    return await read_user(session, actor, row.id)


async def update_user(
    session: AsyncSession, actor: UserContext, user_id: int, payload: UserUpdate
) -> UserOut:
    row = await get_visible_user(session, actor, user_id)
    await _guard_super_admin_target(session, actor, row.id)
    if row.id == actor.id and payload.status == 0:
        raise ValidationFailed("不能停用自己")
    data = payload.model_dump(exclude_unset=True)
    blocked = blocked_write_fields(User)
    for key in ("nickname", "avatar", "email", "phone", "status", "remark"):
        if key in data and key not in blocked:
            setattr(row, key, data[key])
    await _replace_relations(
        session,
        actor,
        row.id,
        role_ids=data.get("role_ids") if "role_ids" in data else None,
        dept_ids=data.get("dept_ids") if "dept_ids" in data else None,
        post_ids=data.get("post_ids") if "post_ids" in data else None,
    )
    row.update_time = now()
    await session.commit()
    return await read_user(session, actor, row.id)


async def _guard_super_admin_target(session: AsyncSession, actor: UserContext, user_id: int) -> None:
    if not actor.is_super_admin and await _is_super_admin_user(session, user_id):
        raise Forbidden("不能修改超级管理员账号")


async def _is_super_admin_user(session: AsyncSession, user_id: int) -> bool:
    return bool(
        await session.scalar(
            select(UserRole.id)
            .join(Role, Role.id == UserRole.role_id)
            .where(UserRole.user_id == user_id, Role.code == "super_admin")
            .limit(1)
        )
    )


async def delete_user(session: AsyncSession, actor: UserContext, user_id: int) -> None:
    row = await get_visible_user(session, actor, user_id)
    if row.id == actor.id:
        raise Forbidden("不能删除自己")
    if not actor.is_super_admin and await _is_super_admin_user(session, row.id):
        raise Forbidden("不能删除超级管理员")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def _visible_users(
    session: AsyncSession, actor: UserContext, ids: list[int]
) -> dict[int, User]:
    """一次 ``IN`` 查询取回全部可见用户，替代循环里逐条校验。

    批量操作在前端可以一次选 500 行，逐条查询会产生上千次往返并长时间占用连接。
    """
    wanted = list(dict.fromkeys(ids))
    if not wanted:
        return {}
    rows = (
        await session.scalars(apply_scope(select(User).where(User.id.in_(wanted)), User))
    ).all()
    return {row.id: row for row in rows}


async def _super_admin_ids(session: AsyncSession, ids: list[int]) -> set[int]:
    if not ids:
        return set()
    rows = await session.scalars(
        select(UserRole.user_id)
        .join(Role, Role.id == UserRole.role_id)
        .where(UserRole.user_id.in_(ids), Role.code == SUPER_ADMIN_CODE)
    )
    return {int(item) for item in rows.all()}


async def batch_status(session: AsyncSession, actor: UserContext, payload: BatchStatusRequest) -> BatchResult:
    affected = 0
    skipped: list[str] = []
    rows = await _visible_users(session, actor, payload.ids)
    protected = set() if actor.is_super_admin else await _super_admin_ids(session, list(rows))
    for user_id in dict.fromkeys(payload.ids):
        row = rows.get(user_id)
        if row is None or (row.id == actor.id and payload.status == 0) or row.id in protected:
            skipped.append(str(user_id))
            continue
        row.status = payload.status
        row.update_time = now()
        affected += 1
    await session.commit()
    return BatchResult(affected=affected, skipped=skipped)


async def batch_delete(session: AsyncSession, actor: UserContext, ids: list[int]) -> BatchResult:
    affected = 0
    skipped: list[str] = []
    rows = await _visible_users(session, actor, ids)
    protected = set() if actor.is_super_admin else await _super_admin_ids(session, list(rows))
    for user_id in dict.fromkeys(ids):
        row = rows.get(user_id)
        if row is None or row.id == actor.id or row.id in protected:
            skipped.append(str(user_id))
            continue
        row.delete_time = now()
        row.update_time = now()
        affected += 1
    await session.commit()
    return BatchResult(affected=affected, skipped=skipped)


async def _assert_assignable(
    session: AsyncSession,
    actor: UserContext,
    *,
    role_ids: list[int],
    dept_ids: list[int],
    post_ids: list[int],
) -> None:
    """批量分配先整体校验，避免“清空旧关系后才发现 id 非法”。"""
    checks = (
        (Role, role_ids, actor.is_super_admin, "角色"),
        (Dept, dept_ids, False, "部门"),
        (Post, post_ids, False, "岗位"),
    )
    for model, ids, allow_super, label in checks:
        if not ids:
            continue
        valid = await _owned_ids(
            session,
            model,
            ids,
            actor.tenant_id,
            allow_super_admin_role=allow_super,
        )
        if set(valid) != set(ids):
            raise ValidationFailed(f"{label}不存在或不属于当前租户")


async def batch_assign(session: AsyncSession, actor: UserContext, payload: BatchAssignRequest) -> BatchResult:
    if not payload.role_ids and not payload.dept_ids and not payload.post_ids:
        raise ValidationFailed("请至少选择一项分配内容")
    await _assert_assignable(
        session,
        actor,
        role_ids=payload.role_ids or [],
        dept_ids=payload.dept_ids or [],
        post_ids=payload.post_ids or [],
    )
    affected = 0
    skipped: list[str] = []
    rows = await _visible_users(session, actor, payload.ids)
    protected = set() if actor.is_super_admin else await _super_admin_ids(session, list(rows))
    for user_id in dict.fromkeys(payload.ids):
        row = rows.get(user_id)
        if row is None or row.id in protected:
            skipped.append(str(user_id))
            continue
        await _replace_relations(
            session,
            actor,
            row.id,
            role_ids=payload.role_ids,
            dept_ids=payload.dept_ids,
            post_ids=payload.post_ids,
        )
        affected += 1
    await session.commit()
    return BatchResult(affected=affected, skipped=skipped)


async def reset_password(
    session: AsyncSession, actor: UserContext, user_id: int, payload: PasswordReset
) -> None:
    row = await get_visible_user(session, actor, user_id)
    await _guard_super_admin_target(session, actor, row.id)
    validate_password(payload.password, username=row.username, nickname=row.nickname, email=row.email or "")
    row.password_hash = await hash_password_async(payload.password)
    row.password_changed_at = now()
    row.update_time = now()
    await session.commit()
    from cccms.core import session as token_session

    await token_session.revoke_user_tokens(row.id, 7200)
    await token_session.remove_user_online(row.id)
