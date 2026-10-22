from __future__ import annotations

import json
import logging
from typing import Any

from redis.asyncio import Redis
from redis.exceptions import RedisError

from cccms.config import get_settings

logger = logging.getLogger("cccms.cache")

_client: Redis | None = None


def get_redis() -> Redis:
    global _client
    if _client is None:
        settings = get_settings()
        _client = Redis.from_url(
            settings.redis_url,
            encoding="utf-8",
            decode_responses=True,
            socket_connect_timeout=2,
            socket_timeout=2,
            health_check_interval=30,
        )
    return _client


async def close_redis() -> None:
    global _client
    if _client is not None:
        await _client.aclose()
    _client = None


async def redis_ping() -> bool:
    try:
        return bool(await get_redis().ping())
    except RedisError:
        return False


async def get_json(key: str) -> Any | None:
    try:
        value = await get_redis().get(key)
    except RedisError:
        return None
    if value is None:
        return None
    try:
        return json.loads(value)
    except json.JSONDecodeError:
        return None


async def set_json(key: str, value: Any, ttl: int = 300) -> bool:
    try:
        await get_redis().set(key, json.dumps(value, ensure_ascii=False), ex=ttl)
        return True
    except RedisError:
        return False


async def delete(*keys: str) -> None:
    if not keys:
        return
    try:
        await get_redis().delete(*keys)
    except RedisError:
        return


async def incr(key: str, ttl: int | None = None) -> int | None:
    try:
        client = get_redis()
        value = await client.incr(key)
        if ttl is not None and value == 1:
            await client.expire(key, ttl)
        return int(value)
    except RedisError:
        return None


async def get_int(key: str, default: int = 0) -> int:
    try:
        value = await get_redis().get(key)
    except RedisError:
        return default
    if value is None:
        return default
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


async def version(key: str) -> int:
    return await get_int(key, 0)


async def bump(key: str) -> int:
    value = await incr(key)
    return value or 0


class Keys:
    AUTH_VERSION = "cccms:auth:ver"
    CONFIG_VERSION = "cccms:config:ver"
    DICT_VERSION = "cccms:dict:ver"
    SESSION_FLOOR = "cccms:session:floor"

    @staticmethod
    def auth_context(version: int, user_id: int, tenant_id: int) -> str:
        return f"cccms:auth:ctx:{version}:{tenant_id}:{user_id}"

    @staticmethod
    def token_blacklist(jti: str) -> str:
        return f"cccms:token:bl:{jti}"

    @staticmethod
    def user_cutoff(user_id: int) -> str:
        return f"cccms:token:cut:{user_id}"

    @staticmethod
    def online_session(jti: str) -> str:
        return f"cccms:online:s:{jti}"

    ONLINE_INDEX = "cccms:online:index"

    @staticmethod
    def captcha(captcha_id: str) -> str:
        return f"cccms:captcha:{captcha_id}"

    @staticmethod
    def login_fail_user(tenant_id: int, username: str) -> str:
        return f"cccms:login_fail:user:{tenant_id}:{username.lower()}"

    @staticmethod
    def login_fail_ip(ip: str) -> str:
        return f"cccms:login_fail:ip:{ip}"

    @staticmethod
    def rate_limit(user_id: int, path: str) -> str:
        return f"cccms:rl:{user_id}:{path}"

    @staticmethod
    def anon_rate_limit(bucket: str, ip: str) -> str:
        return f"cccms:rl:anon:{bucket}:{ip}"

    @staticmethod
    def serializer_lock(key: str) -> str:
        return f"cccms:lock:{key}"

    @staticmethod
    def dict_data(version: int, tenant_id: int, type_id: int) -> str:
        return f"cccms:dict:d:{version}:{tenant_id}:{type_id}"

    @staticmethod
    def dashboard_stats(version: int, tenant_id: int, user_id: int) -> str:
        return f"cccms:dash:stats:{version}:{tenant_id}:{user_id}"

    @staticmethod
    def config_all(tenant_id: int) -> str:
        return f"cccms:config:all:{tenant_id}"

    CONFIG_LOCK = "cccms:config:lock"
    SCHEDULER_HEARTBEAT = "cccms:scheduler:heartbeat"
    TENANT_DOMAIN = "cccms:tenant:domain:"
    UPLOAD = "cccms:upload:"
    SCHEMA_LOCK = "cccms:schema:lock"
    SCHEDULER_LOCK = "cccms:scheduler:lock"
    SEALER_LOCK = "cccms:sealer:lock"
