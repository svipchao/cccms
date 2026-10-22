from __future__ import annotations

import shutil
import time

from fastapi import Depends
from fastapi.responses import PlainTextResponse
from prometheus_client import CONTENT_TYPE_LATEST, Gauge, generate_latest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.cache import Keys, get_int, redis_ping
from cccms.core.context import current_context
from cccms.core.envelope import ApiResponse, ok
from cccms.core.errors import NotFound, ServiceUnavailable
from cccms.core.metrics import REGISTRY
from cccms.core.net import ip_allowed
from cccms.core.permissions import make_router, no_login
from cccms.core.session import online_sessions, online_user_count
from cccms.db.session import get_engine, get_session

router = make_router(prefix="", tags=["observability"], auth=False)

# 与中间件的 RED 指标共用同一个 registry，/metrics 一次就能抓全。
registry = REGISTRY
online_sessions_gauge = Gauge(
    "cccms_online_sessions", "Redis 中仍有效的在线会话数", registry=registry
)
online_users_gauge = Gauge(
    "cccms_online_users", "去重后的在线用户数", registry=registry
)
scheduler_heartbeat_gauge = Gauge(
    "cccms_scheduler_heartbeat_age_seconds", "调度器心跳年龄；-1 表示从未上报", registry=registry
)
db_pool_gauge = Gauge(
    "cccms_db_pool_checked_out", "数据库连接池已借出的连接数", registry=registry
)


@router.get("/ping", response_model=ApiResponse[dict[str, str]])
@no_login("存活探针")
async def ping() -> dict[str, object]:
    return ok({"status": "ok"})


@router.get("/healthz", response_model=ApiResponse[dict[str, object]])
@no_login("就绪探针")
async def healthz(session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    """DB 与磁盘是硬依赖；Redis 只作降级信号，不参与状态码。

    业务侧对 Redis 全部 fail-open，若把它算作必需依赖，Redis 抖动会让所有实例
    同时 503 被负载均衡摘除，反而制造全站故障。
    """
    checks: dict[str, object] = {}
    # 探活本身不能抛错, 任何异常都折算成 unavailable。
    try:
        await session.execute(text("SELECT 1"))
        checks["database"] = True
    except Exception:  # noqa: BLE001
        checks["database"] = False

    checks["redis"] = await redis_ping()

    settings = get_settings()
    try:
        usage = shutil.disk_usage(settings.storage_root)
        percent = usage.free / usage.total * 100 if usage.total else 0
        checks["disk_free_percent"] = round(percent, 2)
        checks["disk_ok"] = percent >= settings.health_disk_min_percent
    except OSError:
        checks["disk_ok"] = False

    if not checks.get("database") or not checks.get("disk_ok"):
        raise ServiceUnavailable("服务依赖异常")

    degraded = not checks.get("redis")
    return ok({"status": "degraded" if degraded else "ok", "checks": checks})


@router.get("/metrics", response_class=PlainTextResponse, response_model=None)
@no_login("Prometheus 指标")
async def metrics() -> PlainTextResponse:
    ctx = current_context()
    # 支持网段与 *：原来的 set 精确匹配让 10.0.0.0/8 这类配置静默失效。
    if ctx is not None and not ip_allowed(ctx.ip, get_settings().metrics_allow_ips):
        raise NotFound("页面不存在")
    sessions = await online_sessions()
    online_sessions_gauge.set(len(sessions))
    online_users_gauge.set(await online_user_count())
    last_beat = await get_int(Keys.SCHEDULER_HEARTBEAT, 0)
    scheduler_heartbeat_gauge.set(max(0, time.time() - last_beat) if last_beat else -1)
    try:
        # QueuePool 等具体连接池才有 checkedout(), 基类 Pool 只有通用接口。
        db_pool_gauge.set(get_engine().pool.checkedout())  # type: ignore[attr-defined]
    except Exception:  # noqa: BLE001 - 指标采集失败不能影响抓取
        db_pool_gauge.set(-1)
    return PlainTextResponse(generate_latest(registry), media_type=CONTENT_TYPE_LATEST)
