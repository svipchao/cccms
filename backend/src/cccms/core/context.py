from __future__ import annotations

import secrets
from contextvars import ContextVar
from dataclasses import dataclass, field
from datetime import datetime
from typing import TYPE_CHECKING, Any

from cccms.core.time import now

if TYPE_CHECKING:
    from cccms.core.scope import ScopePlan


@dataclass(frozen=True, slots=True)
class TenantContext:
    id: int
    code: str
    name: str
    is_platform: bool
    domain: str | None
    timezone: str = "Asia/Shanghai"
    # 到期时间（字符串，便于写入 Redis 缓存）；None 表示永不过期。
    expire_at: str | None = None


@dataclass(frozen=True, slots=True)
class UserContext:
    id: int
    username: str
    nickname: str
    tenant_id: int
    home_tenant_id: int
    is_super_admin: bool
    permissions: frozenset[str]
    role_ids: tuple[int, ...]
    effective_role_ids: tuple[int, ...]
    dept_ids: tuple[int, ...]
    dept_subtree_ids: tuple[int, ...]
    post_ids: tuple[int, ...]
    data_scope: int
    jti: str
    token_exp: int
    token_iat: int
    may_decrypt_fields: bool = False


@dataclass(frozen=True, slots=True)
class RouteMeta:
    slug: str | None
    title: str
    no_auth: bool = False
    no_login: bool = False
    methods: tuple[str, ...] = ()


@dataclass(slots=True)
class RequestContext:
    trace_id: str
    action_id: str
    session_id: str
    method: str
    path: str
    ip: str
    ua: str
    started_at: datetime = field(default_factory=now)
    tenant: TenantContext | None = None
    tenant_locked: bool = False
    user: UserContext | None = None
    auth_error: str | None = None
    route: RouteMeta | None = None
    scope_plan: ScopePlan | None = None
    csrf_token: str = ""
    response_headers: dict[str, str] = field(default_factory=dict)
    hidden_fields: set[str] = field(default_factory=set)
    readonly_fields: set[str] = field(default_factory=set)
    mask_fields: dict[str, str] = field(default_factory=dict)
    encrypt_fields: set[str] = field(default_factory=set)
    extra: dict[str, Any] = field(default_factory=dict)


_ctx: ContextVar[RequestContext | None] = ContextVar("cccms_request_context", default=None)


def current_context() -> RequestContext | None:
    return _ctx.get()


def require_context() -> RequestContext:
    ctx = _ctx.get()
    if ctx is None:  # pragma: no cover - only happens outside a request
        raise RuntimeError("no request context; this code must run inside an HTTP request")
    return ctx


def set_context(ctx: RequestContext) -> object:
    return _ctx.set(ctx)


def reset_context(token: object) -> None:
    _ctx.reset(token)  # type: ignore[arg-type]


def new_trace_id() -> str:
    return now().strftime("%Y%m%d%H%M%S") + secrets.token_hex(6)


def new_correlation_id() -> str:
    return secrets.token_hex(16)


def sanitize_correlation_id(value: str | None) -> str:
    if not value:
        return ""
    cleaned = "".join(ch for ch in value if ch.isalnum() or ch in "._-")
    return cleaned[:32]
