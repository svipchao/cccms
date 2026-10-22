"""鉴权后的附件直出。

原来 ``/storage`` 是 StaticFiles 挂载：知道 URL 即可匿名读取，绕过
``FileRecord.__data_scope__`` 且不留审计痕迹。这里改为按 ``sys_file.path``
反查记录并走 ``apply_scope``，只把“当前用户可见”的附件发出去。

URL 形状保持不变（``/storage/2026/10/22/xxx.png``），因此历史存量链接对
已登录用户仍然有效：``load_user`` 既读 Cookie 也读 ``Authorization: Bearer``，
两类客户端都能读到自己的附件；匿名请求一律 401。跨站 ``<img>`` 引用不带
凭据，因此仍然不可读。
"""

from __future__ import annotations

from fastapi import Depends
from fastapi.responses import FileResponse
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.errors import NotFound
from cccms.core.permissions import make_router, no_auth
from cccms.core.scope import apply_scope
from cccms.db.session import get_session
from cccms.models.file import FileRecord
from cccms.services.file import _blob_path

router = make_router(prefix="/storage", tags=["storage"], auth=True)


@router.get("/{file_path:path}", response_model=None, response_class=FileResponse)
@no_auth("附件读取")
async def read_storage(
    file_path: str,
    session: AsyncSession = Depends(get_session),
) -> FileResponse:
    relative = file_path.strip("/")
    if not relative:
        raise NotFound("文件不存在")

    record = await session.scalar(
        apply_scope(
            select(FileRecord).where(
                FileRecord.path == relative,
                FileRecord.storage == "local",
            ),
            FileRecord,
        )
    )
    if record is None:
        raise NotFound("文件不存在")

    target = _blob_path(get_settings().storage_root, relative)
    if not target.is_file():
        raise NotFound("文件不存在")
    return FileResponse(
        target,
        media_type=record.mime or "application/octet-stream",
        headers={"Cache-Control": "private, max-age=300"},
    )


__all__ = ["router"]
