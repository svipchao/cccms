"""HTTP 载荷里的弱类型字段解析。

前端大量接口沿用 flat JSON（``id`` 直接放在 body 顶层），路由只能声明
``dict[str, object]``。把这些值集中收敛成明确类型，避免 ``int(object)`` 散落在
各个路由里：那样既过不了类型检查，运行期抛 TypeError/ValueError 还会变成 500。
"""

from __future__ import annotations

from cccms.core.errors import ValidationFailed


def as_int(value: object, *, field: str = "id") -> int:
    """把请求体中的数字或数字字符串转成 int，非法值统一抛 422。"""
    if isinstance(value, bool):
        # bool 是 int 的子类，但把 true 当成 1 会掩盖前端传错字段。
        raise ValidationFailed(f"{field} 不合法")
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        if value.is_integer():
            return int(value)
        raise ValidationFailed(f"{field} 不合法")
    if isinstance(value, str):
        try:
            return int(value.strip())
        except ValueError:
            raise ValidationFailed(f"{field} 不合法") from None
    raise ValidationFailed(f"{field} 不合法")
