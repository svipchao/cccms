"""存储驱动契约与"上传确实经过驱动"的回归。

F-01 的第一步只做"抽象层 + local 驱动"，所以要锁住两件事：
1. 驱动的行为（含路径穿越、半截文件清理、Range 读取）；
2. ``services/file.py`` 的上传确实走驱动，而不是绕过它直接写盘——否则换 S3 时这层就白抽象了。
"""

from __future__ import annotations

import hashlib
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest

from cccms.config import IMPLEMENTED_STORAGE_DRIVERS, Settings
from cccms.core.context import UserContext
from cccms.core.errors import ServiceUnavailable, ValidationFailed
from cccms.services import file as file_service
from cccms.services import storage
from cccms.services.storage import get_driver, implemented_drivers, local_driver
from cccms.services.storage.local import LocalStorageDriver, safe_join


def _driver(tmp_path: Path) -> LocalStorageDriver:
    return LocalStorageDriver(tmp_path, "/storage")


def _txt_files(root: Path) -> list[Path]:
    """同步读取目录（放在 async 用例里会被 ASYNC240 拦下）。"""
    return list(root.rglob("*.txt"))


def _read(path: Path) -> bytes:
    return path.read_bytes()


def _missing(path: Path) -> bool:
    return not path.exists()


async def _collect(stream: Any) -> bytes:
    return b"".join([chunk async for chunk in stream])


def test_registry_matches_config_constant() -> None:
    """两处清单必须一致：config 里的白名单决定"能不能启动"，注册表决定"能不能用"。"""
    assert set(implemented_drivers()) == set(IMPLEMENTED_STORAGE_DRIVERS)


def test_validate_storage_rejects_unimplemented_driver() -> None:
    assert Settings(_env_file=None, storage_driver="local").validate_storage() == []

    # 没实现的驱动：启动即失败，并列出当前支持的取值。
    problems = Settings(_env_file=None, storage_driver="oss").validate_storage()
    assert len(problems) == 1
    assert "oss" in problems[0]
    assert "local" in problems[0] and "s3" in problems[0]


def test_validate_storage_requires_s3_credentials() -> None:
    """声明了 s3 却缺桶/凭据 → 拒绝启动，别等第一个附件才 500。"""
    problems = Settings(_env_file=None, storage_driver="s3").validate_storage()
    assert len(problems) == 1
    assert "CCCMS_S3_BUCKET" in problems[0]

    ready = Settings(
        _env_file=None,
        storage_driver="s3",
        s3_bucket="cccms",
        s3_access_key="key",
        s3_secret_key="secret",
    )
    assert ready.validate_storage() == []


def test_get_driver_returns_local(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        storage, "get_settings", lambda: SimpleNamespace(storage_driver="local", storage_root=tmp_path,
                                                         storage_url_prefix="/storage")
    )
    driver = get_driver()
    assert isinstance(driver, LocalStorageDriver)
    assert driver.name == "local"


