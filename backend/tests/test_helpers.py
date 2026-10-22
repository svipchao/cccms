"""Unit tests for pure helpers that guard security boundaries."""

from __future__ import annotations

import asyncio
import logging

import pytest
from sqlalchemy import select
from starlette.requests import Request

from cccms.config import DEFAULT_ADMIN_PASSWORD, Settings
from cccms.core import audit, auth_deps
from cccms.core.audit import LogEvent
from cccms.core.codes import BizCode, default_code
from cccms.core.context import UserContext
from cccms.core.envelope import localize
from cccms.core.errors import Forbidden, Unauthorized, ValidationFailed
from cccms.core.metrics import REGISTRY
from cccms.core.middleware import invalidates_auth_cache, invalidates_dict_cache
from cccms.core.params import as_int
from cccms.core.ratelimit import is_heavy_path
from cccms.core.rbac import merge_data_scope
from cccms.core.scope import _mask_value, redact_field_value
from cccms.core.session import token_before_cutoff
from cccms.core.sorting import apply_sort
from cccms.core.tenant import _expired
from cccms.core.time import now
from cccms.models.log import OperationLog
from cccms.schemas.log import LogQuery
from cccms.services.file import _chunk_total, _meta_int, _safe_name
from cccms.services.generator import _class_name, _snake
from cccms.services.log import EXPORT_COLUMNS, _csv_header, _csv_line, _csv_safe
from cccms.services.role import _assert_node_delegation, _assert_scope_delegation


def _actor(**overrides: object) -> UserContext:
    values: dict[str, object] = {
        "id": 1,
        "username": "tester",
        "nickname": "Tester",
        "tenant_id": 1,
        "home_tenant_id": 1,
        "is_super_admin": False,
        "permissions": frozenset({"cccms:role:index"}),
        "role_ids": (),
        "effective_role_ids": (),
        "dept_ids": (),
        "dept_subtree_ids": (),
        "post_ids": (),
        "data_scope": 4,
        "jti": "",
        "token_exp": 0,
        "token_iat": 0,
    }
    values.update(overrides)
    return UserContext(**values)  # type: ignore[arg-type]


def test_safe_name_strips_directories() -> None:
    assert _safe_name("../../etc/passwd") == "passwd"
    assert _safe_name(r"C:\temp\a.txt") == "a.txt"
    assert _safe_name("logo.png") == "logo.png"


@pytest.mark.parametrize("value", ["", "  ", ".", "..", "\x00"])
def test_safe_name_rejects_invalid(value: str) -> None:
    with pytest.raises(ValidationFailed):
        _safe_name(value)


def test_safe_name_truncates_to_255() -> None:
    assert len(_safe_name("a" * 400 + ".txt")) == 255


def test_mask_value_keeps_partial_display() -> None:
    assert _mask_value("phone", "13800138000") == "138****8000"
    assert _mask_value("id_card", "110101199001011234") == "1101********1234"
    assert _mask_value("nickname", "abcdef") == "ab**ef"
    assert _mask_value("nickname", "") == ""


def test_csv_safe_prefixes_formula_characters() -> None:
    assert _csv_safe("=1+1") == "'=1+1"
    assert _csv_safe("@cmd") == "'@cmd"
    assert _csv_safe("normal") == "normal"
    assert _csv_safe(None) == ""


def test_expired_handles_none_past_and_future() -> None:
    assert _expired(None) is False
    assert _expired("2000-01-01 00:00:00") is True
    assert _expired("2999-01-01 00:00:00") is False


def test_generator_naming() -> None:
    assert _class_name("sys_user_role") == "UserRole"
    assert _class_name("ks_order") == "Order"
    assert _snake("UserRole") == "user_role"


def test_role_delegation_cannot_widen_scope() -> None:
    actor = _actor(data_scope=4)
    with pytest.raises(Forbidden):
        _assert_scope_delegation(actor, 1)
    _assert_scope_delegation(actor, 4)
    _assert_scope_delegation(actor, 5)


def test_role_delegation_cannot_grant_unknown_nodes() -> None:
    actor = _actor()
    _assert_node_delegation(actor, ["cccms:role:index"])
    with pytest.raises(Forbidden):
        _assert_node_delegation(actor, ["cccms:role:index", "cccms:tenant:index"])


def test_super_admin_bypasses_delegation_checks() -> None:
    actor = _actor(is_super_admin=True, permissions=frozenset(), data_scope=1)
    _assert_scope_delegation(actor, 1)
    _assert_node_delegation(actor, ["cccms:tenant:index"])


def test_multi_role_data_scope_takes_strictest() -> None:
    # 1=全部 / 4=仅本人：多角色合并不得被宽范围角色放大。
    assert merge_data_scope([1, 4]) == 4
    assert merge_data_scope([3, 2]) == 3
    assert merge_data_scope([]) == 4


