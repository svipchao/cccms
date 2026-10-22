from __future__ import annotations

import builtins
from typing import Annotated, Any

from fastapi import Query
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import Select, func, literal_column, select

MAX_PAGE_LIMIT = 200
# 直接接收 page/limit 查询参数的路由用这两个别名，避免 limit=100000 打爆内存与数据库。
PageNum = Annotated[int, Query(ge=1)]
PageLimit = Annotated[int, Query(ge=1, le=MAX_PAGE_LIMIT)]


class PageResult[T](BaseModel):
    """Frontend contract is ``{total, list}``; keep that shape exactly."""

    model_config = ConfigDict(populate_by_name=True)

    total: int = 0
    list: builtins.list[T] = Field(default_factory=list)


def page_result[T](total: int, rows: list[T]) -> dict[str, object]:
    return {"total": total, "list": rows}


def count_query(statement: Select[Any], column: Any | None = None) -> Select[Any]:
    """Count the rows a SELECT would return, without materialising its columns.

    ``select(func.count()).select_from(statement.subquery())`` makes MySQL build a
    derived table containing **every** selected column (TEXT/JSON included) and then
    count it. Narrowing the derived table to a single indexed column keeps the count
    on an index-only scan, which is what makes deep log tables usable.

    ``column`` defaults to the first mapped entity's primary key. Statements with a
    multi-column ``DISTINCT`` must pass an explicit column or count rows themselves.
    """
    narrowed = statement.order_by(None).with_only_columns(
        column if column is not None else _first_entity_column(statement),
        maintain_column_froms=True,
    )
    return select(func.count()).select_from(narrowed.subquery())


def _first_entity_column(statement: Select[Any]) -> Any:
    for description in statement.column_descriptions:
        entity = description.get("entity")
        if entity is not None and hasattr(entity, "id"):
            return entity.id
    return literal_column("1")
