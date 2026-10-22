"""口令历史（S-02）：拒绝改回旧口令，并裁掉超出保留份数的老行。

只测服务层判断与写库意图（语句类型、写入内容），不连 MySQL：
真正落表由 ``alembic upgrade head`` 与 ``tests/test_http_contract`` 之外的集成环境覆盖。
"""

from __future__ import annotations

from collections.abc import Sequence
from types import SimpleNamespace

import pytest
from sqlalchemy import Delete, Select

from cccms.core.errors import ValidationFailed
from cccms.core.security import hash_password
from cccms.models.user import PasswordHistory
from cccms.services import password_history


class _Scalars:
    def __init__(self, rows: Sequence[object]) -> None:
        self._rows = list(rows)

    def all(self) -> list[object]:
        return self._rows


class _Result:
    def __init__(self, rows: Sequence[object]) -> None:
        self._rows = list(rows)

    def scalars(self) -> _Scalars:
        return _Scalars(self._rows)


class _Session:
    """记录 add / execute / flush，够断言「读了什么、写了什么、删没删」。"""

    def __init__(self, results: Sequence[Sequence[object]] = ()) -> None:
        self._results = [list(item) for item in results]
        self.added: list[object] = []
        self.statements: list[object] = []
        self.flushed = 0

    async def execute(self, statement: object) -> _Result:
        self.statements.append(statement)
        return _Result(self._results.pop(0) if self._results else [])

    def add(self, obj: object) -> None:
        self.added.append(obj)

    async def flush(self) -> None:
        self.flushed += 1


def _settings(limit: int) -> SimpleNamespace:
    return SimpleNamespace(password_history=limit)


async def test_reuse_of_recent_password_is_rejected() -> None:
    old_hash = hash_password("OldPassw0rd!")
    session = _Session([[old_hash]])

    with pytest.raises(ValidationFailed) as excinfo:
        await password_history.ensure_not_reused(
            session, tenant_id=1, user_id=2, password="OldPassw0rd!"
        )

    assert "最近 5 次" in str(excinfo.value)


async def test_unrelated_password_passes() -> None:
    session = _Session([[hash_password("OldPassw0rd!")]])

    await password_history.ensure_not_reused(
        session, tenant_id=1, user_id=2, password="BrandNew#2026"
    )


async def test_disabled_when_limit_is_zero(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(password_history, "get_settings", lambda: _settings(0))
    session = _Session([[hash_password("OldPassw0rd!")]])

    # 关闭历史校验时连查询都不发：limit=0 不该再为改密多打一次库。
    await password_history.ensure_not_reused(
        session, tenant_id=1, user_id=2, password="OldPassw0rd!"
    )
    await password_history.remember(session, tenant_id=1, user_id=2, password_hash="x" * 32)

    assert session.statements == []
    assert session.added == []


async def test_remember_stores_old_hash_and_prunes_overflow() -> None:
    session = _Session([[11, 12]])

    await password_history.remember(
        session, tenant_id=3, user_id=4, password_hash="$argon2id$old"
    )

    assert session.flushed == 1
    assert len(session.added) == 1
    row = session.added[0]
    assert isinstance(row, PasswordHistory)
    assert (row.tenant_id, row.user_id, row.password_hash) == (3, 4, "$argon2id$old")
    # 先查超额的 id，再一条 DELETE 批量清理；不是逐行 delete。
    assert isinstance(session.statements[0], Select)
    assert isinstance(session.statements[1], Delete)


async def test_remember_skips_empty_hash() -> None:
    session = _Session()

    await password_history.remember(session, tenant_id=3, user_id=4, password_hash="")

    assert session.added == []
    assert session.statements == []
