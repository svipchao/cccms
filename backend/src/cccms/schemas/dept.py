from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import LocalDateTime, StrictModel, blank_to_none


class DeptNode(StrictModel):
    id: int
    parent_id: int | None = None
    name: str
    leader: str | None = None
    phone: str | None = None
    email: str | None = None
    sort: int = 0
    status: int = 1
    path: str = "/"
    level: int = 1
    create_time: LocalDateTime | None = None
    children: list[DeptNode] = Field(default_factory=list)


class DeptSave(StrictModel):
    parent_id: int | None = None
    name: str = Field(min_length=1, max_length=128)
    leader: str | None = Field(default=None, max_length=64)
    phone: str | None = Field(default=None, max_length=32)
    email: str | None = Field(default=None, max_length=128)
    sort: int = 0
    status: int = 1

    @field_validator("parent_id", mode="before")
    @classmethod
    def _parent(cls, value: object) -> object:
        return blank_to_none(value)


class PostOut(StrictModel):
    id: int
    code: str
    name: str
    sort: int = 0
    status: int = 1
    remark: str | None = None
    create_time: LocalDateTime
    update_time: LocalDateTime


class PostSave(StrictModel):
    code: str = Field(min_length=1, max_length=64, pattern=r"^[a-z][a-z0-9_]*$")
    name: str = Field(min_length=1, max_length=64)
    sort: int = 0
    status: int = 1
    remark: str | None = Field(default=None, max_length=500)
