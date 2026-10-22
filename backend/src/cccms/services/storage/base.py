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

    async def quarantine(self, relative: str) -> None:
        """软删除：把对象移出可下载路径，但保留以便回收站还原。

        local 移到静态目录之外的隔离区；S3 移到 ``_quarantine/`` 前缀（配合桶生命周期规则清理）。
        """

    async def restore(self, relative: str) -> None:
        """从隔离区移回可下载路径；隔离区里没有时静默返回。"""

    async def purge(self, relative: str) -> None:
        """彻底删除，包括隔离区里的副本。"""

    def exists(self, relative: str) -> bool: ...

    def size(self, relative: str) -> int:
        """对象字节数。调用方拿它算 ``Content-Length``，所以必须是真实大小而不是库里的旧值。"""

    def url(self, relative: str) -> str:
        """站内访问地址（走登录态 + 数据权限链路）。"""

    def presign_url(self, relative: str, ttl: int) -> str | None:
        """限时直链。local 返回 ``None``，表示"只能走鉴权链路"。"""
