from __future__ import annotations

from typing import Annotated

from fastapi import Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.crontab import (
    CrontabLogOut,
    CrontabLogQuery,
    CrontabOut,
    CrontabQuery,
    CrontabSave,
    CrontabTargetOut,
)
from cccms.services import crontab as crontab_service

router = make_router(prefix="/crontab", tags=["crontab"])


@router.get("", response_model=ApiResponse[PageResult[CrontabOut]])
@permission("cccms:crontab:index", "定时任务列表")
async def index(
    params: Annotated[CrontabQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await crontab_service.list_crontab(session, user, params)
    return ok(page_result(total, rows))


@router.get("/targets", response_model=ApiResponse[list[CrontabTargetOut]])
@permission("cccms:crontab:targets", "可调度任务")
async def targets(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(crontab_service.targets())


@router.get("/logs", response_model=ApiResponse[PageResult[CrontabLogOut]])
@permission("cccms:crontab:logs", "执行日志")
async def logs(
    params: Annotated[CrontabLogQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await crontab_service.list_logs(session, user, params)
    return ok(page_result(total, rows))


@router.post("/save", response_model=ApiResponse[CrontabOut])
@permission("cccms:crontab:save", "新增定时任务", methods=["POST"])
async def save(
    payload: CrontabSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await crontab_service.create_crontab(session, user, payload))


@router.post("/update", response_model=ApiResponse[CrontabOut])
@permission("cccms:crontab:update", "修改定时任务", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    crontab_id = as_int(payload.pop("id"))
    return ok(await crontab_service.update_crontab(session, user, crontab_id, CrontabSave.model_validate(payload)))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:crontab:delete", "删除定时任务", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await crontab_service.delete_crontab(session, user, payload.id)
    return ok(None)


@router.post("/run", response_model=ApiResponse[CrontabLogOut])
@permission("cccms:crontab:run", "立即执行定时任务", methods=["POST"])
async def run(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await crontab_service.run_now(session, user, payload.id))
