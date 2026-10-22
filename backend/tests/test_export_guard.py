"""导出文件的越界与归属校验（E-01）。

导出目录里装的是日志与业务数据，``file_path`` 又来自数据库，两处都值得盯：
路径逃逸（``../``）和"拿到了别人的 task_id"。这里用桩会话断言 SQL 里确实带了
``tenant_id`` 与 ``user_id``，不依赖 MySQL。
"""

from __future__ import annotations

from datetime import timedelta
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest

from cccms.core.context import UserContext
from cccms.core.errors import NotFound, ValidationFailed
from cccms.core.time import now
from cccms.models.export import ExportTask
from cccms.services import export as export_service

ACTOR = UserContext(
    id=9,
    username="exporter",
    nickname="Exporter",
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


class _Session:
    def __init__(self, row: ExportTask | None) -> None:
        self.row = row
        self.statement: Any = None

    async def scalar(self, statement: Any) -> ExportTask | None:
        self.statement = statement
        return self.row


def _wire(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> Path:
    root = tmp_path / "exports"
    root.mkdir()
    monkeypatch.setattr(export_service, "get_settings", lambda: SimpleNamespace(export_root=root))
    return root


def _task(file_path: str, **overrides: Any) -> ExportTask:
    values: dict[str, Any] = {
        "id": 7,
        "tenant_id": 1,
        "user_id": 9,
        "status": export_service.STATUS_DONE,
        "file_path": file_path,
        "type": "log",
    }
    values.update(overrides)
    return ExportTask(**values)


def _sql(statement: Any) -> str:
    return str(statement.compile(compile_kwargs={"literal_binds": True}))


async def test_download_returns_file_inside_root(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    root = _wire(monkeypatch, tmp_path)
    target = root / "log-7.csv"
    target.write_text("a,b\n", encoding="utf-8")

    path, name = await export_service.download_path(_Session(_task("log-7.csv")), ACTOR, 7)

    assert path == target.resolve()
    assert name == "log-7.csv"


async def test_download_rejects_path_escape(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    root = _wire(monkeypatch, tmp_path)
    # 库里的 file_path 若被写脏，下载接口不能交出导出目录之外的文件。
    (tmp_path / "secret.txt").write_text("token", encoding="utf-8")

    with pytest.raises(NotFound):
        await export_service.download_path(_Session(_task("../secret.txt")), ACTOR, 7)

    absolute = str((tmp_path / "secret.txt").resolve())
    with pytest.raises(NotFound):
        await export_service.download_path(_Session(_task(absolute)), ACTOR, 7)
    assert root.is_dir()


async def test_download_rejects_missing_file(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    _wire(monkeypatch, tmp_path)
    with pytest.raises(NotFound):
        await export_service.download_path(_Session(_task("never.csv")), ACTOR, 7)


async def test_download_rejects_unfinished_or_expired(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    root = _wire(monkeypatch, tmp_path)
    (root / "log-7.csv").write_text("a", encoding="utf-8")

    with pytest.raises(ValidationFailed):
        await export_service.download_path(
            _Session(_task("log-7.csv", status="running")), ACTOR, 7
        )
    with pytest.raises(NotFound):
        await export_service.download_path(
            _Session(_task("log-7.csv", expires_at=now() - timedelta(minutes=1))), ACTOR, 7
        )


async def test_download_scopes_task_to_tenant_and_owner(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    root = _wire(monkeypatch, tmp_path)
    (root / "log-7.csv").write_text("a", encoding="utf-8")
    session = _Session(_task("log-7.csv"))

    await export_service.download_path(session, ACTOR, 7)
    text = _sql(session.statement)

    # 只靠 task_id 是不够的：必须同时钉住租户与本人，否则猜 id 就能下别人的导出。
    assert "sys_export_task.tenant_id = 1" in text
    assert "sys_export_task.user_id = 9" in text


def test_purge_refuses_outside_root(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    _wire(monkeypatch, tmp_path)
    outside = tmp_path / "keep.txt"
    outside.write_text("x", encoding="utf-8")

    assert export_service.purge_file(str(outside)) is False
    assert outside.exists()
    assert export_service.purge_file("../keep.txt") is False
    assert export_service.purge_file(None) is False


def test_purge_deletes_inside_root(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    root = _wire(monkeypatch, tmp_path)
    target = root / "old.csv"
    target.write_text("x", encoding="utf-8")

    assert export_service.purge_file("old.csv") is True
    assert not target.exists()
