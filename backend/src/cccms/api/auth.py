from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext, current_context
from cccms.core.envelope import ApiResponse, ok
from cccms.core.permissions import make_router, no_auth, no_login
from cccms.core.ratelimit import enforce_anon_rate_limit
from cccms.db.session import get_session
from cccms.schemas.auth import (
    LoginContextOut,
    LoginOut,
    LoginRequest,
    ResetPasswordRequest,
    SendResetCodeRequest,
    SwitchTenantRequest,
    UserProfile,
)
from cccms.services import auth as auth_service
from cccms.services import captcha

router = make_router(prefix="/auth", tags=["auth"])


async def _anon_throttle(bucket: str, limit: int | None = None) -> None:
    """匿名接口按 IP + 用途分桶限流；没有身份可依附，只能靠这里兜底。"""
    ctx = current_context()
    await enforce_anon_rate_limit(bucket, ctx.ip if ctx else "unknown", limit=limit)


@router.get("/login-context", response_model=ApiResponse[LoginContextOut])
@no_login("登录上下文")
async def login_context(session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    return ok(await auth_service.login_context(session))


@router.get("/captcha", response_model=ApiResponse[dict[str, str] | None])
@no_login("图形验证码")
async def get_captcha(session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    from cccms.core.config_store import get_bool

    await _anon_throttle("captcha")
    ctx = current_context()
    tenant_id = ctx.tenant.id if ctx and ctx.tenant else None
    if not await get_bool(session, "security.login_captcha", False, tenant_id=tenant_id):
        return ok(None)
    return ok(await captcha.create())


@router.post("/login", response_model=ApiResponse[LoginOut])
@no_login("登录")
async def login(payload: LoginRequest, session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    await _anon_throttle("login", get_settings().ratelimit_anon_per_min)
    return ok(await auth_service.login(session, payload))


@router.post("/logout", response_model=ApiResponse[None])
@no_auth("退出登录")
async def logout(session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    await auth_service.logout(session)
    return ok(None)


@router.get("/me", response_model=ApiResponse[UserProfile])
@no_auth("当前用户")
async def me(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await auth_service.me(session, user))


@router.get("/tenants", response_model=ApiResponse[list[dict[str, object]]])
@no_auth("可选租户")
async def tenants(
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    from cccms.core.tenant import get_tenant, public_tenants

    if user.is_super_admin:
        rows = await public_tenants()
    else:
        current = await get_tenant(user.tenant_id)
        rows = [{"id": current.id, "code": current.code, "name": current.name}] if current else []
    return ok(rows)


@router.post("/switchTenant", response_model=ApiResponse[LoginOut])
@no_auth("切换租户")
async def switch_tenant(
    payload: SwitchTenantRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await auth_service.switch_tenant(session, user, payload))


@router.post("/password/sendCode", response_model=ApiResponse[dict[str, object]])
@no_login("发送找回密码验证码")
async def send_reset_code(
    payload: SendResetCodeRequest, session: AsyncSession = Depends(get_session)
) -> dict[str, object]:
    from cccms.services.password_reset import request_code

    await _anon_throttle("password:sendCode", get_settings().ratelimit_anon_write_per_min)
    return ok(await request_code(session, payload.model_dump()))


@router.post("/password/reset", response_model=ApiResponse[None])
@no_login("找回密码")
async def reset_password(
    payload: ResetPasswordRequest, session: AsyncSession = Depends(get_session)
) -> dict[str, object]:
    from cccms.services.password_reset import reset_password

    await _anon_throttle("password:reset", get_settings().ratelimit_anon_write_per_min)
    await reset_password(session, payload.model_dump())
    return ok(None)
