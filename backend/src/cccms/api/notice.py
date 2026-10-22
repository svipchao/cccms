from __future__ import annotations

from typing import Literal

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageLimit, PageNum, PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, no_auth, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.notice import (
    NoticeListOut,
    NoticeMyOut,
    NoticeOptionsOut,
    NoticeOut,
    NoticeReportOut,
    NoticeSave,
)
from cccms.services import notice as notice_service

router = make_router(prefix="/notice", tags=["notice"])


@router.get("", response_model=ApiResponse[PageResult[NoticeListOut]])
@permission("cccms:notice:index", "通知公告列表")
async def index(
    keyword: str | None = None,
    title: str | None = None,
    type: int | None = None,
    level: int | None = None,
    scope: int | None = None,
    status: int | None = None,
    trashed: int = 0,
    start: str | None = None,
    end: str | None = None,
    publish_start: str | None = None,
    publish_end: str | None = None,
    expire_start: str | None = None,
    expire_end: str | None = None,
    page: PageNum = 1,
    limit: PageLimit = 15,
    sort: str | None = None,
    order: Literal["asc", "desc"] = "asc",
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await notice_service.list_notices(
        session,
        user,
        keyword=keyword,
        title=title,
        type=type,
        level=level,
        scope=scope,
        status=status,
        trashed=trashed,
        start=start,
        end=end,
        publish_start=publish_start,
        publish_end=publish_end,
        expire_start=expire_start,
        expire_end=expire_end,
        page=page,
        limit=limit,
        sort=sort,
        order=order,
    )
    return ok(page_result(total, rows))


@router.get("/read", response_model=ApiResponse[NoticeOut])
@permission("cccms:notice:read", "通知公告详情")
async def read(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await notice_service.read_notice(session, user, id))


@router.get("/options", response_model=ApiResponse[NoticeOptionsOut])
@permission("cccms:notice:options", "投放候选")
async def options(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await notice_service.options(session, user))


@router.get("/users", response_model=ApiResponse[list[dict[str, object]]])
@permission("cccms:notice:users", "投放用户搜索")
async def users(
    keyword: str | None = None,
    ids: str | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    wanted = [int(item) for item in ids.split(",") if item.strip().isdigit()] if ids else None
    return ok(await notice_service.search_users(session, user, keyword, wanted))


@router.get("/report", response_model=ApiResponse[NoticeReportOut])
@permission("cccms:notice:report", "已读回执")
async def report(
    id: int,
    view: str = "read",
    page: PageNum = 1,
    limit: PageLimit = 15,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await notice_service.report(session, user, id, view=view, page=page, limit=limit))


@router.post("/save", response_model=ApiResponse[NoticeOut])
@permission("cccms:notice:save", "新增通知公告", methods=["POST"])
async def save(
    payload: NoticeSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await notice_service.create_notice(session, user, payload))


@router.post("/update", response_model=ApiResponse[NoticeOut])
@permission("cccms:notice:update", "修改通知公告", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    notice_id = as_int(payload.pop("id"))
    return ok(
        await notice_service.update_notice(session, user, notice_id, NoticeSave.model_validate(payload))
    )


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:notice:delete", "删除通知公告", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await notice_service.delete_notice(session, user, payload.id)
    return ok(None)


@router.get("/my", response_model=ApiResponse[PageResult[NoticeMyOut]])
@no_auth("我的消息")
async def my(
    page: PageNum = 1,
    limit: PageLimit = 15,
    unread: int = 0,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await notice_service.my_notices(
        session, user, page=page, limit=limit, only_unread=bool(unread)
    )
    return ok(page_result(total, rows))


@router.get("/unread", response_model=ApiResponse[dict[str, int]])
@no_auth("未读消息数")
async def unread(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok({"count": await notice_service.unread_count(session, user)})


@router.post("/markRead", response_model=ApiResponse[dict[str, bool]])
@no_auth("标记消息已读")
async def mark_read(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    changed = await notice_service.mark_read(session, user, payload.id)
    return ok({"read": changed})


@router.post("/markAllRead", response_model=ApiResponse[dict[str, int]])
@no_auth("全部标记已读")
async def mark_all_read(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    count = await notice_service.mark_all_read(session, user)
    return ok({"count": count, "updated": count})