def test_get_driver_refuses_unimplemented_name(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(storage, "get_settings", lambda: SimpleNamespace(storage_driver="oss"))
    with pytest.raises(ServiceUnavailable):
        get_driver()


def test_local_driver_refuses_non_local(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    class _Fake:
        name = "s3"

    monkeypatch.setattr(storage, "_BUILDERS", {"s3": lambda settings: _Fake()})
    monkeypatch.setattr(storage, "get_settings", lambda: SimpleNamespace(storage_driver="s3"))
    with pytest.raises(ServiceUnavailable):
        local_driver()


def test_safe_join_rejects_traversal(tmp_path: Path) -> None:
    assert safe_join(tmp_path, "2026/10/22/a.png") == (tmp_path / "2026/10/22/a.png").resolve()
    with pytest.raises(ValidationFailed):
        safe_join(tmp_path, "../escape.png")
    with pytest.raises(ValidationFailed):
        safe_join(tmp_path, "2026/../../escape.png")


async def test_save_writes_bytes_and_reports_size(tmp_path: Path) -> None:
    driver = _driver(tmp_path)

    async def _body() -> Any:
        yield b"hello "
        yield b"world"

    written = await driver.save("2026/10/22/a.txt", _body())
    assert written == 11
    assert driver.blob_path("2026/10/22/a.txt").read_bytes() == b"hello world"
    assert driver.exists("2026/10/22/a.txt")


async def test_save_leaves_no_partial_object_when_source_fails(tmp_path: Path) -> None:
    driver = _driver(tmp_path)

    async def _body() -> Any:
        yield b"partial"
        raise ValidationFailed("文件超过上限 1MB")

    with pytest.raises(ValidationFailed):
        await driver.save("2026/10/22/bad.txt", _body())
    # 半截文件会变成"能下载但内容不全"的坏附件。
    assert not driver.exists("2026/10/22/bad.txt")


async def test_open_supports_offset_and_length(tmp_path: Path) -> None:
    driver = _driver(tmp_path)

    async def _body() -> Any:
        yield b"0123456789"

    await driver.save("a.bin", _body())
    assert await _collect(driver.open("a.bin")) == b"0123456789"
    # Range 读取：HTTP 分段请求依赖它，S3 驱动落地时同样要满足。
    assert await _collect(driver.open("a.bin", offset=3, length=4)) == b"3456"
    assert await _collect(driver.open("a.bin", offset=8, length=100)) == b"89"
    assert await _collect(driver.open("a.bin", offset=99, length=4)) == b""


async def test_delete_is_idempotent_and_exists_follows(tmp_path: Path) -> None:
    driver = _driver(tmp_path)

    async def _body() -> Any:
        yield b"x"

    await driver.save("a.txt", _body())
    await driver.delete("a.txt")
    await driver.delete("a.txt")
    assert driver.exists("a.txt") is False


def test_url_and_presign(tmp_path: Path) -> None:
    driver = LocalStorageDriver(tmp_path, "/storage/")
    assert driver.url("2026/10/22/a.png") == "/storage/2026/10/22/a.png"
    # 本地盘给不出限时直链，只能走 /storage 的鉴权链路。
    assert driver.presign_url("2026/10/22/a.png", 60) is None


async def test_local_quarantine_restore_purge(tmp_path: Path) -> None:
    driver = _driver(tmp_path)

    async def _body() -> Any:
        yield b"payload"

    await driver.save("2026/10/22/a.png", _body())
    assert driver.quarantine_path("2026/10/22/a.png") == (
        tmp_path.parent / "quarantine" / "2026/10/22/a.png"
    ).resolve()

    isolated = tmp_path.parent / "quarantine" / "2026/10/22/a.png"

    await driver.quarantine("2026/10/22/a.png")
    # 隔离区在静态目录之外：旧 URL 立即 404，回收站还能还原。
    assert driver.exists("2026/10/22/a.png") is False
    assert _read(isolated) == b"payload"

    await driver.restore("2026/10/22/a.png")
    assert _read(driver.blob_path("2026/10/22/a.png")) == b"payload"

    await driver.quarantine("2026/10/22/a.png")
    await driver.purge("2026/10/22/a.png")
    assert _missing(isolated)


async def test_local_quarantine_missing_is_noop(tmp_path: Path) -> None:
    driver = _driver(tmp_path)
    await driver.quarantine("never.png")
    await driver.restore("never.png")
    await driver.purge("never.png")


# --- 上传确实经过驱动 -------------------------------------------------------

_ACTOR = UserContext(
    id=9,
    username="uploader",
    nickname="Uploader",
    tenant_id=1,
    home_tenant_id=1,
    is_super_admin=False,
    permissions=frozenset(),
    role_ids=(),
    effective_role_ids=(),
    dept_ids=(),
    dept_subtree_ids=(),
    post_ids=(),
    data_scope=1,
    jti="t",
    token_exp=0,
    token_iat=0,
)


class _FakeUpload:
    def __init__(self, data: bytes, filename: str = "a.txt") -> None:
        self._data = data
        self.filename = filename

    async def read(self, size: int = -1) -> bytes:
        chunk = self._data[:size]
        self._data = self._data[size:]
        return chunk


class _FakeSession:
    """只实现 ``_create_record`` 用到的三件事，避免测试依赖 MySQL。"""

    def __init__(self) -> None:
        self.added: list[Any] = []

    def add(self, row: Any) -> None:
        self.added.append(row)

    async def commit(self) -> None:
        return None

    async def refresh(self, row: Any) -> None:
        return None


def _wire(monkeypatch: pytest.MonkeyPatch, tmp_path: Path, *, dedupe: Any = None) -> _FakeSession:
    driver = _driver(tmp_path)
    session = _FakeSession()
    monkeypatch.setattr(file_service, "get_driver", lambda: driver)
    monkeypatch.setattr(
        file_service,
        "get_settings",
        lambda: SimpleNamespace(upload_max_mb=1, upload_ext_set={"txt"}),
    )

    async def _category(session_: Any, actor: Any, category_id: Any) -> None:
        return None

    async def _dedupe(session_: Any, actor: Any, *, sha1: str, size: int) -> Any:
        return dedupe

    monkeypatch.setattr(file_service, "_assert_category", _category)
    monkeypatch.setattr(file_service, "_dedupe", _dedupe)
    monkeypatch.setattr(file_service, "_to_out", lambda row, **kwargs: row)
    return session


async def test_upload_writes_through_driver(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    session = _wire(monkeypatch, tmp_path)
    row = await file_service.upload_file(session, _ACTOR, _FakeUpload(b"hello world"))

    assert len(session.added) == 1
    record = session.added[0]
    assert record.storage == "local"
    assert record.url == f"/storage/{record.path}"
    assert record.size == 11
    assert record.sha1 == hashlib.sha1(b"hello world").hexdigest()  # noqa: S324 - 与实现同源
    assert (tmp_path / record.path).read_bytes() == b"hello world"
    assert row.storage == "local"


async def test_upload_dedupe_hit_removes_new_blob(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    existing = SimpleNamespace(id=3, create_by=1, storage="local")
    session = _wire(monkeypatch, tmp_path, dedupe=existing)

    async def _names(session_: Any, ids: set[int]) -> dict[int, str]:
        return {1: "Uploader"}

    monkeypatch.setattr(file_service, "_names", _names)

    await file_service.upload_file(session, _ACTOR, _FakeUpload(b"dup"))
    # 秒传命中：新写的那份必须删掉，只保留既有记录指向的对象。
    assert _txt_files(tmp_path) == []
    assert session.added == []


async def test_upload_over_limit_removes_partial(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    _wire(monkeypatch, tmp_path)
    with pytest.raises(ValidationFailed):
        await file_service.upload_file(object(), _ACTOR, _FakeUpload(b"x" * (2 * 1024 * 1024)))
    assert _txt_files(tmp_path) == []


async def test_upload_rejects_unknown_extension(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    _wire(monkeypatch, tmp_path)
    with pytest.raises(ValidationFailed):
        await file_service.upload_file(object(), _ACTOR, _FakeUpload(b"x", filename="a.exe"))
