from __future__ import annotations

import logging
import time
from dataclasses import replace

from fastapi import Depends, Request
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core import session as token_session
from cccms.core.cache import Keys, get_redis
from cccms.core.config_store import get_bool
from cccms.core.context import UserContext, current_context
from cccms.core.errors import Unauthorized
from cccms.core.ratelimit import enforce_api_rate_limit, is_heavy_path
from cccms.core.rbac import load_user_context
from cccms.core.scope import build_plan
from cccms.core.security import create_access_token, decode_access_token, new_jti, should_renew
from cccms.core.tenant import get_tenant
from cccms.db.session import get_session

logger = logging.getLogger("cccms.auth")


async def load_user(
    request: Request,
    session: AsyncSession = Depends(get_session),
) -> None:
    ctx = current_context()
    if ctx is None:
        return
    settings = get_settings()
    token = request.cookies.get(settings.token_cookie_name)
    if not token:
        # SPA 默认把令牌放在 Authorization 里；Cookie 作为同域部署的兼容方式。
        authorization = request.headers.get("authorization", "")
        if authorization.lower().startswith("bearer "):
            token = authorization[7:].strip()
    if not token:
        ctx.auth_error = "未登录"
        return

    try:
        payload = decode_access_token(token)
    except Unauthorized as exc:
        ctx.auth_error = exc.message
        return

    if await token_session.is_token_revoked(payload.jti):
        ctx.auth_error = "登录状态已失效，请重新登录"
        return
    if await token_session.is_stale(user_id=payload.user_id, iat=payload.iat):
        ctx.auth_error = "登录状态已失效，请重新登录"
        return

    tenant = ctx.tenant
    if tenant is None:
        tenant = await get_tenant(payload.tenant_id)
        if tenant is None:
            ctx.auth_error = "租户不存在或已停用"
            return
        ctx.tenant = tenant
    elif tenant.id != payload.tenant_id:
        ctx.auth_error = "当前域名与登录租户不匹配"
        return

    user = await load_user_context(session, user_id=payload.user_id, tenant_id=payload.home_tenant_id)
    if user is None:
        ctx.auth_error = "登录凭证无效或用户已失效"
        return
    if tenant.id != payload.home_tenant_id and not user.is_super_admin:
        ctx.auth_error = "无权访问当前租户"
        return
    user = replace(
        user,
        tenant_id=tenant.id,
        home_tenant_id=payload.home_tenant_id,
        jti=payload.jti,
        token_exp=payload.exp,
        token_iat=payload.iat,
    )

    if await get_bool(session, "system.maintenance", False, tenant_id=tenant.id) and not user.is_super_admin:
        notice = await __import__("cccms.core.config_store", fromlist=["get_value"]).get_value(
            session, "system.maintenance_notice", "系统维护中，请稍后访问", tenant_id=tenant.id
        )
        raise Unauthorized(notice or "系统维护中，请稍后访问")

    ctx.user = user
    ctx.scope_plan = await build_plan(session, user)

    await token_session.touch_online(payload.jti)
    await token_session.register_online(
        user_id=user.id,
        tenant_id=tenant.id,
        username=user.username,
        nickname=user.nickname,
        jti=payload.jti,
        ip=ctx.ip,
        ua=ctx.ua,
        exp=payload.exp,
    )
    await enforce_api_rate_limit(user.id, request.url.path, heavy=is_heavy_path(request.url.path))

    if should_renew(payload):
        jti = new_jti()
        new_token, new_payload = create_access_token(
            user_id=user.id,
            tenant_id=tenant.id,
            home_tenant_id=user.home_tenant_id,
            jti=jti,
            ttl=settings.token_ttl,
            iat=int(time.time()),
        )
        ctx.extra["new_token"] = new_token
        ctx.extra["new_token_exp"] = int(new_payload["exp"])
        # SPA 从响应头静默续期（见 frontend/src/api/request.ts）
        ctx.response_headers["X-Refresh-Token"] = new_token
        await token_session.rotate_online(payload.jti, jti, int(new_payload["exp"]))


async def get_current_user() -> UserContext:
    ctx = current_context()
    if ctx is None or ctx.user is None:
        raise Unauthorized((ctx.auth_error if ctx else None) or "未登录")
    return ctx.user


async def optional_user() -> UserContext | None:
    ctx = current_context()
    return ctx.user if ctx else None


async def clear_online_for(jti: str) -> None:
    # 在线会话是尽力而为的旁路数据, Redis 异常不得影响登出主流程；
    # 但"强制下线看起来成功、在线表却残留"必须留痕，否则排查时无从下手。
    try:
        await get_redis().delete(Keys.online_session(jti))
        await get_redis().zrem(Keys.ONLINE_INDEX, jti)
    except Exception:
        logger.warning("clear_online_for failed jti=%s", jti, exc_info=True)
