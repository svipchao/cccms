from __future__ import annotations

from typing import Annotated

from fastapi import Depends, File, Form, Query, Request, UploadFile
from fastapi.responses import Response, StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.http_range import attachment_header, resolve_range
from cccms.core.pagination import PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, no_auth, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.file import (
    FileMoveRequest,
    FileOut,
    FileQuery,
    UploadCompleteRequest,
    UploadInitOut,
    UploadInitRequest,
)
from cccms.schemas.system import CategoryNode, CategorySave
from cccms.services import dict_service
from cccms.services import file as file_service

router = make_router(prefix="/file", tags=["file"])


@router.get("", response_model=ApiResponse[PageResult[FileOut]])
@permission("cccms:file:index", "附件列表")
async def index(
    params: Annotated[FileQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await file_service.list_files(session, user, params)
    return ok(page_result(total, rows))


@router.get("/{file_id}/download", response_model=None)
@no_auth("附件下载")
async def download(
    file_id: int,
    request: Request,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> Response:
    """纯 Bearer 客户端与"另存为"的唯一出口。

    ``@no_auth`` 在这里的语义是"不校验具体权限 slug"：可见性完全由服务层
    ``apply_scope`` 决定（与 ``/storage/<path>`` 一致），匿名请求仍被
    ``get_current_user`` 挡在 401。

    字节流由存储驱动给（不再交给 ``FileResponse``），因此 Range 与
    ``Content-Length`` 在下面自己处理：断点续传、PDF 翻页、音视频拖动都依赖它们。
    """
    blob = await file_service.download_file(session, user, file_id)
    byte_range, satisfiable = resolve_range(request.headers.get("range"), blob.size)
    if not satisfiable:
        return Response(
            status_code=416,
            headers={"Accept-Ranges": "bytes", "Content-Range": f"bytes */{blob.size}"},
        )

    headers = {
        # 通知客户端"可以分段要"，否则浏览器只会整份重下。
        "Accept-Ranges": "bytes",
        "Cache-Control": "private, max-age=300",
        "Content-Disposition": attachment_header(blob.name),
    }
    if byte_range is None:
        headers["Content-Length"] = str(blob.size)
        return StreamingResponse(
            blob.stream(), media_type=blob.mime, headers=headers
        )

    headers["Content-Length"] = str(byte_range.length)
    headers["Content-Range"] = byte_range.header(blob.size)
    return StreamingResponse(
        blob.stream(offset=byte_range.start, length=byte_range.length),
        status_code=206,
        media_type=blob.mime,
        headers=headers,
    )


@router.post("/upload", response_model=ApiResponse[FileOut])
@permission("cccms:file:upload", "上传附件", methods=["POST"])
async def upload(
    file: UploadFile = File(...),
    category_id: int | None = Form(None),
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await file_service.upload_file(session, user, file, category_id=category_id))


@router.post("/upload/init", response_model=ApiResponse[UploadInitOut])
@permission("cccms:file:upload:chunk", "分片上传初始化", methods=["POST"])
async def upload_init(
    payload: UploadInitRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await file_service.init_upload(session, user, payload))


@router.post("/upload/chunk", response_model=ApiResponse[dict[str, int]])
@no_auth("上传分片")
async def upload_chunk(
    upload_id: str = Form(...),
    index: int = Form(...),
    chunk: UploadFile = File(..., alias="file"),
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    size = await file_service.save_chunk(user, upload_id, index, chunk)
    return ok({"size": size})


@router.post("/upload/complete", response_model=ApiResponse[dict[str, object]])
@no_auth("完成分片上传")
async def upload_complete(
    payload: UploadCompleteRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    row = await file_service.complete_upload(session, user, payload)
    return ok({"instant": False, "file": row})


@router.post("/delete", response_model=ApiResponse[dict[str, object]])
@permission("cccms:file:delete", "删除附件", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await file_service.delete_files(session, user, [payload.id])
    return ok({"affected": affected, "skipped": skipped})


@router.post("/move", response_model=ApiResponse[dict[str, object]])
@permission("cccms:file:move", "移动附件", methods=["POST"])
async def move(
    payload: FileMoveRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await file_service.move_files(session, user, payload.ids, payload.category_id)
    return ok({"affected": affected, "skipped": skipped})


@router.get("/category", response_model=ApiResponse[list[CategoryNode]])
@permission("cccms:file:category", "附件分类树")
async def category(
    trashed: int = 0,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await file_service.category_tree(session, user, trashed=bool(trashed)))


@router.post("/category/save", response_model=ApiResponse[CategoryNode])
@permission("cccms:file:category_save", "新增附件分类", methods=["POST"])
async def category_save(
    payload: CategorySave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    payload = payload.model_copy(update={"module": "file"})
    return ok(await dict_service.save_category(session, user, payload))


@router.post("/category/update", response_model=ApiResponse[CategoryNode])
@permission("cccms:file:category_update", "修改附件分类", methods=["POST"])
async def category_update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    category_id = as_int(payload.pop("id"))
    payload["module"] = "file"
    return ok(await dict_service.save_category(session, user, CategorySave.model_validate(payload), category_id))


@router.post("/category/delete", response_model=ApiResponse[None])
@permission("cccms:file:category_delete", "删除附件分类", methods=["POST"])
async def category_delete(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await file_service.delete_category(session, user, as_int(payload.pop("id")))
    return ok(None)
