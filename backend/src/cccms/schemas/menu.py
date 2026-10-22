from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import StrictModel, blank_to_none


class MenuNode(StrictModel):
    id: int
    parent_id: int | None = None
    type: int
    title: str
    path: str | None = None
    component: str | None = None
    icon: str | None = None
    sort: int = 0
    node: str | None = None
    status: int = 1
    keep_alive: int = 0
    children: list[MenuNode] = Field(default_factory=list)


class MenuSave(StrictModel):
    parent_id: int | None = None
    type: int = Field(ge=1, le=3)
    title: str = Field(min_length=1, max_length=128)
    path: str | None = Field(default=None, max_length=255)
    component: str | None = Field(default=None, max_length=255)
    icon: str | None = Field(default=None, max_length=64)
    sort: int = 0
    node: str | None = Field(default=None, max_length=160)
    status: int = 1
    keep_alive: int = 0

    @field_validator("parent_id", mode="before")
    @classmethod
    def _parent(cls, value: object) -> object:
        return blank_to_none(value)


class MenuUpdate(MenuSave):
    pass
