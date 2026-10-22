"""本地磁盘驱动：``CCCMS_STORAGE_LOCAL_ROOT`` 之下按 ``YYYY/MM/DD`` 分目录。

除通用契约外，它额外提供三样只有"文件确实在本机"时才成立的能力：
``blob_path`` / ``quarantine_path`` / ``chunk_dir``。软删除隔离区与分片暂存都依赖它们，
换到对象存储时要单独改造（见 docs/16 §9.1 的对照表）。
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from pathlib import Path

import aiofiles

from cccms.config import Settings
from cccms.core.errors import ValidationFailed

READ_CHUNK = 1024 * 1024


def safe_join(root: Path, relative: str) -> Path:
    """把相对路径拼到根目录下，并拒绝任何越界结果（``../`` 与符号链接逃逸）。"""
    resolved_root = root.resolve()
    path = (resolved_root / relative).resolve()
    if path != resolved_root and resolved_root not in path.parents:
        raise ValidationFailed("文件路径不合法")
    return path


class LocalStorageDriver:
    name = "local"

    def __init__(self, root: Path, url_prefix: str = "/storage") -> None:
        self._root = root
        self._url_prefix = url_prefix.rstrip("/") or "/storage"

    @classmethod
    def from_settings(cls, settings: Settings) -> LocalStorageDriver:
        return cls(settings.storage_root, settings.storage_url_prefix)

    # --- 本地专属能力 -----------------------------------------------------
    def blob_path(self, relative: str) -> Path:
        return safe_join(self._root, relative)

    def quarantine_path(self, relative: str) -> Path:
        # 隔离区必须在静态目录之外，软删除后旧 URL 立即 404。
        return safe_join(self._root.parent / "quarantine", relative)

    def chunk_root(self) -> Path:
        return safe_join(self._root, "chunks")

    def chunk_dir(self, upload_id: str) -> Path:
        return safe_join(self._root, f"chunks/{upload_id}")

    # --- 通用契约 ---------------------------------------------------------
    async def save(self, relative: str, chunks: AsyncIterator[bytes]) -> int:
        target = self.blob_path(relative)
        target.parent.mkdir(parents=True, exist_ok=True)
        total = 0
        try:
            async with aiofiles.open(target, "wb") as handle:
                async for chunk in chunks:
                    await handle.write(chunk)
                    total += len(chunk)
        except Exception:
            # 半截文件会变成"能下载但内容不全"的坏附件，必须清掉。
            target.unlink(missing_ok=True)
            raise
        return total

    async def open(
        self, relative: str, *, offset: int = 0, length: int | None = None
    ) -> AsyncIterator[bytes]:
        target = self.blob_path(relative)
        async with aiofiles.open(target, "rb") as handle:
            if offset:
                await handle.seek(offset)
            remaining = length
            while True:
                size = READ_CHUNK if remaining is None else min(READ_CHUNK, remaining)
                if size <= 0:
                    return
                chunk: bytes = await handle.read(size)
                if not chunk:
                    return
                if remaining is not None:
                    remaining -= len(chunk)
                yield chunk

    async def delete(self, relative: str) -> None:
        self.blob_path(relative).unlink(missing_ok=True)

    def exists(self, relative: str) -> bool:
        return self.blob_path(relative).is_file()

    def url(self, relative: str) -> str:
        return f"{self._url_prefix}/{relative}"

    def presign_url(self, relative: str, ttl: int) -> str | None:
        # 本地盘没有"给别人一个限时直链"的能力，只能走 /storage 的鉴权链路。
        return None


__all__ = ["READ_CHUNK", "LocalStorageDriver", "safe_join"]
