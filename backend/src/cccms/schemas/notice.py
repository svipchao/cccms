from __future__ import annotations

import builtins

from pydantic import Field

from cccms.schemas.base import LocalDateTime, OptionalLocalDateTime, StrictModel


class NoticeTargetIn(StrictModel):
    target_type: str = Field(pattern=r"^(dept|role|user)$")
    target_id: int


class NoticeSave(StrictModel):
    type: int = Field(default=1, ge=1, le=2)
    level: int = Field(default=1, ge=1, le=2)
    title: str = Field(min_length=1, max_length=255)
    summary: str | None = Field(default=None, max_length=500)
    content: str = Field(min_length=1)
    status: int = Field(default=0, ge=0, le=1)
    scope: int = Field(default=0, ge=0, le=3)
    pinned: int = Field(default=0, ge=0, le=1)
    publish_at: str | None = None
    expire_at: str | None = None
    targets: list[NoticeTargetIn] = Field(default_factory=list)
    # 前端按 scope 只提交一个 id 数组，服务层会据此生成 targets
    target_ids: list[int] = Field(default_factory=list)


class NoticeListOut(StrictModel):
    id: int
    type: int
    level: int
    title: str
    summary: str | None = None
    content: str = ""
    status: int
    scope: int
    pinned: int
    target_ids: list[int] = Field(default_factory=list)
    publish_at: OptionalLocalDateTime = None
    expire_at: OptionalLocalDateTime = None
    create_by: int | None = None
    create_by_name: str = ""
    read_count: int = 0
    target_count: int = 0
    create_time: LocalDateTime
    update_time: LocalDateTime


class NoticeOut(NoticeListOut):
    content: str
    targets: list[NoticeTargetIn] = Field(default_factory=list)


class NoticeMyOut(StrictModel):
    id: int
    type: int
    level: int
    title: str
    summary: str | None = None
    content: str
    pinned: int
    publish_at: OptionalLocalDateTime = None
    expire_at: OptionalLocalDateTime = None
    is_read: bool = False
    create_time: LocalDateTime


class NoticeOptionsOut(StrictModel):
    depts: list[dict[str, object]] = Field(default_factory=list)
    roles: list[dict[str, object]] = Field(default_factory=list)
    users: list[dict[str, object]] = Field(default_factory=list)


class NoticeReportRow(StrictModel):
    user_id: int
    username: str
    nickname: str
    read_time: OptionalLocalDateTime = None


class NoticeReportOut(StrictModel):
    notice: dict[str, object] = Field(default_factory=dict)
    total: int
    read: int
    unread: int
    list: builtins.list[NoticeReportRow] = Field(default_factory=list)
