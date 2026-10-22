from __future__ import annotations

from pydantic import Field

from cccms.schemas.base import LocalDateTime, OptionalLocalDateTime, StrictModel


class TenantOut(StrictModel):
    id: int
    code: str
    name: str
    domain: str | None = None
    is_platform: int = 0
    status: int = 1
    expire_at: OptionalLocalDateTime = None
    contact: str | None = None
    max_users: int = 0
    remark: str | None = None
    user_count: int = 0
    create_time: LocalDateTime
    update_time: LocalDateTime


class TenantSave(StrictModel):
    code: str = Field(min_length=2, max_length=64, pattern=r"^[a-zA-Z0-9_-]+$")
    name: str = Field(min_length=1, max_length=128)
    domain: str | None = Field(default=None, max_length=255)
    is_platform: int = 0
    status: int = 1
    expire_at: str | None = None
    contact: str | None = Field(default=None, max_length=128)
    max_users: int = Field(default=0, ge=0, le=100000)
    remark: str | None = Field(default=None, max_length=500)


class TenantQuery(StrictModel):
    keyword: str | None = None
    name: str | None = None
    code: str | None = None
    contact: str | None = None
    phone: str | None = None
    status: int | None = None
    start: str | None = None
    end: str | None = None
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)
