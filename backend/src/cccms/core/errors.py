from __future__ import annotations

from typing import Any

from cccms.core.codes import BizCode, default_code


class ApiError(Exception):
    """业务错误：同时携带 HTTP 状态码与业务码。

    * ``status_code`` 决定响应的 HTTP 状态，网关/监控/浏览器按它判断；
    * ``code`` 决定响应体里的 ``code`` 字段，前端按它区分具体业务原因；
    * ``message_key`` 指向 ``lang/<locale>/*.json`` 的文案键，便于多语言与统一文案。
    """

    def __init__(
        self,
        message: str,
        status_code: int = 400,
        *,
        code: BizCode | None = None,
        message_key: str | None = None,
        data: Any = None,
        headers: dict[str, str] | None = None,
    ) -> None:
        super().__init__(message)
        self.message = message
        self.status_code = status_code
        self.code = int(code if code is not None else default_code(status_code))
        self.message_key = message_key
        self.data = data
        self.headers = headers or {}

    def __repr__(self) -> str:  # pragma: no cover - 仅调试用
        return f"{type(self).__name__}(status={self.status_code}, code={self.code}, message={self.message!r})"


class Unauthorized(ApiError):
    def __init__(
        self,
        message: str = "未登录",
        *,
        code: BizCode = BizCode.UNAUTHORIZED,
        message_key: str = "error.unauthorized",
    ) -> None:
        super().__init__(message, 401, code=code, message_key=message_key)


class Forbidden(ApiError):
    def __init__(
        self,
        message: str = "权限不足",
        *,
        code: BizCode = BizCode.FORBIDDEN,
        message_key: str = "error.forbidden",
    ) -> None:
        super().__init__(message, 403, code=code, message_key=message_key)


class NotFound(ApiError):
    def __init__(
        self,
        message: str = "数据不存在",
        *,
        code: BizCode = BizCode.NOT_FOUND,
        message_key: str = "error.not_found",
    ) -> None:
        super().__init__(message, 404, code=code, message_key=message_key)


class MethodNotAllowed(ApiError):
    def __init__(
        self,
        message: str = "请求方法不允许",
        *,
        code: BizCode = BizCode.METHOD_NOT_ALLOWED,
        message_key: str = "error.method_not_allowed",
    ) -> None:
        super().__init__(message, 405, code=code, message_key=message_key)


class Conflict(ApiError):
    def __init__(
        self,
        message: str = "数据冲突",
        *,
        code: BizCode = BizCode.CONFLICT,
        message_key: str = "error.conflict",
    ) -> None:
        super().__init__(message, 409, code=code, message_key=message_key)


class ValidationFailed(ApiError):
    def __init__(
        self,
        message: str = "参数校验失败",
        data: Any = None,
        *,
        code: BizCode = BizCode.INVALID_PARAM,
        message_key: str = "error.validation",
    ) -> None:
        super().__init__(message, 422, code=code, message_key=message_key, data=data)


class TooManyRequests(ApiError):
    def __init__(
        self,
        message: str = "请求过于频繁",
        headers: dict[str, str] | None = None,
        *,
        code: BizCode = BizCode.RATE_LIMITED,
        message_key: str = "error.rate_limited",
    ) -> None:
        super().__init__(message, 429, code=code, message_key=message_key, headers=headers)


class ServiceUnavailable(ApiError):
    def __init__(
        self,
        message: str = "服务暂不可用",
        *,
        code: BizCode = BizCode.UNAVAILABLE,
        message_key: str = "error.unavailable",
    ) -> None:
        super().__init__(message, 503, code=code, message_key=message_key)
