from __future__ import annotations

from fastapi import Depends
from fastapi.responses import FileResponse
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageLimit, PageNum, PageResult, page_result
from cccms.core.permissions import make_router, no_auth
from cccms.db.session import get_session
from cccms.schemas.export import ExportTaskOut
from cccms.services import export as export_service

router = make_router(prefix="/export", tags=["export"])


@router.get("/task/list", response_model=ApiResponse[PageResult[ExportTaskOut]])
@no_auth("我的导出任务")
async def task_list(
    page: PageNum = 1,
    limit: PageLimit = 15,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await export_service.list_tasks(session, user, page=page, limit=limit)
    return ok(page_result(total, rows))


@router.get("/task/download", response_model=None)
@no_auth("下载导出文件")
async def task_download(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> FileResponse:
    path, filename = await export_service.download_path(session, user, id)
    return FileResponse(path, filename=filename, media_type="application/octet-stream")
