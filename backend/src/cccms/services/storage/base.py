"""存储驱动的接口契约。

只放**对象级**操作：``save`` / ``open`` / ``delete`` / ``exists`` / ``url`` / ``presign_url``。
刻意不放 ``blob_path()`` 这类"给我一个本地路径"的方法 —— 那只有 local 实现得了，
放进协议里会让 S3 驱动永远是个不完备实现（论证见 docs/16 §9.1）。
本地专属能力（隔离区、分片暂存）放在 ``LocalStorageDriver`` 上，由它自己负责。
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import Protocol


class StorageDriver(Protocol):
    """一套驱动要满足的最小面。实现方只搬运字节，不做鉴权判断。"""

    name: str

    async def save(self, relative: str, chunks: AsyncIterator[bytes]) -> int:
        """把字节流写到 ``relative``，返回写入字节数。

        失败不得留下半截对象：要么写完整，要么删干净（调用方还会再兜一次 ``delete``）。
        """

    def open(
        self, relative: str, *, offset: int = 0, length: int | None = None
    ) -> AsyncIterator[bytes]:
        """按块读取。``offset`` / ``length`` 供 HTTP Range 使用，不要忽略它们。"""

    async def delete(self, relative: str) -> None:
        """删除对象；对象本来就不存在时视为成功。"""

    def exists(self, relative: str) -> bool: ...

    def url(self, relative: str) -> str:
        """站内访问地址（走登录态 + 数据权限链路）。"""

    def presign_url(self, relative: str, ttl: int) -> str | None:
        """限时直链。local 返回 ``None``，表示"只能走鉴权链路"。"""
