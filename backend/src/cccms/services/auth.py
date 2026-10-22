from __future__ import annotations

import time
from dataclasses import replace

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core import session as token_session
from cccms.core.codes import BizCode
from cccms.core.config_store import get_bool, get_value
from cccms.core.context import UserContext, current_context
from cccms.core.delivery import deliverable_channels
from cccms.core.errors import Forbidden, NotFound, Unauthorized, ValidationFailed
from cccms.core.i18n import DEFAULT_LOCALE, SUPPORTED_LOCALES
from cccms.core.ratelimit import check_login_allowed, clear_login_failures, record_login_failure
from cccms.core.rbac import load_user_context
from cccms.core.security import (
    create_access_token,
    hash_password_async,
    needs_rehash,
    new_jti,
    validate_password,
    verify_password_async,
)
from cccms.core.tenant import get_tenant, get_tenant_by_code, public_tenants
from cccms.core.time import now
from cccms.models.user import Dept, Role, User, UserDept, UserRole
from cccms.schemas.auth import (
    LoginContextOut,
    LoginOut,
    LoginRequest,
    PasswordChangeRequest,
    ProfileUpdateRequest,
    RoleBrief,
    SwitchTenantRequest,
    TokenOut,
    UserProfile,
)
from cccms.services import captcha
from cccms.services.password_history import ensure_not_reused, remember

SINGLE_MESSAGE = "用户名或密码错误"


async def _roles_of(session: AsyncSession, user: User) -> list[RoleBrief]:
    rows = (
        await session.scalars(
            select(Role)
            .join(UserRole, UserRole.role_id == Role.id)
            .where(
                UserRole.user_id == user.id,
                Role.status == 1,
                Role.delete_time.is_(None),
            )
            .order_by(Role.sort.asc(), Role.id.asc())
        )
    ).all()
    return [RoleBrief(id=role.id, code=role.code, name=role.name) for role in rows]


async def build_profile(session: AsyncSession, user: UserContext) -> UserProfile:
    row = await session.get(User, user.id)
    if row is None:
        raise NotFound("用户不存在")
    depts = list(
        (
            await session.scalars(
                select(Dept.name)
                .join(UserDept, UserDept.dept_id == Dept.id)
                .where(UserDept.user_id == row.id, Dept.delete_time.is_(None))
                .order_by(Dept.sort.asc(), Dept.id.asc())
            )
        ).all()
    )
    return UserProfile(
        id=row.id,
        username=row.username,
        nickname=row.nickname,
        avatar=row.avatar,
        email=row.email,
        phone=row.phone,
        status=row.status,
        remark=row.remark,
        super_admin=user.is_super_admin,
        roles=await _roles_of(session, row),
        depts=[str(name) for name in depts],
        permissions=sorted(user.permissions),
        tenant_id=user.tenant_id,
        home_tenant_id=user.home_tenant_id,
        tenant_code="",
        tenant_name="",
        tenant_switched=user.tenant_id != user.home_tenant_id,
        last_login_at=row.last_login_at,
        login_time=row.last_login_at,
        login_ip=row.last_login_ip or "",
        create_time=row.create_time,
    )


