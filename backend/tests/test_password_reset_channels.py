"""找回密码渠道：配置开关打开 ≠ 真的能发出去。

prod 没有任何投递实现，若不收敛，登录页会显示"忘记密码"、用户填完表单才拿到 503。
这组用例锁住三处消费方的同一口径（发码前的校验、登录页初始化、公共配置）。
"""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any

import pytest

from cccms.core import config_store, delivery
from cccms.services import password_reset


@pytest.mark.parametrize(("is_prod", "expected"), [(False, ["email", "sms"]), (True, [])])
def test_deliverable_channels_depends_on_env(
    monkeypatch: pytest.MonkeyPatch, is_prod: bool, expected: list[str]
) -> None:
    monkeypatch.setattr(delivery, "get_settings", lambda: SimpleNamespace(is_prod=is_prod))
    assert delivery.deliverable_channels(["email", "sms"]) == expected


@pytest.mark.parametrize(("is_prod", "expected"), [(False, ["email"]), (True, [])])
async def test_channels_follow_the_same_rule(
    monkeypatch: pytest.MonkeyPatch, is_prod: bool, expected: list[str]
) -> None:
    async def _enabled(
        session: Any, name: str, default: bool = False, tenant_id: int | None = None
    ) -> bool:
        return name == "security.reset_email_enabled"

    monkeypatch.setattr(password_reset, "get_bool", _enabled)
    monkeypatch.setattr(delivery, "get_settings", lambda: SimpleNamespace(is_prod=is_prod))

    # session 只被 get_bool 使用，这里已被替换，传占位对象即可。
    assert await password_reset._channels(object(), 1) == expected


async def test_public_config_drops_unreachable_channels(monkeypatch: pytest.MonkeyPatch) -> None:
    async def _all_values(session: Any, tenant_id: int | None) -> dict[str, str]:
        return {"security.reset_email_enabled": "1", "security.reset_sms_enabled": "1"}

    monkeypatch.setattr(config_store, "_all_values", _all_values)

    monkeypatch.setattr(delivery, "get_settings", lambda: SimpleNamespace(is_prod=False))
    dev = await config_store.ui_payload(None, None)
    assert dev["security"]["reset_channels"] == ["email", "sms"]

    monkeypatch.setattr(delivery, "get_settings", lambda: SimpleNamespace(is_prod=True))
    prod = await config_store.ui_payload(None, None)
    assert prod["security"]["reset_channels"] == []
