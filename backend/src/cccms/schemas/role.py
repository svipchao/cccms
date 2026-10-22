from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import LocalDateTime, StrictModel, blank_to_none


class RoleOut(StrictModel):
    id: int
    tenant_id: int
    code: str
    name: str
    parent_id: int | None = None
    data_scope: int = 4
    is_system: int = 0
    status: int = 1
    sort: int = 0
    remark: str | None = None
    create_time: LocalDateTime
    update_time: LocalDateTime
    # 详情接口回显授权树用；列表接口为 None
    own: list[str] | None = None
    inherited: list[str] | None = None


class RoleSave(StrictModel):
    code: str = Field(min_length=2, max_length=64, pattern=r"^[a-z][a-z0-9_]*$")
    name: str = Field(min_length=1, max_length=64)
    parent_id: int | None = None
    data_scope: int = Field(default=4, ge=1, le=5)
    status: int = Field(default=1, ge=0, le=1)
    sort: int = 0
    remark: str | None = Field(default=None, max_length=500)
    nodes: list[str] | None = None

    @field_validator("parent_id", mode="before")
    @classmethod
    def _parent(cls, value: object) -> object:
        return blank_to_none(value)


class RoleUpdate(StrictModel):
    name: str | None = Field(default=None, min_length=1, max_length=64)
    parent_id: int | None = None
    data_scope: int | None = Field(default=None, ge=1, le=5)
    status: int | None = Field(default=None, ge=0, le=1)
    sort: int | None = None
    remark: str | None = Field(default=None, max_length=500)
    nodes: list[str] | None = None

    @field_validator("parent_id", mode="before")
    @classmethod
    def _parent(cls, value: object) -> object:
        return blank_to_none(value)


class RoleNodesSave(StrictModel):
    nodes: list[str] = Field(default_factory=list)


class RoleCopyRequest(StrictModel):
    code: str = Field(min_length=2, max_length=64, pattern=r"^[a-z][a-z0-9_]*$")
    name: str | None = Field(default=None, max_length=64)


class RoleNodeView(StrictModel):
    own: list[str] = Field(default_factory=list)
    inherited: list[str] = Field(default_factory=list)
    all: list[str] = Field(default_factory=list)
