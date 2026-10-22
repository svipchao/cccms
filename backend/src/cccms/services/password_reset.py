from __future__ import annotations

import hmac
import logging
import secrets
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import Settings, get_settings
from cccms.core.cache import get_redis
from cccms.core.codes import BizCode
from cccms.core.config_store import get_bool, get_int
from cccms.core.context import current_context
from cccms.core.delivery import deliverable_channels
from cccms.core.errors import ServiceUnavailable, ValidationFailed
from cccms.core.redact import mask_email
from cccms.core.security import hash_password_async, validate_password, verify_password_async
from cccms.core.tenant import get_tenant_by_code
from cccms.core.time import now
from cccms.models.user import User
from cccms.services import captcha, mail

logger = logging.getLogger("cccms.password_reset")

# 账号不存在时返回同样的成功体，避免匿名接口被用来枚举有效账号。
_GENERIC_CODE_ERROR = "验证码错误或已过期"


def _key(tenant_id: int, user_id: int) -> str:
    return f"cccms:reset:{tenant_id}:{user_id}"


async def _resolve_tenant(session: AsyncSession, payload: dict[str, Any]) -> int:
    ctx = current_context()
    tenant = ctx.tenant if ctx else None
    if tenant is not None:
        return tenant.id
    code = str(payload.get("tenant_code") or "").strip() or get_settings().login_default_tenant
    resolved = await get_tenant_by_code(code)
    if resolved is None:
        raise ValidationFailed("登录平台不存在或已停用")
    return resolved.id


async def _find_user(session: AsyncSession, tenant_id: int, payload: dict[str, Any]) -> User | None:
    username = str(payload.get("username") or payload.get("account") or "").strip()
    if not username:
        raise ValidationFailed("请输入账号")
    user = await session.scalar(
        select(User).where(
            User.tenant_id == tenant_id,
            User.username == username,
            User.delete_time.is_(None),
        )
    )
    if user is None or user.status != 1:
        return None
    return user


async def _channels(session: AsyncSession, tenant_id: int) -> list[str]:
    configured = [
        item
        for item in ("email", "sms")
        if await get_bool(session, f"security.reset_{item}_enabled", False, tenant_id=tenant_id)
    ]
    # 开关 ≠ 能发出：prod 没有投递实现时这里返回空，与登录页入口的判定同源。
    return deliverable_channels(configured)


async def request_code(session: AsyncSession, payload: dict[str, Any]) -> dict[str, Any]:
    tenant_id = await _resolve_tenant(session, payload)
    captcha_enabled = await get_bool(
        session, "security.login_captcha", False, tenant_id=tenant_id
    )
    if captcha_enabled and not await captcha.verify(
        payload.get("captcha_id"), payload.get("captcha")
    ):
        raise ValidationFailed(
            "验证码错误或已过期",
            code=BizCode.CAPTCHA_INVALID,
            message_key="error.captcha_invalid",
        )

    channels = await _channels(session, tenant_id)
    if not channels:
        raise ServiceUnavailable("未配置找回密码渠道")
    channel = str(payload.get("channel") or channels[0])
    if channel not in channels:
        raise ValidationFailed("不支持的找回渠道")

    ttl = await get_int(session, "security.reset_code_ttl", 300, tenant_id=tenant_id)
    interval = await get_int(session, "security.reset_send_interval", 60, tenant_id=tenant_id)
    user = await _find_user(session, tenant_id, payload)
    if user is None:
        # 返回与真实发送相同的成功外壳，不透露账号是否存在
        # （真实发信更慢，响应时间仍可作旁路，见 docs/15 §6.1）。
        return {"channel": channel, "expires_in": ttl, "username": ""}

    target = user.email if channel == "email" else user.phone
    if not str(target or "").strip():
        # 账号存在但没绑邮箱/手机：同样返回成功外壳，否则就成了"账号是否绑定"的探测器。
        return {"channel": channel, "expires_in": ttl, "username": ""}

    key = _key(tenant_id, user.id)
    code = f"{secrets.randbelow(1_000_000):06d}"
    try:
        client = get_redis()
        # 冷却键先占位再发信：并发请求里只有一次会真正投递，
        # 而发不出去时立刻释放，失败的人不会被困在冷却期里。
        if not await client.set(f"{key}:interval", "1", ex=interval, nx=True):
            raise ValidationFailed(f"发送过于频繁，请 {interval} 秒后再试")
    except ValidationFailed:
        raise
    except Exception as exc:
        raise ServiceUnavailable("验证码服务不可用") from exc

    try:
        await _deliver(get_settings(), channel=channel, user=user, code=code, ttl=ttl)
    except ServiceUnavailable:
        await _release_send_slot(key)
        raise
    except Exception as exc:
        await _release_send_slot(key)
        logger.warning("找回密码验证码投递异常 user=%s", user.id, exc_info=True)
        raise ServiceUnavailable("验证码服务不可用") from exc

    try:
        # 只有投递成功后才落码：失败路径上 Redis 里不该存在用户看不到的验证码。
        await client.set(key, code, ex=ttl)
    except Exception as exc:
        await _release_send_slot(key)
        raise ServiceUnavailable("验证码服务不可用") from exc
    return {"channel": channel, "expires_in": ttl, "username": user.username}


