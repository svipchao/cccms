from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from pydantic import BaseModel, ConfigDict, PlainSerializer

from cccms.core.time import fmt


def _serialize_datetime(value: datetime | None) -> str | None:
    return fmt(value)


LocalDateTime = Annotated[
    datetime,
    PlainSerializer(_serialize_datetime, return_type=str | None, when_used="json"),
]
OptionalLocalDateTime = Annotated[
    datetime | None,
    PlainSerializer(_serialize_datetime, return_type=str | None, when_used="json"),
]


class ORMModel(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class StrictModel(BaseModel):
    # 只认已声明字段：未知字段直接丢弃，而不是报 422。
    # 前端表单会附带 id / 展示列等只读字段，服务层仍只按 model_dump() 的声明字段赋值。
    model_config = ConfigDict(extra="ignore")


def blank_to_none(value: Any) -> Any:
    """前端树选择器用 0 / "" 表示「无上级」，数据库里对应 NULL。"""
    return None if value in (0, "0", "") else value


class IdsRequest(StrictModel):
    ids: list[int]


class IdRequest(StrictModel):
    id: int