def test_token_cutoff_is_strict_and_monotonic() -> None:
    assert token_before_cutoff(iat=100, cutoff=0) is False
    assert token_before_cutoff(iat=99, cutoff=100) is True
    # 同一秒签发的令牌（改密后立刻重新登录）不能被自己的水位挡住。
    assert token_before_cutoff(iat=100, cutoff=100) is False


@pytest.mark.parametrize(
    ("path", "expected"),
    [
        ("/api/log/export", True),
        ("/api/upgrade/status", True),
        ("/api/generator/table", True),
        ("/api/user/index", False),
        ("/api/file/list", False),
    ],
)
def test_heavy_path_matches_real_api_prefixes(path: str, expected: bool) -> None:
    assert is_heavy_path(path) is expected


def test_redact_field_value_applies_rules() -> None:
    assert redact_field_value("phone", "hidden", "13800138000") == ""
    assert redact_field_value("phone", "mask", "13800138000") == "138****8000"
    assert redact_field_value("phone", "readonly", "13800138000") == "13800138000"
    assert redact_field_value("phone", "mask", None) is None


def test_business_code_is_decoupled_from_http_status() -> None:
    assert Unauthorized().status_code == 401
    assert Unauthorized().code == int(BizCode.UNAUTHORIZED)
    # 登录失败与“未登录”同为 401，但业务码不同：前端可分别处理。
    failed = Unauthorized("x", code=BizCode.LOGIN_FAILED)
    assert failed.status_code == 401
    assert failed.code == int(BizCode.LOGIN_FAILED)
    assert ValidationFailed().status_code == 422
    assert ValidationFailed().code == int(BizCode.INVALID_PARAM)
    assert default_code(503) is BizCode.UNAVAILABLE
    assert default_code(418) is BizCode.INVALID_PARAM


def test_error_message_is_localized_by_accept_language() -> None:
    zh = Request({"type": "http", "headers": [(b"accept-language", b"zh-CN")], "query_string": b""})
    en = Request({"type": "http", "headers": [(b"accept-language", b"en-US")], "query_string": b""})
    assert localize(zh, "未登录", "error.unauthorized") == "未登录"
    assert localize(en, "未登录", "error.unauthorized") == "Not signed in"
    # 没有文案键的服务自定义消息原样返回，不会被翻译覆盖。
    assert localize(en, "用户名已存在", None) == "用户名已存在"
    assert localize(en, "用户名已存在", "error.missing_key") == "用户名已存在"


def test_auth_and_dict_cache_invalidation_scope() -> None:
    assert invalidates_auth_cache("/api/role/save", "POST") is True
    assert invalidates_auth_cache("/api/user/update", "POST") is True
    assert invalidates_auth_cache("/api/role/list", "GET") is False
    assert invalidates_auth_cache("/api/notice/save", "POST") is False
    assert invalidates_dict_cache("/api/dict/updateData", "POST") is True
    assert invalidates_dict_cache("/api/dict/data", "GET") is False


def test_export_csv_line_keeps_columns_and_masking() -> None:
    row = OperationLog(
        id=1,
        username="tester",
        status=1,
        message="login",
        method="POST",
        path="/api/auth/login",
        node="cccms:auth:login",
        title="登录",
        trace_id="t1",
        action_id="a1",
        session_id="s1",
        ip="13800138000",
        status_code=200,
        cost=12,
        create_time=now(),
    )
    header = _csv_header()
    plain = _csv_line(row, {})
    masked = _csv_line(row, {"ip": "mask"})
    assert header.count(",") == len(EXPORT_COLUMNS) - 1
    assert plain.count(",") == len(EXPORT_COLUMNS) - 1
    assert "13800138000" in plain
    assert "13800138000" not in masked


def test_prod_hardening_rejects_default_credentials() -> None:
    insecure = Settings(
        env="prod",
        jwt_secret="j" * 40,
        data_encrypt_key="data-key",
        audit_signing_key="audit-key",
        cookie_secure=True,
        debug=True,
        cors_origins=[],
        admin_password=DEFAULT_ADMIN_PASSWORD,
    )
    problems = insecure.validate_secrets()
    assert any("CCCMS_ADMIN_PASSWORD" in item for item in problems)
    assert any("CCCMS_CORS_ORIGINS" in item for item in problems)
    assert any("CCCMS_DEBUG" in item for item in problems)

    hardened = Settings(
        env="prod",
        jwt_secret="j" * 40,
        data_encrypt_key="data-key",
        audit_signing_key="audit-key",
        cookie_secure=True,
        secret_key="s" * 40,
        cors_origins=["https://admin.example.com"],
        admin_password="Str0ng-Passw0rd!",
    )
    assert hardened.validate_secrets() == []


