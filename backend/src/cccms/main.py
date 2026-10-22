from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.middleware.gzip import GZipMiddleware
from sqlalchemy.exc import SQLAlchemyError

from cccms.api import BUSINESS_ROUTERS, PUBLIC_ROUTERS
from cccms.config import get_settings
from cccms.core.audit import start_log_writer, stop_log_writer
from cccms.core.cache import close_redis, redis_ping
from cccms.core.envelope import install_exception_handlers
from cccms.core.middleware import (
    OperationLogMiddleware,
    RequestContextMiddleware,
    RequestGuardMiddleware,
)
from cccms.core.permissions import assert_route_declarations
from cccms.db.session import dispose_engine
from cccms.services.export import reconcile_stale_tasks
from cccms.services.log import shutdown_exports

logger = logging.getLogger("cccms.app")


@asynccontextmanager
async def lifespan(_: FastAPI) -> AsyncIterator[None]:
    settings = get_settings()
    problems = settings.validate_secrets()
    if problems:
        message = "; ".join(problems)
        if settings.is_prod:
            raise RuntimeError(message)
        logger.warning("insecure development configuration: %s", message)

    storage_problems = settings.validate_storage()
    if storage_problems:
        raise RuntimeError("; ".join(storage_problems))

    settings.storage_root.mkdir(parents=True, exist_ok=True)
    settings.export_root.mkdir(parents=True, exist_ok=True)

    if not await redis_ping():
        logger.warning("redis is not reachable; online sessions and rate limits degrade")

    try:
        stale = await reconcile_stale_tasks()
        if stale:
            logger.warning("marked %s stale export task(s) as failed", stale)
    except SQLAlchemyError:
        logger.warning("export task reconciliation skipped: database unavailable")

    await start_log_writer()
    try:
        yield
    finally:
        await stop_log_writer()
        # 先停导出任务：它们还要写库更新状态，必须早于连接池释放。
        await shutdown_exports()
        await close_redis()
        await dispose_engine()


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title="CCCMS API",
        version="0.1.0",
        docs_url=None if settings.is_prod else "/docs",
        redoc_url=None if settings.is_prod else "/redoc",
        openapi_url=None if settings.is_prod else "/openapi.json",
        lifespan=lifespan,
    )

    # Starlette wraps in reverse registration order: CORS outermost, then gzip,
    # request context, and finally the operation log writer closest to routes.
    app.add_middleware(OperationLogMiddleware)
    app.add_middleware(RequestContextMiddleware)
    # 超时/体积守卫放在上下文之外：先挡住慢请求，再进入解析与鉴权。
    app.add_middleware(RequestGuardMiddleware)
    app.add_middleware(GZipMiddleware, minimum_size=1024)
    if settings.cors_origins:
        app.add_middleware(
            CORSMiddleware,
            allow_origins=settings.cors_origins,
            allow_credentials=True,
            allow_methods=["*"],
            allow_headers=["*", settings.csrf_header_name],
            expose_headers=["X-Trace-Id", "X-Refresh-Token"],
        )

    install_exception_handlers(app)
    settings.storage_root.mkdir(parents=True, exist_ok=True)

    for router in PUBLIC_ROUTERS:
        app.include_router(router)
    for router in BUSINESS_ROUTERS:
        # 旧前缀保留一个发布周期：老前端/第三方继续用 /api，新集成走带版本的 /api/v1。
        app.include_router(router, prefix="/api")
        app.include_router(router, prefix="/api/v1")

    # 附件不再用 StaticFiles 匿名直出：改由 api/storage.py 做鉴权 + 数据权限校验。

    assert_route_declarations(app)
    return app


app = create_app()
