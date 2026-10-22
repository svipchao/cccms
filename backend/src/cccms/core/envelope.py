from __future__ import annotations

import builtins
import logging
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import ORJSONResponse
from pydantic import BaseModel
from sqlalchemy.exc import SQLAlchemyError
from starlette.exceptions import HTTPException as StarletteHTTPException

from cccms.core.codes import BizCode, default_code
from cccms.core.context import current_context
from cccms.core.errors import ApiError
from cccms.core.i18n import has, locale_from_request, t

logger = logging.getLogger("cccms.error")

class ApiResponse[T](BaseModel):
    code: int = 0
    message: str = "ok"
    data: T | None = None


class PageMeta(BaseModel):
    total: int
    list: builtins.list[Any]


def ok(data: Any = None, message: str = "ok") -> dict[str, Any]:
    """Return a plain dict so endpoints do not lose FastAPI response_model filtering."""
    return {"code": 0, "message": message, "data": data}


def _ids() -> dict[str, str]:
    ctx = current_context()
    if ctx is None:
        return {}
    return {
        "trace_id": ctx.trace_id,
        "action_id": ctx.action_id,
        "session_id": ctx.session_id,
    }


def error_body(message: str, code: int, data: Any = None) -> dict[str, Any]:
    body: dict[str, Any] = {"code": code, "message": message, "data": data}
    body.update(_ids())
    return body


def localize(request: Request | None, message: str, key: str | None) -> str:
    """有文案键且该语言存在翻译时用翻译，否则回退到调用方给的原文。"""
    if not key or request is None:
        return message
    locale = locale_from_request(request)
    if not has(key, locale):
        return message
    return t(key, locale=locale)


def _json_response(body: dict[str, Any], status_code: int, headers: dict[str, str] | None = None) -> ORJSONResponse:
    return ORJSONResponse(content=body, status_code=status_code, headers=headers)


def install_exception_handlers(app: FastAPI) -> None:
    @app.exception_handler(ApiError)
    async def _api_error(request: Request, exc: ApiError) -> ORJSONResponse:
        # HTTP 状态码走 protocol 语义，响应体里的 code 是业务码，两者不再同值。
        return _json_response(
            error_body(localize(request, exc.message, exc.message_key), exc.code, exc.data),
            exc.status_code,
            exc.headers,
        )

    @app.exception_handler(RequestValidationError)
    async def _validation_error(request: Request, exc: RequestValidationError) -> ORJSONResponse:
        errors = []
        for item in exc.errors():
            errors.append(
                {
                    "loc": ".".join(str(part) for part in item.get("loc", ())),
                    "msg": item.get("msg", ""),
                    "type": item.get("type", ""),
                }
            )
        message = localize(request, errors[0]["msg"] if errors else "参数校验失败", "error.validation")
        return _json_response(
            error_body(message, int(BizCode.INVALID_PARAM), {"errors": errors}), 422
        )

    @app.exception_handler(StarletteHTTPException)
    async def _http_error(request: Request, exc: StarletteHTTPException) -> ORJSONResponse:
        message = exc.detail if isinstance(exc.detail, str) else "请求失败"
        code = int(default_code(exc.status_code))
        key = f"error.{_KEY_BY_STATUS.get(exc.status_code, 'server')}"
        return _json_response(error_body(localize(request, message, key), code), exc.status_code)

    @app.exception_handler(SQLAlchemyError)
    async def _db_error(request: Request, exc: SQLAlchemyError) -> ORJSONResponse:
        logger.exception("database error", exc_info=exc)
        return _json_response(
            error_body(localize(request, "服务器内部错误", "error.server"), int(BizCode.SERVER_ERROR)), 500
        )

    @app.exception_handler(Exception)
    async def _unhandled(request: Request, exc: Exception) -> ORJSONResponse:
        logger.exception("unhandled error", exc_info=exc)
        return _json_response(
            error_body(localize(request, "服务器内部错误", "error.server"), int(BizCode.SERVER_ERROR)), 500
        )


_KEY_BY_STATUS: dict[int, str] = {
    401: "unauthorized",
    403: "forbidden",
    404: "not_found",
    405: "method_not_allowed",
    409: "conflict",
    413: "payload_too_large",
    429: "rate_limited",
    503: "unavailable",
    504: "timeout",
}
