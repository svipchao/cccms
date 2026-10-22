from __future__ import annotations

import asyncio
import json
import logging
import time
from typing import Any, ClassVar
from urllib.parse import parse_qsl

from fastapi import Request
from fastapi.responses import ORJSONResponse
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from cccms.config import get_settings
from cccms.core.audit import LogEvent, enqueue
from cccms.core.cache import Keys, bump
from cccms.core.codes import BizCode
from cccms.core.context import (
    RequestContext,
    current_context,
    new_trace_id,
    reset_context,
    sanitize_correlation_id,
    set_context,
)
from cccms.core.envelope import error_body
from cccms.core.i18n import has, locale_from_request, t
from cccms.core.metrics import IN_FLIGHT, observe_request, stable_path
from cccms.core.net import client_ip
from cccms.core.rbac import invalidate_auth_cache
from cccms.core.redact import redact, truncate
from cccms.core.security import constant_time_equals, new_opaque_token
from cccms.core.tenant import tenant_from_host
from cccms.core.time import now

logger = logging.getLogger("cccms.http")

# 这些写接口会改变“谁有哪些权限”：成功后统一 bump 鉴权缓存版本，
# 单点收口，避免在十几个 service 里逐个漏掉失效调用。
AUTH_INVALIDATING_PREFIXES = (
    "/api/role",
    "/api/menu",
    "/api/user",
    "/api/dept",
    "/api/post",
    "/api/data_rule",
)
SAFE_METHODS = {"GET", "HEAD", "OPTIONS", "TRACE"}


def invalidates_auth_cache(path: str, method: str) -> bool:
    if method.upper() in SAFE_METHODS:
        return False
    return any(path.startswith(prefix) for prefix in AUTH_INVALIDATING_PREFIXES)


def invalidates_dict_cache(path: str, method: str) -> bool:
    return method.upper() not in SAFE_METHODS and path.startswith("/api/dict")


def _cookie_header(
    name: str,
    value: str,
    *,
    max_age: int,
    http_only: bool,
    secure: bool,
    samesite: str,
    path: str = "/",
    domain: str | None = None,
) -> str:
    parts = [f"{name}={value}", f"Path={path}", f"Max-Age={max_age}", f"SameSite={samesite.capitalize()}"]
    if http_only:
        parts.append("HttpOnly")
    if secure:
        parts.append("Secure")
    if domain:
        parts.append(f"Domain={domain}")
    return "; ".join(parts)


def _expire_cookie(name: str, *, secure: bool, samesite: str, path: str = "/", domain: str | None = None) -> str:
    return _cookie_header(
        name,
        "",
        max_age=0,
        http_only=True,
        secure=secure,
        samesite=samesite,
        path=path,
        domain=domain,
    )


