"""SMTP 投递能力与找回密码的连接方式。

两条底线：
1. 没配 SMTP 时不能假装发出去（否则用户永远收不到码）；
2. 投递失败不能在 Redis 留下验证码，也不能把用户锁进冷却期。
"""

from __future__ import annotations

import re
import smtplib
from types import SimpleNamespace
from typing import Any

import pytest

from cccms.config import Settings
from cccms.core import delivery
from cccms.core.errors import ServiceUnavailable, ValidationFailed
from cccms.services import mail, password_reset

KEY = "cccms:reset:1:7"


def _settings(**overrides: Any) -> Settings:
    values: dict[str, Any] = {
        "smtp_host": "smtp.example.com",
        "smtp_port": 587,
        "smtp_security": "starttls",
        "smtp_from": "noreply@example.com",
        "smtp_from_name": "CCCMS",
    }
    values.update(overrides)
    return Settings(_env_file=None, **values)


def test_build_message_sets_headers_and_body() -> None:
    message = mail.build_message(
        to="alice@example.com", subject="验证码", text="您的验证码是 123456", settings=_settings()
    )

    assert message["To"] == "alice@example.com"
    assert "noreply@example.com" in str(message["From"])
    assert "CCCMS" in str(message["From"])
    assert message["Subject"] == "验证码"
    assert "123456" in message.get_content()
    assert message["Message-ID"]


def test_smtp_ready_requires_host_and_from() -> None:
    assert _settings().smtp_ready is True
    assert _settings(smtp_host="").smtp_ready is False
    assert _settings(smtp_from="").smtp_ready is False


async def test_send_mail_without_config_fails_loudly() -> None:
    with pytest.raises(ServiceUnavailable):
        await mail.send_mail(
            to="alice@example.com", subject="s", text="t", settings=_settings(smtp_host="")
        )


async def test_send_mail_maps_smtp_error_without_leaking_address(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def _boom(message: Any, cfg: Any) -> None:
        raise smtplib.SMTPServerDisconnected("connection lost")

    monkeypatch.setattr(mail, "_deliver", _boom)
    with pytest.raises(ServiceUnavailable) as excinfo:
        await mail.send_mail(
            to="alice@example.com", subject="s", text="t", settings=_settings()
        )
    # 收件人不该出现在返回给调用方（进而可能进前端）的错误文案里。
    assert "alice@example.com" not in str(excinfo.value)


async def test_send_mail_delegates_to_blocking_delivery(monkeypatch: pytest.MonkeyPatch) -> None:
    seen: list[str] = []

    def _ok(message: Any, cfg: Any) -> None:
        seen.append(str(message["To"]))

    monkeypatch.setattr(mail, "_deliver", _ok)
    await mail.send_mail(to="alice@example.com", subject="s", text="t", settings=_settings())
    assert seen == ["alice@example.com"]


class _FakeRedis:
    def __init__(self) -> None:
        self.store: dict[str, str] = {}

    async def set(
        self, key: str, value: str, ex: int | None = None, nx: bool = False
    ) -> bool | None:
        if nx and key in self.store:
            return None
        self.store[key] = value
        return True

    async def delete(self, *keys: str) -> int:
        return sum(1 for key in keys if self.store.pop(key, None) is not None)


async def _prepare(
    monkeypatch: pytest.MonkeyPatch,
    fake: _FakeRedis,
    send: Any,
    *,
    user: SimpleNamespace | None = None,
) -> None:
    async def _tenant(session: Any, payload: dict[str, Any]) -> int:
        return 1

    async def _find(session: Any, tenant_id: int, payload: dict[str, Any]) -> SimpleNamespace | None:
        return user

    async def _bool(
        session: Any, name: str, default: bool = False, tenant_id: int | None = None
    ) -> bool:
        return name == "security.reset_email_enabled"

    async def _int(
        session: Any, name: str, default: int = 0, tenant_id: int | None = None
    ) -> int:
        return default

    cfg = SimpleNamespace(is_prod=True, smtp_ready=True)
    monkeypatch.setattr(password_reset, "_resolve_tenant", _tenant)
    monkeypatch.setattr(password_reset, "_find_user", _find)
    monkeypatch.setattr(password_reset, "get_bool", _bool)
    monkeypatch.setattr(password_reset, "get_int", _int)
    monkeypatch.setattr(password_reset, "get_redis", lambda: fake)
    monkeypatch.setattr(password_reset, "get_settings", lambda: cfg)
    monkeypatch.setattr(delivery, "get_settings", lambda: cfg)
    monkeypatch.setattr(password_reset.mail, "send_mail", send)


def _customer(**overrides: Any) -> SimpleNamespace:
    values: dict[str, Any] = {
        "id": 7,
        "username": "alice",
        "nickname": "Alice",
        "email": "alice@example.com",
        "phone": None,
        "status": 1,
    }
    values.update(overrides)
    return SimpleNamespace(**values)


async def test_successful_send_stores_code_after_delivery(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    fake = _FakeRedis()
    sent: list[dict[str, Any]] = []

    async def _send(**kwargs: Any) -> None:
        # 落码必须发生在投递之后：发送时 Redis 里还不该有码。
        assert KEY not in fake.store
        sent.append(kwargs)

    await _prepare(monkeypatch, fake, _send, user=_customer())
    result = await password_reset.request_code(object(), {"username": "alice"})

    assert result["username"] == "alice"
    assert re.fullmatch(r"\d{6}", fake.store[KEY]) is not None
    assert fake.store[f"{KEY}:interval"] == "1"
    assert sent[0]["to"] == "alice@example.com"
    assert fake.store[KEY] in sent[0]["text"]


async def test_failed_send_leaves_no_code_and_no_cooldown(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    fake = _FakeRedis()

    async def _boom(**kwargs: Any) -> None:
        raise ServiceUnavailable("邮件发送失败，请稍后再试", message_key=None)

    await _prepare(monkeypatch, fake, _boom, user=_customer())
    with pytest.raises(ServiceUnavailable):
        await password_reset.request_code(object(), {"username": "alice"})

    # 既没有发不出去的码，也没有把用户锁在冷却期里。
    assert fake.store == {}


async def test_account_without_email_looks_like_unknown_account(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    fake = _FakeRedis()

    async def _send(**kwargs: Any) -> None:
        raise AssertionError("没有收件地址时不应投递")

    await _prepare(monkeypatch, fake, _send, user=_customer(email=None))
    result = await password_reset.request_code(object(), {"username": "alice"})

    # 与"账号不存在"返回同一外形，不能用来探测谁绑了邮箱。
    assert result["username"] == ""
    assert fake.store == {}


async def test_cooldown_blocks_second_request(monkeypatch: pytest.MonkeyPatch) -> None:
    fake = _FakeRedis()
    fake.store[f"{KEY}:interval"] = "1"

    async def _send(**kwargs: Any) -> None:
        raise AssertionError("冷却期内不应投递")

    await _prepare(monkeypatch, fake, _send, user=_customer())
    with pytest.raises(ValidationFailed):
        await password_reset.request_code(object(), {"username": "alice"})
