"""API routers.

Every module builds its router with :func:`cccms.core.permissions.make_router`,
so a route without ``@permission`` / ``@no_auth`` / ``@no_login`` fails at
startup instead of silently allowing anonymous access.
"""

from __future__ import annotations

from fastapi import APIRouter

from cccms.api import (
    auth,
    config,
    crontab,
    dashboard,
    data_rule,
    dept,
    dict,
    export,
    file,
    generator,
    log,
    menu,
    notice,
    observability,
    online,
    post,
    profile,
    recycle,
    role,
    storage,
    system,
    tenant,
    upgrade,
    user,
)

BUSINESS_ROUTERS: tuple[APIRouter, ...] = (
    auth.router,
    profile.router,
    dashboard.router,
    user.router,
    role.router,
    menu.router,
    dept.router,
    post.router,
    dict.router,
    config.router,
    system.router,
    data_rule.router,
    tenant.router,
    recycle.router,
    log.router,
    export.router,
    online.router,
    notice.router,
    file.router,
    crontab.router,
    generator.router,
    upgrade.router,
)

# 探针与附件直出挂在根路径：/ping、/healthz、/metrics、/storage/**
PUBLIC_ROUTERS: tuple[APIRouter, ...] = (observability.router, storage.router)

__all__ = ["BUSINESS_ROUTERS", "PUBLIC_ROUTERS"]
