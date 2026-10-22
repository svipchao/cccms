"""测试进程不读工作区里的 ``.env`` / ``.dev-secrets.env``，也不连 Redis/MySQL。

单元测试断言的是代码行为，不是某台机器的配置。此前 ``Settings`` 会连带读
``backend/.env``，于是开发者本地 ``CCCMS_DEBUG=true`` 时
``test_prod_hardening_rejects_default_credentials`` 必然失败、CI 上却通过。
这里在收集测试之前清空 ``env_file``：显式传参仍然优先级最高，测试结论只取决于测试本身。

需要读取真实配置的集成测试应显式构造 ``Settings(_env_file=...)``，而不是依赖本文件。
"""

from __future__ import annotations

import pytest

from cccms.config import Settings

Settings.model_config["env_file"] = ()


@pytest.fixture(autouse=True)
def _no_tenant_resolution(monkeypatch: pytest.MonkeyPatch) -> None:
    """域名→租户解析一律返回"未绑定"。

    真实实现在 Redis 或数据库不可用时抛异常，中间件据此返回 503（fail-closed）。
    没有这条桩，所有契约测试都会变 503。要验证 fail-closed 的用例，
    在测试体内再 patch 一次即可覆盖本桩。
    """

    async def _none(host: str | None) -> None:
        return None

    monkeypatch.setattr("cccms.core.middleware.tenant_from_host", _none)