async def _release_send_slot(key: str) -> None:
    """释放发送占位，让用户可以立刻重试；释放失败只留痕，不掩盖原始错误。"""
    try:
        await get_redis().delete(f"{key}:interval")
    except Exception:
        logger.warning("释放密码重置发送占位失败", exc_info=True)


async def _deliver(
    settings: Settings, *, channel: str, user: User, code: str, ttl: int
) -> None:
    """按渠道投递验证码。

    邮件走 SMTP；短信尚无投递实现，dev 把验证码打到日志便于联调
    （prod 下 ``deliverable_channels`` 已把 sms 过滤掉，走不到这里）。
    """
    if channel == "email" and settings.smtp_ready:
        minutes = max(1, ttl // 60)
        await mail.send_mail(
            to=str(user.email or ""),
            subject="找回密码验证码",
            text=(
                f"您好 {user.nickname or user.username}：\n\n"
                f"本次找回密码的验证码为 {code}，{minutes} 分钟内有效。\n"
                "如非本人操作，请忽略本邮件，并尽快检查账号安全。\n"
            ),
            settings=settings,
        )
        logger.info("password reset code sent by email to %s", mask_email(user.email))
        return
    logger.warning("password reset code for %s on channel %s: %s", user.username, channel, code)


async def reset_password(session: AsyncSession, payload: dict[str, Any]) -> None:
    tenant_id = await _resolve_tenant(session, payload)
    user = await _find_user(session, tenant_id, payload)
    code = str(payload.get("code") or "").strip()
    if user is None or not code:
        raise ValidationFailed(_GENERIC_CODE_ERROR)

    key = _key(tenant_id, user.id)
    try:
        client = get_redis()
        # GETDEL 原子消费，避免同一验证码被并发重放。
        expected = await client.getdel(key)
        attempts = await client.incr(f"{key}:attempts")
        await client.expire(f"{key}:attempts", 3600)
    except Exception as exc:
        raise ServiceUnavailable("验证码服务不可用") from exc
    if expected is None or not hmac.compare_digest(str(expected), code) or attempts > 5:
        raise ValidationFailed(_GENERIC_CODE_ERROR, code=BizCode.CAPTCHA_INVALID)

    password = str(payload.get("password") or "")
    validate_password(password, username=user.username, nickname=user.nickname, email=user.email or "")
    if await verify_password_async(password, user.password_hash):
        raise ValidationFailed("新密码不能与原密码相同")
    user.password_hash = await hash_password_async(password)
    user.password_changed_at = now()
    await session.commit()
    # 改密后立即作废该用户全部令牌与在线会话（与后台改密保持一致）。
    from cccms.core import session as token_session

    await token_session.revoke_user_tokens(user.id, get_settings().token_ttl)
    await token_session.remove_user_online(user.id)
    try:
        await get_redis().delete(key, f"{key}:attempts", f"{key}:interval")
    except Exception:
        # 限流键清理失败不影响改密结果, 但必须留痕以便排查 Redis 异常。
        logger.warning("清理密码重置限流键失败", exc_info=True)