class RequestContextMiddleware:
    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        settings = get_settings()
        request = Request(scope)
        host = request.headers.get("host")
        tenant = None
        if scope.get("path") not in {"/ping", "/healthz", "/metrics"}:
            try:
                tenant = await tenant_from_host(host)
            except Exception:
                logger.exception("failed to resolve tenant from host")

        ctx = RequestContext(
            trace_id=new_trace_id(),
            action_id=sanitize_correlation_id(request.headers.get("x-action-id")),
            session_id=sanitize_correlation_id(request.headers.get("x-session-id")),
            method=request.method,
            path=scope.get("path", ""),
            ip=client_ip(request),
            ua=request.headers.get("user-agent", "")[:255],
            tenant=tenant,
            tenant_locked=tenant is not None,
        )

        path = str(scope.get("path", ""))
        csrf_cookie = request.cookies.get(settings.csrf_cookie_name)
        if csrf_cookie:
            ctx.csrf_token = csrf_cookie
        else:
            ctx.csrf_token = new_opaque_token(24)
            ctx.extra["set_csrf"] = ctx.csrf_token

        # 只有 Cookie 会话需要 CSRF；Authorization: Bearer 本身不受浏览器自动携带，
        # 登录 / 找回密码等匿名 POST 也没有会话可被利用。
        cookie_session = bool(request.cookies.get(settings.token_cookie_name))
        bearer = request.headers.get("authorization", "").lower().startswith("bearer ")
        anonymous_post = path.endswith(
            ("/auth/login", "/auth/password/sendCode", "/auth/password/reset", "/auth/captcha")
        )
        if (
            cookie_session
            and not bearer
            and not anonymous_post
            and request.method.upper() not in {"GET", "HEAD", "OPTIONS", "TRACE"}
        ):
            header = request.headers.get(settings.csrf_header_name, "")
            if not csrf_cookie or not header or not constant_time_equals(csrf_cookie, header):
                locale = locale_from_request(request)
                message = (
                    t("error.csrf_invalid", locale=locale)
                    if has("error.csrf_invalid", locale)
                    else "CSRF 校验失败，请刷新页面后重试"
                )
                response = ORJSONResponse(
                    status_code=403,
                    content=error_body(message, int(BizCode.CSRF_INVALID)),
                )
                await response(scope, receive, send)
                return

        token = set_context(ctx)
        status_holder = {"status": 0}
        started_at = time.perf_counter()
        IN_FLIGHT.inc()
        try:
            async def send_wrapper(message: Message) -> None:
                if message["type"] == "http.response.start":
                    status_holder["status"] = int(message.get("status", 200))
                    if int(message.get("status", 200)) < 400:
                        if invalidates_auth_cache(ctx.path, ctx.method):
                            await invalidate_auth_cache()
                        if invalidates_dict_cache(ctx.path, ctx.method):
                            await bump(Keys.DICT_VERSION)
                    headers = list(message.get("headers", []))
                    headers.append((b"x-trace-id", ctx.trace_id.encode()))
                    headers.append((b"x-content-type-options", b"nosniff"))
                    headers.append((b"x-frame-options", b"SAMEORIGIN"))
                    headers.append((b"referrer-policy", b"strict-origin-when-cross-origin"))
                    headers.append((b"permissions-policy", b"geolocation=(), microphone=(), camera=()"))
                    if settings.is_prod:
                        headers.append((b"strict-transport-security", b"max-age=31536000; includeSubDomains"))

                    for name, value in ctx.response_headers.items():
                        headers.append((name.lower().encode(), value.encode()))

                    issued_token = ctx.extra.get("new_token") or ctx.extra.get("set_token")
                    if issued_token:
                        headers.append(
                            (
                                b"set-cookie",
                                _cookie_header(
                                    settings.token_cookie_name,
                                    str(issued_token),
                                    max_age=settings.token_ttl,
                                    http_only=True,
                                    secure=settings.cookie_secure,
                                    samesite=settings.cookie_samesite,
                                    domain=settings.cookie_domain,
                                ).encode(),
                            )
                        )
                    if ctx.extra.get("clear_token"):
                        headers.append(
                            (
                                b"set-cookie",
                                _expire_cookie(
                                    settings.token_cookie_name,
                                    secure=settings.cookie_secure,
                                    samesite=settings.cookie_samesite,
                                    domain=settings.cookie_domain,
                                ).encode(),
                            )
                        )
                    if ctx.extra.get("set_csrf"):
                        headers.append(
                            (
                                b"set-cookie",
                                _cookie_header(
                                    settings.csrf_cookie_name,
                                    str(ctx.extra["set_csrf"]),
                                    max_age=7 * 86400,
                                    http_only=False,
                                    secure=settings.cookie_secure,
                                    samesite=settings.cookie_samesite,
                                    domain=settings.cookie_domain,
                                ).encode(),
                            )
                        )
                    message["headers"] = headers
                await send(message)

            await self.app(scope, receive, send_wrapper)
        finally:
            # 未匹配路由统一记为 unmatched；命中路由用「真实路径 + ID 收敛」，
            # 因为 scope["route"].path 是挂载前缀内的相对模板（如 /user）。
            matched = scope.get("route") is not None
            observe_request(
                method=str(scope.get("method", "GET")),
                route=stable_path(str(scope.get("path", ""))) if matched else None,
                status=status_holder["status"],
                duration=time.perf_counter() - started_at,
            )
            IN_FLIGHT.dec()
            reset_context(token)


class RequestGuardMiddleware:
    """请求级超时 + 请求体大小上限。

    uvicorn 没有内置请求超时，除上传外也没有 body 上限：一个慢客户端或一个
    几十 MB 的 JSON 就能长期占住 worker。超时只对“尚未开始响应”的请求返回 504。
    """

    def __init__(self, app: ASGIApp) -> None:
        self.app = app
        settings = get_settings()
        self.timeout = settings.request_timeout_seconds
        self.max_body = settings.request_max_mb * 1024 * 1024

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        request = Request(scope)
        declared = request.headers.get("content-length")
        if declared:
            try:
                size = int(declared)
            except ValueError:
                size = 0
            if size > self.max_body:
                await _plain_reject(
                    scope, receive, send, 413, BizCode.PAYLOAD_TOO_LARGE, "error.payload_too_large"
                )
                return

        started = False

        async def send_wrapper(message: Message) -> None:
            nonlocal started
            if message["type"] == "http.response.start":
                started = True
            await send(message)

        try:
            async with asyncio.timeout(self.timeout):
                await self.app(scope, receive, send_wrapper)
        except TimeoutError:
            if started:
                raise
            logger.warning("request timeout path=%s", scope.get("path"))
            await _plain_reject(
                scope, receive, send, 504, BizCode.TIMEOUT, "error.timeout"
            )


