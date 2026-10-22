"""业务错误码。

与 HTTP 状态码**解耦**：

* HTTP 状态码表达协议语义（401/403/404/409/429/5xx），供网关、浏览器与监控使用；
* `code` 表达业务语义，前端只判断 `code === 0` 成功，其余按 `code` 做分支或提示。

历史实现把两者混用（`code` 就是 HTTP 码），导致“登录失败”和“令牌过期”都是 401
无法区分，也无法承载 i18n 文案键。新增业务错误时在这里登记，禁止直接写字面量。
"""

from __future__ import annotations

from enum import IntEnum


class BizCode(IntEnum):
    OK = 0

    # 认证 101xx ---------------------------------------------------------
    UNAUTHORIZED = 10101
    LOGIN_FAILED = 10102
    ACCOUNT_DISABLED = 10103
    ACCOUNT_LOCKED = 10104
    CAPTCHA_INVALID = 10105
    PASSWORD_POLICY = 10106
    TOKEN_EXPIRED = 10107
    CSRF_INVALID = 10108

    # 授权 102xx ---------------------------------------------------------
    FORBIDDEN = 10201
    DATA_SCOPE_DENIED = 10202
    DELEGATION_DENIED = 10203

    # 资源与请求 104xx ---------------------------------------------------
    NOT_FOUND = 10401
    METHOD_NOT_ALLOWED = 10402
    CONFLICT = 10409
    INVALID_PARAM = 10422
    PAYLOAD_TOO_LARGE = 10413

    # 限流与服务 105xx ---------------------------------------------------
    SERVER_ERROR = 10500
    RATE_LIMITED = 10501
    UNAVAILABLE = 10503
    TIMEOUT = 10504


#: HTTP 状态码 → 默认业务码，用于没有显式指定业务码的场景（网关/框架异常等）。
HTTP_DEFAULT_CODE: dict[int, BizCode] = {
    400: BizCode.INVALID_PARAM,
    401: BizCode.UNAUTHORIZED,
    403: BizCode.FORBIDDEN,
    404: BizCode.NOT_FOUND,
    405: BizCode.METHOD_NOT_ALLOWED,
    409: BizCode.CONFLICT,
    413: BizCode.PAYLOAD_TOO_LARGE,
    422: BizCode.INVALID_PARAM,
    429: BizCode.RATE_LIMITED,
    500: BizCode.SERVER_ERROR,
    503: BizCode.UNAVAILABLE,
    504: BizCode.TIMEOUT,
}


def default_code(status_code: int) -> BizCode:
    return HTTP_DEFAULT_CODE.get(status_code, BizCode.SERVER_ERROR if status_code >= 500 else BizCode.INVALID_PARAM)
