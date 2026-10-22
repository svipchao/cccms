from __future__ import annotations

from datetime import timedelta
from typing import Any

from fastapi import Depends
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.cache import Keys, get_json, set_json, version
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import count_query
from cccms.core.permissions import make_router, no_auth
from cccms.core.rbac import has_permission
from cccms.core.scope import apply_scope
from cccms.core.session import online_user_count
from cccms.core.time import now
from cccms.db.session import get_session
from cccms.models.crontab import Crontab
from cccms.models.dict import DictType
from cccms.models.file import FileRecord
from cccms.models.log import OperationLog
from cccms.models.rule import DataRule
from cccms.models.tenant import Tenant
from cccms.models.user import Dept, Menu, Post, Role, User
from cccms.services import notice as notice_service

router = make_router(prefix="/dashboard", tags=["dashboard"])

DASHBOARD_CACHE_TTL = 60


async def _count(session: AsyncSession, statement: Any) -> int:
    """统计口径与列表页保持一致的“瘦”写法：派生表只带主键一列。"""
    return int(await session.scalar(count_query(statement)) or 0)


@router.get("/stats", response_model=ApiResponse[dict[str, object]])
@no_auth("工作台统计")
async def stats(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    tenant_id = user.tenant_id
    # 工作台是登录后第一个页面，原实现每次刷新跑 11 次 count（含 sys_log 全表）。
    cache_key = Keys.dashboard_stats(await version(Keys.AUTH_VERSION), tenant_id, user.id)
    cached = await get_json(cache_key)
    if isinstance(cached, dict):
        return ok(cached)
    counts: dict[str, int] = {}

    async def add(key: str, slug: str, statement: Any) -> None:
        # 先判断权限再查询：原实现把 await _count(...) 放在实参里，权限判断无法阻止查询发生。
        if has_permission(user, slug):
            counts[key] = await _count(session, statement)

    await add(
        "user",
        "cccms:user:index",
        apply_scope(select(User), User).where(User.status == 1),
    )
    await add(
        "role",
        "cccms:role:index",
        select(Role).where(Role.tenant_id == tenant_id, Role.status == 1, Role.delete_time.is_(None)),
    )
    await add(
        "dept",
        "cccms:dept:tree",
        select(Dept).where(Dept.tenant_id == tenant_id, Dept.status == 1, Dept.delete_time.is_(None)),
    )
    await add(
        "post",
        "cccms:post:index",
        select(Post).where(Post.tenant_id == tenant_id, Post.status == 1, Post.delete_time.is_(None)),
    )
    await add(
        "menu",
        "cccms:menu:tree",
        select(Menu).where(Menu.type == 2, Menu.status == 1, Menu.delete_time.is_(None)),
    )
    await add(
        "dict",
        "cccms:dict:index",
        select(DictType).where(
            DictType.tenant_id == tenant_id,
            DictType.status == 1,
            DictType.delete_time.is_(None),
        ),
    )
    await add("file", "cccms:file:index", apply_scope(select(FileRecord), FileRecord))
    await add(
        "crontab",
        "cccms:crontab:index",
        select(Crontab).where(Crontab.tenant_id == tenant_id, Crontab.delete_time.is_(None)),
    )
    await add(
        "data_rule",
        "cccms:data_rule:index",
        select(DataRule).where(DataRule.tenant_id == tenant_id, DataRule.delete_time.is_(None)),
    )

    log_base = apply_scope(
        select(OperationLog.id, OperationLog.create_time).where(OperationLog.tenant_id == tenant_id),
        OperationLog,
    )
    await add("log", "cccms:log:index", log_base)

    today_start = now().replace(hour=0, minute=0, second=0, microsecond=0)
    await add(
        "today_log",
        "cccms:log:index",
        log_base.where(OperationLog.create_time >= today_start),
    )

    if has_permission(user, "cccms:online:index"):
        counts["online"] = await online_user_count(tenant_id)
    if user.is_super_admin:
        counts["tenant"] = await _count(session, select(Tenant).where(Tenant.delete_time.is_(None)))

    # 阅读侧：公告未读数与权限无关，登录即可见
    counts["notice_unread"] = await notice_service.unread_count(session, user)

    dates: list[str] = []
    values: list[int] = []
    if has_permission(user, "cccms:log:index"):
        start = (now() - timedelta(days=6)).replace(hour=0, minute=0, second=0, microsecond=0)
        trend_statement = apply_scope(
            select(func.date(OperationLog.create_time).label("day"), func.count())
            .where(OperationLog.tenant_id == tenant_id, OperationLog.create_time >= start)
            .group_by(func.date(OperationLog.create_time)),
            OperationLog,
        )
        trend_rows = await session.execute(trend_statement)
        by_day = {str(day): int(count) for day, count in trend_rows.all()}
        for offset in range(7):
            day = (start + timedelta(days=offset)).strftime("%Y-%m-%d")
            dates.append(day)
            values.append(by_day.get(day, 0))

    file_types: list[dict[str, object]] = []
    if has_permission(user, "cccms:file:index"):
        file_sub = apply_scope(
            select(FileRecord.ext).where(FileRecord.tenant_id == tenant_id), FileRecord
        ).subquery()
        file_rows = await session.execute(
            select(file_sub.c.ext, func.count()).select_from(file_sub).group_by(file_sub.c.ext)
        )
        file_types = [{"name": ext or "other", "value": int(count)} for ext, count in file_rows.all()]

    payload: dict[str, object] = {
        "counts": counts,
        "log_trend": {"dates": dates, "values": values},
        "file_types": file_types,
    }
    await set_json(cache_key, payload, ttl=DASHBOARD_CACHE_TTL)
    return ok(payload)