async def login(session: AsyncSession, payload: LoginRequest) -> LoginOut:
    ctx = current_context()
    if ctx is None:
        raise ValidationFailed("请求上下文缺失")
    settings = get_settings()

    tenant = ctx.tenant
    if tenant is None:
        code = (payload.tenant_code or "").strip() or settings.login_default_tenant
        tenant = await get_tenant_by_code(code)
        if tenant is None:
            raise ValidationFailed("登录平台不存在或已停用")
        ctx.tenant = tenant
    elif payload.tenant_code and payload.tenant_code.strip().lower() != tenant.code:
        raise ValidationFailed("当前域名已绑定登录平台，请勿重复选择")

    await check_login_allowed(tenant.id, payload.username, ctx.ip)
    captcha_enabled = await get_bool(session, "security.login_captcha", False, tenant_id=tenant.id)
    if captcha_enabled and not await captcha.verify(payload.captcha_id, payload.captcha):
        raise ValidationFailed(
            "验证码错误或已过期",
            code=BizCode.CAPTCHA_INVALID,
            message_key="error.captcha_invalid",
        )

    user = await session.scalar(
        select(User).where(
            User.tenant_id == tenant.id,
            User.username == payload.username,
            User.delete_time.is_(None),
        )
    )
    if user is None:
        await record_login_failure(tenant.id, payload.username, ctx.ip)
        raise Unauthorized(SINGLE_MESSAGE, code=BizCode.LOGIN_FAILED, message_key="error.login_failed")
    if user.status != 1:
        await record_login_failure(tenant.id, payload.username, ctx.ip)
        raise Forbidden(
            "账号已被停用", code=BizCode.ACCOUNT_DISABLED, message_key="error.account_disabled"
        )

    if not await verify_password_async(payload.password, user.password_hash):
        await record_login_failure(tenant.id, payload.username, ctx.ip)
        raise Unauthorized(SINGLE_MESSAGE, code=BizCode.LOGIN_FAILED, message_key="error.login_failed")
    if needs_rehash(user.password_hash):
        # Argon2 参数升级后，老哈希在用户下次登录时自动迁移。
        user.password_hash = await hash_password_async(payload.password)

    context = await load_user_context(session, user_id=user.id, tenant_id=tenant.id)
    if context is None:
        raise Forbidden("账号未分配角色或角色已停用")

    if (
        await get_bool(session, "system.maintenance", False, tenant_id=tenant.id)
        and not context.is_super_admin
    ):
        raise Forbidden(
            await get_value(
                session, "system.maintenance_notice", "系统维护中，请稍后访问", tenant_id=tenant.id
            )
        )

    await clear_login_failures(tenant.id, payload.username, ctx.ip)

    jti = new_jti()
    token, claims = create_access_token(
        user_id=user.id,
        tenant_id=tenant.id,
        home_tenant_id=tenant.id,
        jti=jti,
        ttl=settings.token_ttl,
        iat=int(time.time()),
    )
    user.last_login_at = now()
    user.last_login_ip = ctx.ip
    await session.commit()

    context = replace(context, jti=jti, token_exp=int(claims["exp"]), token_iat=int(claims["iat"]))
    ctx.extra["set_token"] = token
    # 登录请求是匿名的，ctx.user 仍为空：把账号回填给审计日志，登录分析才能统计到人
    ctx.extra["log_user_id"] = user.id
    await token_session.register_online(
        user_id=user.id,
        tenant_id=tenant.id,
        username=user.username,
        nickname=user.nickname,
        jti=jti,
        ip=ctx.ip,
        ua=ctx.ua,
        exp=int(claims["exp"]),
    )
    profile = await build_profile(session, context)
    profile.tenant_code = tenant.code
    profile.tenant_name = tenant.name
    return LoginOut(
        token=TokenOut(
            token=token,
            expires_in=int(claims["exp"]) - int(claims["iat"]),
            expires_at=int(claims["exp"]),
        ),
        user=profile,
    )


async def logout(session: AsyncSession) -> None:
    ctx = current_context()
    if ctx is None or ctx.user is None:
        return
    remaining = max(0, ctx.user.token_exp - int(time.time()))
    await token_session.revoke_token(ctx.user.jti, remaining)
    await token_session.remove_online(ctx.user.jti)
    ctx.extra["clear_token"] = True
    _ = session


async def me(session: AsyncSession, user: UserContext) -> UserProfile:
    profile = await build_profile(session, user)
    tenant = await get_tenant(user.tenant_id)
    if tenant is not None:
        profile.tenant_code = tenant.code
        profile.tenant_name = tenant.name
    return profile


async def login_context(session: AsyncSession) -> LoginContextOut:
    ctx = current_context()
    tenant = ctx.tenant if ctx else None
    tenants = await public_tenants()
    maintenance = await get_bool(
        session, "system.maintenance", False, tenant_id=tenant.id if tenant else None
    )
    notice = await get_value(
        session,
        "system.maintenance_notice",
        "系统维护中，请稍后访问",
        tenant_id=tenant.id if tenant else None,
    )
    captcha_enabled = await get_bool(
        session, "security.login_captcha", False, tenant_id=tenant.id if tenant else None
    )
    reset_channels = deliverable_channels(
        [
            item
            for item in ("email", "sms")
            if await get_bool(
                session, f"security.reset_{item}_enabled", False, tenant_id=tenant.id if tenant else None
            )
        ]
    )
    from cccms.schemas.auth import TenantOption

    return LoginContextOut(
        tenant_locked=bool(tenant),
        tenant=TenantOption(id=tenant.id, code=tenant.code, name=tenant.name) if tenant else None,
        tenants=[]
        if tenant
        else [
            TenantOption(id=item["id"], code=str(item["code"]), name=str(item["name"])) for item in tenants
        ],
        captcha_enabled=captcha_enabled,
        maintenance=maintenance,
        maintenance_notice=notice,
        reset_channels=reset_channels,
        locale=DEFAULT_LOCALE,
        locales=list(SUPPORTED_LOCALES),
    )


