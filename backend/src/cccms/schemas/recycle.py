from __future__ import annotations

from pydantic import Field

from cccms.schemas.base import StrictModel

RECYCLE_TYPES = (
    "user",
    "role",
    "dept",
    "post",
    "dict",
    "dict_type",
    "dict_data",
    "category",
    "menu",
    "file",
    "notice",
    "crontab",
    "data_rule",
)
RECYCLE_PATTERN = r"^(user|role|dept|post|dict|dict_type|dict_data|category|menu|file|notice|crontab|data_rule)$"


class RecycleRequest(StrictModel):
    type: str = Field(pattern=RECYCLE_PATTERN)
    ids: list[int] = Field(min_length=1)


class RecycleResult(StrictModel):
    affected: int = 0
    restored: int = 0
    deleted: int = 0
    skipped: list[int] = Field(default_factory=list)
