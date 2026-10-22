from __future__ import annotations

from typing import Annotated

from fastapi import Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageLimit, PageResult, page_result
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdsRequest
from cccms.schemas.log import (
    AuditVerifyOut,
    LogActionOut,
    LogAnalysisOut,
    LogDetailOut,
    LogExportOut,
    LogOut,
    LogQuery,
    LogSessionOut,
)
from cccms.services import log as log_service

router = make_router(prefix="/log", tags=["log"])


@router.get("", response_model=ApiResponse[PageResult[LogOut]])
@permission("cccms:log:index", "操作日志列表")
async def index(
    params: Annotated[LogQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await log_service.list_logs(session, user, params)
    return ok(page_result(total, rows))


@router.get("/detail", response_model=ApiResponse[LogDetailOut])
@permission("cccms:log:index", "操作日志详情")
async def detail(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await log_service.read_log(session, user, id))


@router.get("/trace", response_model=ApiResponse[LogDetailOut])
@permission("cccms:log:trace", "按请求 ID 查日志")
async def trace(
    trace_id: str,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await log_service.trace(session, user, trace_id))


@router.get("/action", response_model=ApiResponse[LogActionOut])
@permission("cccms:log:action", "操作链路")
async def action(
    action_id: str,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await log_service.action(session, user, action_id))


@router.get("/session", response_model=ApiResponse[LogSessionOut])
@permission("cccms:log:session", "会话轨迹")
async def session_trace(
    session_id: str,
    after_id: int = 0,
    limit: PageLimit = 50,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(
        await log_service.session_trace(
            session,
            user,
            session_id,
            after_id=after_id,
            limit=min(200, max(1, limit)),
        )
    )


@router.get("/login/analysis", response_model=ApiResponse[LogAnalysisOut])
@permission("cccms:log:analysis", "登录安全分析")
async def login_analysis(
    days: int = 7,
    start: str | None = None,
    end: str | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await log_service.login_analysis(session, user, days=days, start=start, end=end))


@router.get("/export", response_model=ApiResponse[LogExportOut])
@permission("cccms:log:export", "导出操作日志")
async def export(
    params: Annotated[LogQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await log_service.export_logs(session, user, params))


@router.post("/audit/verify", response_model=ApiResponse[AuditVerifyOut])
@permission("cccms:log:audit", "校验审计链", methods=["POST"])
async def audit_verify(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    """校验审计哈希链。

    不依赖 ``get_session``：``verify_chain`` 自己开会话做 keyset 分页，
    避免把请求会话占用到全表扫完。
    """
    return ok(await log_service.verify_audit(user))


@router.post("/delete", response_model=ApiResponse[dict[str, object]])
@permission("cccms:log:delete", "删除操作日志", methods=["POST"])
async def delete(
    payload: IdsRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await log_service.delete_logs(session, user, payload.ids)
    return ok({"affected": affected, "skipped": skipped})