async def _plain_reject(
    scope: Scope,
    receive: Receive,
    send: Send,
    status: int,
    code: BizCode,
    message_key: str,
) -> None:
    request = Request(scope)
    locale = locale_from_request(request)
    # 文案缺失时回退到中文原句，绝不把 "error.timeout" 这种键名丢给用户。
    fallback = "请求处理超时" if code is BizCode.TIMEOUT else "请求体过大"
    message = t(message_key, locale=locale) if has(message_key, locale) else fallback
    response = ORJSONResponse(status_code=status, content=error_body(message, int(code)))
    await response(scope, receive, send)


class OperationLogMiddleware:
    # 认证与配置请求仍要留审计，但绝不保存响应体（登录/切租户响应含 JWT），
    # 请求体由 redact() 按字段名脱敏。
    SKIP_PATHS: ClassVar[frozenset[str]] = frozenset({"/ping", "/healthz", "/metrics"})
    NO_RESULT_PREFIXES: ClassVar[tuple[str, ...]] = ("/api/auth/", "/api/config")

    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        path = str(scope.get("path", ""))
        if path in self.SKIP_PATHS or path.startswith("/storage/"):
            await self.app(scope, receive, send)
            return

        settings = get_settings()
        content_type = ""
        for name, value in scope.get("headers", []):
            if name == b"content-type":
                content_type = value.decode("latin-1").lower()
                break

        body = b""
        replay_receive = receive
        content_length = 0
        for name, value in scope.get("headers", []):
            if name == b"content-length":
                try:
                    content_length = int(value)
                except ValueError:
                    content_length = 0
                break
        if "application/json" in content_type and content_length <= 65536:
            messages: list[Message] = []
            while True:
                message = await receive()
                messages.append(message)
                body += message.get("body", b"")
                if not message.get("more_body", False):
                    break
            consumed = False

            async def replay_receive() -> Message:
                nonlocal consumed
                if not consumed:
                    consumed = True
                    return {"type": "http.request", "body": body, "more_body": False}
                return {"type": "http.disconnect"}

        status_code = 500
        response_chunks: list[bytes] = []
        response_type = ""

        async def send_wrapper(message: Message) -> None:
            nonlocal status_code, response_type
            if message["type"] == "http.response.start":
                status_code = int(message["status"])
                for name, value in message.get("headers", []):
                    if name == b"content-type":
                        response_type = value.decode("latin-1").lower()
                        break
            elif message["type"] == "http.response.body" and len(b"".join(response_chunks)) < 8192:
                chunk = message.get("body", b"")
                if chunk:
                    response_chunks.append(chunk)
            await send(message)

        await self.app(scope, replay_receive, send_wrapper)

        ctx = current_context()
        if ctx is None:
            return
        route = scope.get("route")
        meta = getattr(route, "cccms_meta", None)
        response_bytes = b"".join(response_chunks)
        if path.startswith(self.NO_RESULT_PREFIXES):
            response_text = None
        else:
            response_text = truncate(response_bytes.decode("utf-8", errors="replace")) if response_bytes else None

        result_code: int | None = None
        result_message = ""
        if response_text and "json" in response_type:
            try:
                payload = json.loads(response_text)
                if isinstance(payload, dict):
                    result_code = int(payload.get("code", 0) or 0)
                    result_message = str(payload.get("message", "") or "")
            except (json.JSONDecodeError, TypeError, ValueError):
                pass

        ok = status_code < 400 and (result_code in (None, 0))
        if not ok and not result_message:
            result_message = f"HTTP {status_code}"

        params: dict[str, Any] = dict(parse_qsl(scope.get("query_string", b"").decode("latin-1")))
        if body:
            try:
                parsed = json.loads(body)
                if isinstance(parsed, dict):
                    params.update(parsed)
                else:
                    params["_body"] = parsed
            except (json.JSONDecodeError, UnicodeDecodeError):
                params["_raw"] = body[:2000].decode("utf-8", errors="replace")

        if ctx.method == "GET" and ok and not settings.log_record_read:
            return

        cost = int((now() - ctx.started_at).total_seconds() * 1000)
        if cost >= settings.slow_log_threshold_ms > 0:
            logger.warning(
                "slow request %s %s cost=%sms user=%s trace=%s",
                ctx.method,
                ctx.path,
                cost,
                ctx.user.id if ctx.user else 0,
                ctx.trace_id,
            )

        event = LogEvent(
            tenant_id=ctx.tenant.id if ctx.tenant else None,
            user_id=ctx.user.id if ctx.user else 0,
            username=ctx.user.username if ctx.user else "",
            status=1 if ok else 0,
            message=result_message[:255],
            method=ctx.method,
            path=ctx.path,
            node=str(getattr(meta, "slug", "") or ""),
            title=str(getattr(meta, "title", "") or ""),
            trace_id=ctx.trace_id,
            action_id=ctx.action_id,
            session_id=ctx.session_id,
            ip=ctx.ip,
            ua=ctx.ua,
            params=json.dumps(redact(params), ensure_ascii=False)[:8000] if params else None,
            result=response_text,
            status_code=status_code,
            cost=cost,
            create_time=now(),
        )
        enqueue(event)
