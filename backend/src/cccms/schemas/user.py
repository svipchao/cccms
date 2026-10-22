from __future__ import annotations

from pydantic import EmailStr, Field

from cccms.schemas.auth import RoleBrief
from cccms.schemas.base import LocalDateTime, OptionalLocalDateTime, StrictModel
from cccms.schemas.sorting import SortQuery


class UserQuery(SortQuery):
    keyword: str | None = None
    username: str | None = None
    nickname: str | None = None
    status: int | None = None
    dept_id: int | None = None
    post_id: int | None = None
    start: str | None = None
    end: str | None = None
    trashed: int = 0
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)


class UserOut(StrictModel):
    id: int
    tenant_id: int
    username: str
    nickname: str
    avatar: str | None = None
    email: str | None = None
    phone: str | None = None
    status: int
    super_admin: bool = False
    roles: list[RoleBrief] = Field(default_factory=list)
    role_ids: list[int] = Field(default_factory=list)
    dept_ids: list[int] = Field(default_factory=list)
    post_ids: list[int] = Field(default_factory=list)
    last_login_at: OptionalLocalDateTime = None
    login_time: OptionalLocalDateTime = None
    create_time: LocalDateTime
    update_time: LocalDateTime


class UserCreate(StrictModel):
    username: str = Field(min_length=2, max_length=64)
    password: str = Field(min_length=8, max_length=128)
    nickname: str = Field(default="", max_length=64)
    avatar: str | None = Field(default=None, max_length=1024)
    email: EmailStr | None = None
    phone: str | None = Field(default=None, max_length=32)
    status: int = 1
    role_ids: list[int] = Field(default_factory=list)
    dept_ids: list[int] = Field(default_factory=list)
    post_ids: list[int] = Field(default_factory=list)
    remark: str | None = Field(default=None, max_length=500)


class UserUpdate(StrictModel):
    nickname: str | None = Field(default=None, max_length=64)
    avatar: str | None = Field(default=None, max_length=1024)
    email: EmailStr | None = None
    phone: str | None = Field(default=None, max_length=32)
    status: int | None = Field(default=None, ge=0, le=1)
    role_ids: list[int] | None = None
    dept_ids: list[int] | None = None
    post_ids: list[int] | None = None
    remark: str | None = Field(default=None, max_length=500)


class PasswordReset(StrictModel):
    password: str = Field(min_length=8, max_length=128)


class BatchStatusRequest(StrictModel):
    ids: list[int]
    status: int = Field(ge=0, le=1)


class BatchAssignRequest(StrictModel):
    ids: list[int]
    # None = 保持原样；空数组 = 显式清空。前端只提交要修改的字段。
    role_ids: list[int] | None = None
    dept_ids: list[int] | None = None
    post_ids: list[int] | None = None


class BatchResult(StrictModel):
    affected: int
    skipped: list[str] = Field(default_factory=list)
