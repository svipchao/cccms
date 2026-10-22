from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.recycle import RecycleRequest, RecycleResult
from cccms.services import recycle as recycle_service

router = make_router(prefix="/recycle", tags=["recycle"])


@router.post("/restore", response_model=ApiResponse[RecycleResult])
@permission("cccms:recycle:restore", "回收站还原", methods=["POST"])
async def restore(
    payload: RecycleRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await recycle_service.restore(session, user, payload.type, payload.ids)
    return ok(RecycleResult(affected=affected, restored=affected, skipped=skipped))


@router.post("/delete", response_model=ApiResponse[RecycleResult])
@permission("cccms:recycle:delete", "回收站彻底删除", methods=["POST"])
async def purge(
    payload: RecycleRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await recycle_service.purge(session, user, payload.type, payload.ids)
    return ok(RecycleResult(affected=affected, deleted=affected, skipped=skipped))