def test_meta_int_tolerates_untrusted_chunk_metadata() -> None:
    # 元数据来自缓存, 可能被改写或残留旧格式, 解析必须不抛异常.
    assert _meta_int({"size": "1024"}, "size") == 1024
    assert _meta_int({"size": 1024}, "size") == 1024
    assert _meta_int({"user_id": 7}, "user_id") == 7
    assert _meta_int({}, "tenant_id") == 0
    for bad in ("abc", "", "10.5", None, [], {}, True, 1.5):
        assert _meta_int({"size": bad}, "size") == 0


def test_as_int_rejects_non_numeric_payload_fields() -> None:
    # 前端 flat JSON 的 id 可能是数字或数字字符串, 其余一律 422 而不是 500。
    assert as_int(12) == 12
    assert as_int("12") == 12
    assert as_int(" 12 ") == 12
    assert as_int(12.0) == 12
    for bad in ("12.5", "", "abc", True, None, [], 1.5, {}):
        with pytest.raises(ValidationFailed):
            as_int(bad)


def test_chunk_total_bounds_by_declared_size() -> None:
    chunk = 5 * 1024 * 1024
    # 声明大小缺失或非法时至少允许 1 片, 避免索引 0 也被拒绝.
    assert _chunk_total(0, chunk) == 1
    assert _chunk_total(-1, chunk) == 1
    assert _chunk_total(1, chunk) == 1
    assert _chunk_total(chunk, chunk) == 1
    assert _chunk_total(chunk + 1, chunk) == 2
    assert _chunk_total(chunk * 3, chunk) == 3
    # 分片大小配置异常为 0 时不得除零崩溃。
    assert _chunk_total(chunk, 0) == 1


def test_apply_sort_uses_whitelist_and_falls_back() -> None:
    # 查询模型必须真的接收 sort/order：StrictModel 下漏字段会被静默丢弃，
    # 前端就会出现"箭头翻转、数据不动"。
    params = LogQuery(sort="cost", order="desc")
    assert (params.sort, params.order) == ("cost", "desc")

    sortable = {"cost": OperationLog.cost, "create_time": OperationLog.create_time}

    # 白名单命中：按请求列排序，并追加主键保证分页稳定。
    picked = apply_sort(
        select(OperationLog),
        sortable,
        id_column=OperationLog.id,
        fallback=(OperationLog.id.desc(),),
        sort="cost",
        order="desc",
    )
    picked_sql = str(picked)
    assert picked_sql.rsplit("ORDER BY", maxsplit=1)[-1].strip() == "sys_log.cost DESC, sys_log.id ASC"

    # 未指定或指定了非白名单列（如 message）：一律回到该模块的 fallback，不得拼进 ORDER BY。
    for sort, order in ((None, "asc"), ("message", "desc")):
        dropped = str(
            apply_sort(
                select(OperationLog),
                sortable,
                id_column=OperationLog.id,
                fallback=(OperationLog.id.desc(),),
                sort=sort,
                order=order,
            )
        )
        order_by = dropped.rsplit("ORDER BY", maxsplit=1)[-1].strip()
        assert order_by == "sys_log.id DESC"


def _log_event() -> LogEvent:
    return LogEvent(
        tenant_id=1,
        user_id=1,
        username="tester",
        status=1,
        message="login",
        method="POST",
        path="/api/auth/login",
        node="cccms:auth:login",
        title="登录",
        trace_id="t1",
        action_id="a1",
        session_id="s1",
        ip="127.0.0.1",
        ua="pytest",
        params=None,
        result=None,
        status_code=200,
        cost=1,
        create_time=now(),
    )


def test_audit_queue_overflow_is_exposed_as_metric(monkeypatch: pytest.MonkeyPatch) -> None:
    # 队列满时丢弃审计行，必须留下 Prometheus 计数，否则"审计缺失"不可观测。
    monkeypatch.setattr(audit, "_queue", asyncio.Queue(maxsize=1))
    monkeypatch.setattr(audit, "_dropped_logs", 0)
    before = REGISTRY.get_sample_value("cccms_audit_dropped_total") or 0.0

    audit.enqueue(_log_event())
    audit.enqueue(_log_event())  # 第二次必然 QueueFull

    after = REGISTRY.get_sample_value("cccms_audit_dropped_total") or 0.0
    assert after == before + 1


async def test_clear_online_for_leaves_a_trace_when_redis_fails(
    monkeypatch: pytest.MonkeyPatch, caplog: pytest.LogCaptureFixture
) -> None:
    class _BoomRedis:
        async def delete(self, *keys: str) -> None:
            raise RuntimeError("redis down")

        async def zrem(self, *args: object) -> None:
            raise RuntimeError("redis down")

    monkeypatch.setattr(auth_deps, "get_redis", _BoomRedis)
    with caplog.at_level(logging.WARNING, logger="cccms.auth"):
        await auth_deps.clear_online_for("jti-abc")

    assert "clear_online_for failed jti=jti-abc" in caplog.text
