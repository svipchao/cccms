"""附件下载的服务层：可见性之外的三个失败面。

下载是"数据外带"的唯一出口，除了 ``apply_scope``，还要盯住：
记录落在别的驱动上、文件已被清掉、库里的 size 与真实大小不一致。
最后一条尤其重要——``Content-Length`` 算错会让浏览器截断文件。
"""

from __future__ import annotations

from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest

from cccms.core.errors import NotFound
from cccms.models.file import FileRecord
from cccms.services import file as file_service
from cccms.services.storage.local import LocalStorageDriver


class _Session:
    def __init__(self, row: FileRecord | None) -> None:
        self.row = row

    async def scalar(self, statement: Any) -> FileRecord | None:
        return self.row


def _wire(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> LocalStorageDriver:
    driver = LocalStorageDriver(tmp_path, "/storage")
    monkeypatch.setattr(file_service, "get_driver", lambda: driver)
    return driver


async def test_download_streams_through_driver(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    driver = _wire(monkeypatch, tmp_path)

    async def _body() -> Any:
        yield b"0123456789"

    await driver.save("2026/10/22/a.pdf", _body())
    row = FileRecord(
        storage="local",
        path="2026/10/22/a.pdf",
        url="/storage/2026/10/22/a.pdf",
        original_name="报价单.pdf",
        mime="application/pdf",
        size=3,  # 故意与真实大小不一致，Content-Length 必须按真实文件算
    )

    blob = await file_service.download_file(_Session(row), SimpleNamespace(), 1)

    assert blob.name == "报价单.pdf"
    assert blob.mime == "application/pdf"
    assert blob.size == 10
    assert b"".join([chunk async for chunk in blob.stream()]) == b"0123456789"
    assert b"".join([chunk async for chunk in blob.stream(offset=3, length=4)]) == b"3456"


async def test_download_missing_blob_is_not_found(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    _wire(monkeypatch, tmp_path)
    row = FileRecord(storage="local", path="gone.pdf", original_name="gone.pdf", mime="application/pdf")

    with pytest.raises(NotFound):
        await file_service.download_file(_Session(row), SimpleNamespace(), 1)


async def test_download_on_another_driver_is_not_found(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    _wire(monkeypatch, tmp_path)
    # 迁移期间会存在这种记录：库里有，但对象不在当前驱动的存储里。
    row = FileRecord(storage="s3", path="2026/10/22/a.pdf", original_name="a.pdf")

    with pytest.raises(NotFound):
        await file_service.download_file(_Session(row), SimpleNamespace(), 1)


async def test_download_of_unscoped_record_is_not_found(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    _wire(monkeypatch, tmp_path)
    with pytest.raises(NotFound):
        await file_service.download_file(_Session(None), SimpleNamespace(), 1)
