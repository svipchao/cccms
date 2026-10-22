from __future__ import annotations

import logging
import time
from typing import Any, cast

from redis.exceptions import RedisError

from cccms.core.cache import Keys, get_redis

logger = logging.getLogger("cccms.session")


async def is_token_revoked(jti: str) -> bool:
    try:
        return bool(await get_redis().exists(Keys.token_blacklist(jti)))
    except RedisError:
        return False


async def revoke_token(jti: str, ttl: int) -> None:
    if ttl <= 0:
        return
    try:
        await get_redis().set(Keys.token_blacklist(jti), "1", ex=ttl)
    except RedisError:
        return


async def user_cutoff(user_id: int) -> int:
    try:
        value = await get_redis().get(Keys.user_cutoff(user_id))
        return int(value or 0)
    except (RedisError, TypeError, ValueError):
        return 0


async def revoke_user_tokens(user_id: int, ttl: int) -> None:
    """作废该用户此前签发的全部令牌。

    水位只允许前进：并发/重复调用取较大值，避免把已生效的作废时间戳改小。
    水位绝不在登录时清除，否则被作废的旧令牌会随下一次登录“复活”。
    """
    key = Keys.user_cutoff(user_id)
    now_ts = int(time.time())
    try:
        client = get_redis()
        current = await client.get(key)
        try:
            current_ts = int(current or 0)
        except (TypeError, ValueError):
            current_ts = 0
        await client.set(key, str(max(now_ts, current_ts)), ex=max(60, ttl))
    except RedisError:
        return


async def session_floor() -> int:
    try:
        value = await get_redis().get(Keys.SESSION_FLOOR)
        return int(value or 0)
    except (RedisError, TypeError, ValueError):
        return 0


async def cut_session_floor(ttl: int) -> None:
    try:
        await get_redis().set(Keys.SESSION_FLOOR, str(int(time.time())), ex=max(60, ttl))
    except RedisError:
        return


async def is_stale(*, user_id: int, iat: int) -> bool:
    if await is_token_revoked_by_user(user_id=user_id, iat=iat):
        return True
    floor = await session_floor()
    return bool(floor and iat <= floor)


def token_before_cutoff(*, iat: int, cutoff: int) -> bool:
    """令牌是否早于用户级作废水位。

    严格小于：作废发生在第 T 秒、登录也发生在第 T 秒时签发的令牌仍然有效，
    避免“改密后立即重新登录被自己的水位挡在门外”。
    """
    return bool(cutoff and iat < cutoff)


async def is_token_revoked_by_user(*, user_id: int, iat: int) -> bool:
    return token_before_cutoff(iat=iat, cutoff=await user_cutoff(user_id))


async def register_online(
    *,
    user_id: int,
    tenant_id: int,
    username: str,
    nickname: str,
    jti: str,
    ip: str,
    ua: str,
    exp: int,
) -> None:
    ttl = max(0, exp - int(time.time()))
    if ttl <= 0:
        return
    key = Keys.online_session(jti)
    payload: dict[str, Any] = {
        "user_id": str(user_id),
        "tenant_id": str(tenant_id),
        "username": username,
        "nickname": nickname,
        "jti": jti,
        "ip": ip,
        "ua": ua[:255],
        "login_at": str(int(time.time())),
        "last_at": str(int(time.time())),
        "exp": str(exp),
    }
    try:
        client = get_redis()
        # redis-py 的 stub 对 mapping 的 TypeVar 推断过窄, 运行期接受 dict[str, str]。
        await client.hset(key, mapping=payload)  # type: ignore[arg-type]
        await client.expire(key, ttl)
        await client.zadd(Keys.ONLINE_INDEX, {jti: int(time.time())})
    except RedisError:
        return


async def touch_online(jti: str) -> None:
    key = Keys.online_session(jti)
    try:
        client = get_redis()
        raw = await client.hget(key, "last_at")
        now_ts = int(time.time())
        if raw is None:
            return
        if now_ts - int(raw) < 60:
            return
        await client.hset(key, "last_at", str(now_ts))
        await client.zadd(Keys.ONLINE_INDEX, {jti: now_ts})
    except (RedisError, TypeError, ValueError):
        return


async def rotate_online(old_jti: str, new_jti: str, exp: int) -> bool:
    """把会话从旧 jti 迁到新 jti；返回 False 表示没抢到（并发续期已被别人接管）。

    「先 hgetall 再 delete」：delete 的返回值是唯一的抢占凭据。续期窗口内的并发请求
    都会读到同一份旧记录，只有真正删掉它（返回值 1）的那个请求才继续下发新令牌，
    否则同一用户会在一秒内攒出多条孤儿会话，在线列表里就出现「一个用户好几行」。
    """
    old_key = Keys.online_session(old_jti)
    new_key = Keys.online_session(new_jti)
    ttl = max(0, exp - int(time.time()))
    if ttl <= 0:
        return False
    try:
        client = get_redis()
        payload = cast("dict[str, Any]", await client.hgetall(old_key))
        if not payload:
            return False
        if not await client.delete(old_key):
            return False
        await client.zrem(Keys.ONLINE_INDEX, old_jti)
        payload["jti"] = new_jti
        payload["last_at"] = str(int(time.time()))
        payload["exp"] = str(exp)
        await client.hset(new_key, mapping=payload)  # type: ignore[arg-type]
        await client.expire(new_key, ttl)
        await client.zadd(Keys.ONLINE_INDEX, {new_jti: int(time.time())})
        return True
    except RedisError:
        return False


async def remove_online(jti: str) -> None:
    try:
        client = get_redis()
        await client.delete(Keys.online_session(jti))
        await client.zrem(Keys.ONLINE_INDEX, jti)
    except RedisError:
        return


async def remove_user_online(user_id: int) -> int:
    removed = 0
    try:
        client = get_redis()
        # 非 withscores 时成员一定是 jti 字符串, stub 的联合返回类型需要收窄。
        members = cast("list[str]", await client.zrange(Keys.ONLINE_INDEX, 0, -1))
        for jti in members:
            key = Keys.online_session(jti)
            owner = await client.hget(key, "user_id")
            if owner == str(user_id):
                await client.delete(key)
                await client.zrem(Keys.ONLINE_INDEX, jti)
                removed += 1
    except RedisError:
        return removed
    return removed


async def online_sessions(tenant_id: int | None = None) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    try:
        client = get_redis()
        members = cast(
            "list[tuple[str, float]]",
            await client.zrange(Keys.ONLINE_INDEX, 0, -1, withscores=True),
        )
        now_ts = int(time.time())
        for jti, score in members:
            payload = cast("dict[str, Any]", await client.hgetall(Keys.online_session(jti)))
            if not payload:
                await client.zrem(Keys.ONLINE_INDEX, jti)
                continue
            exp = int(payload.get("exp", "0") or 0)
            if exp and exp <= now_ts:
                await client.delete(Keys.online_session(jti))
                await client.zrem(Keys.ONLINE_INDEX, jti)
                continue
            if tenant_id is not None and int(payload.get("tenant_id", 0) or 0) != tenant_id:
                continue
            payload["last_at"] = int(score)
            result.append(payload)
    except (RedisError, TypeError, ValueError):
        return result
    return result


async def online_user_count(tenant_id: int | None = None) -> int:
    return len({item.get("user_id") for item in await online_sessions(tenant_id)})
