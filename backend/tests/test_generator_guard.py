"""代码生成器的 dev-only 守卫必须在**每个**入口生效。

``_ensure_available`` 原先只被 ``list_tables`` 调用，导致 prod 环境仍可通过
``/columns``、``/preview``、``/generate`` 枚举表结构并往源码树写文件。
这组用例直接调用路由函数（不经 HTTP、不连 DB），只验证守卫位置。
"""

from __future__ import annotations

from collections.abc import Callable, Coroutine
from types import SimpleNamespace
from typing import Any

import pytest

from cccms.api import generator as generator_api
from cccms.core.context import UserContext
from cccms.core.errors import ValidationFailed
from cccms.schemas.generator import GeneratorRequest
from cccms.services import generator as generator_service

_USER = UserContext(
    id=1,
    username="tester",
    nickname="Tester",
    tenant_id=1,
    home_tenant_id=1,
    is_super_admin=True,
    permissions=frozenset(),
    role_ids=(),
    effective_role_ids=(),
    dept_ids=(),
    dept_subtree_ids=(),
    post_ids=(),
    data_scope=1,
    jti="test",
    token_exp=0,
    token_iat=0,
)

_PAYLOAD = GeneratorRequest(table="sys_user")

_ENDPOINTS: dict[str, Callable[[], Coroutine[Any, Any, dict[str, object]]]] = {
    "tables": lambda: generator_api.tables(user=_USER),
    "columns": lambda: generator_api.columns(table="sys_user", user=_USER),
    "preview": lambda: generator_api.preview(payload=_PAYLOAD, user=_USER),
    "generate": lambda: generator_api.generate(payload=_PAYLOAD, user=_USER),
}


@pytest.fixture
def _prod(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(generator_service, "get_settings", lambda: SimpleNamespace(is_prod=True))


@pytest.mark.usefixtures("_prod")
@pytest.mark.parametrize("name", list(_ENDPOINTS))
async def test_every_generator_endpoint_is_dev_only(name: str) -> None:
    with pytest.raises(ValidationFailed):
        await _ENDPOINTS[name]()


def test_dev_environment_still_usable() -> None:
    # dev/test 下守卫不应误伤（默认 env=dev）。
    generator_service.ensure_available()
