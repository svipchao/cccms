"""列表排序的唯一落地点。

前端 ``useTable`` 只会在用户点了表头时下发 ``sort`` / ``order``；后端必须用
**白名单**把它翻译成列对象，绝不能 ``getattr(Model, params.sort)`` —— 那等于
把任意列（含 ``sys_user.password_hash``）变成可排序入口，并让 SQL 注入面变大。

``fallback`` 是"没有排序参数"时的原始顺序，由各模块显式给出，避免统一默认值
把模块自己的次序（例如公告的"置顶优先"）覆盖掉。
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from sqlalchemy import Select


def apply_sort[StatementT: Select[*tuple[Any, ...]]](
    statement: StatementT,
    sortable: dict[str, Any],
    *,
    id_column: Any,
    fallback: Sequence[Any],
    sort: str | None = None,
    order: str = "asc",
) -> StatementT:
    column = sortable.get(sort or "")
    if column is None:
        # 未指定排序，或指定了不在白名单里的列：静默回到该模块的原始顺序。
        return statement.order_by(*fallback)
    ordering = column.desc() if order == "desc" else column.asc()
    # 追加主键做次级排序，避免同值行在分页间抖动（同一行重复出现/漏行）。
    return statement.order_by(ordering, id_column.asc())


__all__ = ["apply_sort"]
