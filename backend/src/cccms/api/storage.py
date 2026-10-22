"""鉴权后的附件直出。

原来 ``/storage`` 是 StaticFiles 挂载：知道 URL 即可匿名读取，绕过
``FileRecord.__data_scope__`` 且不留审计痕迹。这里改为按 ``sys_file.path``
反查记录并走 ``apply_scope``，只把"当前用户可见"的附件发出去。

两条出流方式，由存储驱动决定：

* **local**：``FileResponse`` 直出本地文件（Range / 缓存 / ``Last-Modified`` 都交给框架）；
* **对象存储**：先在这里校验可见性，再 302 到**限时预签名 URL**（签发的对象就是刚校验过的那个）。

URL 形状保持不变（``/storage/2026/10/22/xxx.png``），因此历史存量链接对已登录用户仍然有效：
``load_user`` 既读 Cookie 也读 ``Authorization: Bearer``；匿名请求一律 401。
"""

from __future__ import annotations

from fastapi import Depends
from fastapi.responses import FileResponse, RedirectResponse
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.errors import NotFound
from cccms.core.permissions import make_router, no_auth
from cccms.core.scope import apply_scope
from cccms.db.session import get_session
from cccms.models.file import FileRecord
from cccms.services.storage import get_driver, local_driver

router = make_router(prefix="/storage", tags=["storage"], auth=True)

# 与 FileResponse 的 Cache-Control 对齐：直链活多久，浏览器就缓存多久。
PRESIGN_TTL = 300


@router.get("/{file_path:path}", response_model=None)
@no_auth("附件读取")
async def read_storage(
    file_path: str,
    session: AsyncSession = Depends(get_session),
) -> FileResponse | RedirectResponse:
    relative = file_path.strip("/")
    if not relative:
        raise NotFound("文件不存在")

    record = await session.scalar(
        apply_scope(select(FileRecord).where(FileRecord.path == relative), FileRecord)
    )
    if record is None:
        raise NotFound("文件不存在")

    driver = get_driver()
    if record.storage != driver.name:
        # 记录不在当前驱动上（迁移中常见）：读不到就是不存在，别回 500。
        raise NotFound("文件不存在")

    presigned = driver.presign_url(relative, PRESIGN_TTL)
    if presigned is not None:
        # 可见性刚刚校验过；直链本身是限时的，转发到微信也不会长期有效。
        return RedirectResponse(presigned, status_code=302)

    # 只有 local 驱动没有直链能力，此时 record.storage 必然是 local。
    target = local_driver().blob_path(relative)
    if not target.is_file():
        raise NotFound("文件不存在")
    return FileResponse(
        target,
        media_type=record.mime or "application/octet-stream",
        headers={"Cache-Control": "private, max-age=300"},
    )


__all__ = ["router"]
