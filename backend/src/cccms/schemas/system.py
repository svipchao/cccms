from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import StrictModel, blank_to_none


class ConfigOut(StrictModel):
    id: int
    group_key: str
    group: str
    group_label: str
    name: str
    title: str
    type: str
    value: str | None = None
    has_value: bool = False
    options: list[dict[str, object]] | None = None
    sort: int = 0
    status: int = 1
    remark: str | None = None


class ConfigSaveRequest(StrictModel):
    values: dict[str, str] = Field(default_factory=dict)


class CategoryNode(StrictModel):
    id: int
    module: str
    parent_id: int | None = None
    name: str
    sort: int = 0
    status: int = 1
    children: list[CategoryNode] = Field(default_factory=list)


class CategorySave(StrictModel):
    # 路由本身决定模块（/dict/category 或 /file/category），前端无需在 body 里重复
    module: str = Field(default="dict", pattern=r"^(dict|file)$")
    parent_id: int | None = None
    name: str = Field(min_length=1, max_length=128)
    sort: int = 0
    status: int = 1

    @field_validator("parent_id", mode="before")
    @classmethod
    def _parent(cls, value: object) -> object:
        return blank_to_none(value)


class DictTypeOut(StrictModel):
    id: int
    category_id: int | None = None
    type: str
    name: str
    status: int = 1
    remark: str | None = None


class DictTypeSave(StrictModel):
    category_id: int | None = None
    type: str = Field(min_length=1, max_length=64)
    name: str = Field(min_length=1, max_length=128)
    status: int = 1
    remark: str | None = Field(default=None, max_length=500)

    @field_validator("category_id", mode="before")
    @classmethod
    def _category(cls, value: object) -> object:
        return blank_to_none(value)


class DictDataOut(StrictModel):
    id: int
    type_id: int
    value: str
    label: str
    tag: str | None = None
    sort: int = 0
    status: int = 1
    remark: str | None = None


class DictDataSave(StrictModel):
    type_id: int
    value: str = Field(min_length=1, max_length=128)
    label: str = Field(min_length=1, max_length=128)
    tag: str | None = Field(default=None, max_length=32)
    sort: int = 0
    status: int = 1
    remark: str | None = Field(default=None, max_length=500)
