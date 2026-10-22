from __future__ import annotations

import logging
import math
import time

from redis.exceptions import RedisError

from cccms.config import get_settings
from cccms.core.cache import Keys, get_redis
from cccms.core.errors import TooManyRequests

logger = logging.getLogger("cccms.ratelimit")

# INCR + set TTL only on first hit.  A fixed window is enough for this product;
# it is cheap, atomic and predictable.
_INCR_SCRIPT = """
local current = redis.call('INCR', KEYS[1])
if current == 1 then
  redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
"""

_HEAVY_SEGMENTS = ("/export", "/import", "/generator", "/generate", "/upgrade")


def is_heavy_path(path: str) -> bool:
    """业务路由统一挂在 ``/api`` 下，判定前必须先剥掉前缀，否则永不命中。"""
    normalized = path[4:] if path.startswith("/api/") else path
    return any(segment in normalized for segment in _HEAVY_SEGMENTS)


def _seconds_left(ttl: int) -> int:
    return max(1, int(ttl))


async def _incr(key: str, ttl: int) -> int | None:
    try:
        value = await get_redis().eval(_INCR_SCRIPT, 1, key, _seconds_left(ttl))
        return int(value)
    except RedisError:
        return None


async def check_login_allowed(tenant_id: int, username: str, ip: str) -> None:
    """Account lockout + IP throttle.  Redis outage is fail-open by design."""
    window = max(60, 15 * 60)
    user_key = Keys.login_fail_user(tenant_id, username)
    ip_key = Keys.login_fail_ip(ip)
    try:
        client = get_redis()
        user_count = int(await client.get(user_key) or 0)
        ip_count = int(await client.get(ip_key) or 0)
        user_ttl = int(await client.ttl(user_key) or window)
        ip_ttl = int(await client.ttl(ip_key) or window)
    except (RedisError, TypeError, ValueError):
        return

    if user_count >= 5:
        minutes = max(1, math.ceil(max(user_ttl, 1) / 60))
        raise TooManyRequests(f"登录失败次数过多，请 {minutes} 分钟后再试")
    if ip_count >= 20:
        minutes = max(1, math.ceil(max(ip_ttl, 1) / 60))
        raise TooManyRequests(f"登录失败次数过多，请 {minutes} 分钟后再试")


async def record_login_failure(tenant_id: int, username: str, ip: str) -> None:
    window = max(60, 15 * 60)
    await _incr(Keys.login_fail_user(tenant_id, username), window)
    await _incr(Keys.login_fail_ip(ip), window)


async def clear_login_failures(tenant_id: int, username: str, ip: str) -> None:
    try:
        await get_redis().delete(Keys.login_fail_user(tenant_id, username), Keys.login_fail_ip(ip))
    except RedisError:
        return


async def enforce_api_rate_limit(user_id: int, path: str, *, heavy: bool = False) -> None:
    settings = get_settings()
    limit = settings.ratelimit_heavy_per_min if heavy else settings.ratelimit_api_per_min
    window = 60
    key = Keys.rate_limit(user_id, path)
    count = await _incr(key, window)
    if count is None:
        return
    if count > limit:
        raise TooManyRequests(
            "请求过于频繁，请稍后再试",
            headers={
                "Retry-After": str(window),
                "X-RateLimit-Limit": str(limit),
                "X-RateLimit-Remaining": "0",
            },
        )


async def enforce_anon_rate_limit(bucket: str, ip: str, *, limit: int | None = None) -> None:
    """匿名接口（登录 / 验证码 / 找回密码）的兜底限流。

    这些接口没有身份可依附，只能按 IP + 用途分桶；Redis 不可用时仍然 fail-open，
    与全站可用性策略保持一致（反向代理侧应另配一层硬限流）。
    """
    settings = get_settings()
    effective = settings.ratelimit_anon_per_min if limit is None else limit
    window = 60
    count = await _incr(Keys.anon_rate_limit(bucket, ip), window)
    if count is None:
        return
    if count > effective:
        raise TooManyRequests(
            "请求过于频繁，请稍后再试",
            headers={
                "Retry-After": str(window),
                "X-RateLimit-Limit": str(effective),
                "X-RateLimit-Remaining": "0",
            },
        )


def rate_limit_headers(limit: int = 60) -> dict[str, str]:
    return {"X-RateLimit-Limit": str(limit)}


def now_ts() -> int:
    return int(time.time())
