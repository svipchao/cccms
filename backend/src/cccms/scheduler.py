"""Standalone scheduler process.

Run it next to the API (``python -m cccms.scheduler``).  Due tasks are claimed
with a Redis lock so several scheduler replicas are safe; the row-level
``running`` flag only protects against overlap within the same task.
"""

from __future__ import annotations

import asyncio
import contextlib
import logging
import signal
import time

from redis.exceptions import RedisError

from cccms.config import get_settings
from cccms.core.cache import Keys, close_redis, get_redis, redis_ping
from cccms.db.session import dispose_engine
from cccms.services.crontab import run_due

logger = logging.getLogger("cccms.scheduler")


async def heartbeat(ttl: int) -> None:
    """调度器存活心跳：挂了才能被发现（监控读这个 key 的年龄）。"""
    try:
        await get_redis().set(Keys.SCHEDULER_HEARTBEAT, str(int(time.time())), ex=max(30, ttl))
    except RedisError:
        logger.warning("failed to write scheduler heartbeat; redis is unreachable")


def _install_signal_handlers(stop: asyncio.Event) -> None:
    loop = asyncio.get_running_loop()
    for signal_name in (signal.SIGINT, signal.SIGTERM):
        with contextlib.suppress(NotImplementedError, RuntimeError, ValueError):
            loop.add_signal_handler(signal_name, stop.set)


async def tick() -> int:
    try:
        return await run_due()
    except asyncio.CancelledError:
        raise
    except Exception:  # a broken task must not kill the scheduler
        logger.exception("scheduler tick failed")
        return 0


async def serve(stop: asyncio.Event | None = None) -> None:
    settings = get_settings()
    event = stop or asyncio.Event()
    _install_signal_handlers(event)
    if not await redis_ping():
        logger.warning("redis is unreachable; scheduled tasks will be skipped")

    await heartbeat(settings.scheduler_poll_interval * 3)
    logger.info("scheduler started, poll interval=%ss", settings.scheduler_poll_interval)
    while not event.is_set():
        await tick()
        await heartbeat(settings.scheduler_poll_interval * 3)
        with contextlib.suppress(TimeoutError):
            await asyncio.wait_for(event.wait(), timeout=max(1, settings.scheduler_poll_interval))
    logger.info("scheduler stopped")


async def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    settings = get_settings()
    problems = settings.validate_secrets()
    if problems:
        message = "; ".join(problems)
        # 与 API 进程保持一致的 fail-fast：生产环境不允许带着不合规密钥跑任务。
        if settings.is_prod:
            raise RuntimeError(message)
        logger.warning("insecure configuration: %s", message)

    storage_problems = settings.validate_storage()
    if storage_problems:
        raise RuntimeError("; ".join(storage_problems))
    try:
        await serve()
    finally:
        await close_redis()
        await dispose_engine()


if __name__ == "__main__":
    with contextlib.suppress(KeyboardInterrupt):
        asyncio.run(main())