async def switch_tenant(session: AsyncSession, user: UserContext, payload: SwitchTenantRequest) -> LoginOut:
    if not user.is_super_admin:
        raise Forbidden("仅超级管理员可切换租户")
    target = await get_tenant(payload.tenant_id)
    if target is None:
        raise ValidationFailed("目标租户不存在或已停用")
    settings = get_settings()
    jti = new_jti()
    token, claims = create_access_token(
        user_id=user.id,
        tenant_id=target.id,
        home_tenant_id=user.home_tenant_id,
        jti=jti,
        ttl=settings.token_ttl,
        iat=int(time.time()),
    )
    ctx = current_context()
    if ctx is not None:
        ctx.extra["set_token"] = token
        ctx.tenant = target
    await token_session.rotate_online(user.jti, jti, int(claims["exp"]))
    switched = replace(
        user, tenant_id=target.id, jti=jti, token_exp=int(claims["exp"]), token_iat=int(claims["iat"])
    )
    if ctx is not None:
        ctx.user = switched
    profile = await build_profile(session, switched)
    profile.tenant_code = target.code
    profile.tenant_name = target.name
    return LoginOut(
        token=TokenOut(token=token, expires_in=settings.token_ttl, expires_at=int(claims["exp"])),
        user=profile,
    )


async def update_profile(
    session: AsyncSession, user: UserContext, payload: ProfileUpdateRequest
) -> UserProfile:
    row = await session.get(User, user.id)
    if row is None:
        raise NotFound("用户不存在")
    data = payload.model_dump(exclude_unset=True)
    for key in ("nickname", "avatar", "email", "phone"):
        if key in data:
            setattr(row, key, data[key])
    await session.commit()
    return await me(session, user)


async def change_password(session: AsyncSession, user: UserContext, payload: PasswordChangeRequest) -> None:
    if payload.confirm_password is not None and payload.new_password != payload.confirm_password:
        raise ValidationFailed("两次输入的新密码不一致")
    row = await session.get(User, user.id)
    if row is None:
        raise NotFound("用户不存在")
    if not await verify_password_async(payload.old_password, row.password_hash):
        raise ValidationFailed("原密码错误")
    if await verify_password_async(payload.new_password, row.password_hash):
        raise ValidationFailed("新密码不能与原密码相同")
    validate_password(
        payload.new_password, username=row.username, nickname=row.nickname, email=row.email or ""
    )
    await ensure_not_reused(
        session, tenant_id=row.tenant_id, user_id=row.id, password=payload.new_password
    )
    previous_hash = row.password_hash
    row.password_hash = await hash_password_async(payload.new_password)
    row.password_changed_at = now()
    await remember(session, tenant_id=row.tenant_id, user_id=row.id, password_hash=previous_hash)
    await session.commit()
    await token_session.revoke_user_tokens(user.id, get_settings().token_ttl)
    await token_session.remove_user_online(user.id)


async def list_sessions(user: UserContext) -> list[dict[str, object]]:
    items = await token_session.online_sessions(user.tenant_id)
    return [item for item in items if int(item.get("user_id", 0)) == user.id]


async def revoke_session(user: UserContext, jti: str) -> None:
    if jti == user.jti:
        raise ValidationFailed("不能注销当前会话")
    sessions = await token_session.online_sessions()
    target = next(
        (item for item in sessions if item.get("jti") == jti and int(item.get("user_id", 0)) == user.id), None
    )
    if target is None:
        raise NotFound("会话不存在")
    exp = int(target.get("exp", 0) or 0)
    await token_session.revoke_token(jti, max(0, exp - int(time.time())))
    await token_session.remove_online(jti)
