from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageLimit, PageNum, PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest, IdsRequest
from cccms.schemas.dept import PostOut, PostSave
from cccms.schemas.user import BatchStatusRequest
from cccms.services import post as post_service

router = make_router(prefix="/post", tags=["post"])


@router.get("", response_model=ApiResponse[PageResult[PostOut]])
@permission("cccms:post:index", "岗位列表")
async def index(
    keyword: str | None = None,
    name: str | None = None,
    status: int | None = None,
    trashed: int = 0,
    page: PageNum = 1,
    limit: PageLimit = 15,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await post_service.list_posts(
        session,
        user,
        keyword=name or keyword,
        status=status,
        trashed=trashed,
        page=page,
        limit=limit,
    )
    return ok(page_result(total, rows))


@router.post("/save", response_model=ApiResponse[PostOut])
@permission("cccms:post:save", "新增岗位", methods=["POST"])
async def save(
    payload: PostSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await post_service.create_post(session, user, payload))


@router.post("/update", response_model=ApiResponse[PostOut])
@permission("cccms:post:update", "修改岗位", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    post_id = as_int(payload.pop("id"))
    return ok(await post_service.update_post(session, user, post_id, PostSave.model_validate(payload)))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:post:delete", "删除岗位", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await post_service.delete_post(session, user, payload.id)
    return ok(None)


@router.post("/batchStatus", response_model=ApiResponse[dict[str, object]])
@permission("cccms:post:batch_status", "批量修改岗位状态", methods=["POST"])
async def batch_status(
    payload: BatchStatusRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await post_service.batch_status(session, user, payload.ids, payload.status)
    return ok({"affected": affected, "skipped": skipped})


@router.post("/batchDelete", response_model=ApiResponse[dict[str, object]])
@permission("cccms:post:batch_delete", "批量删除岗位", methods=["POST"])
async def batch_delete(
    payload: IdsRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await post_service.batch_delete(session, user, payload.ids)
    return ok({"affected": affected, "skipped": skipped})
