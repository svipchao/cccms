from __future__ import annotations

import builtins

from pydantic import ConfigDict, Field

from cccms.schemas.base import LocalDateTime, StrictModel
from cccms.schemas.sorting import SortQuery


class LogQuery(SortQuery):
    keyword: str | None = None
    username: str | None = None
    title: str | None = None
    path: str | None = None
    method: str | None = None
    status: int | None = None
    node: str | None = None
    trace_id: str | None = None
    action_id: str | None = None
    session_id: str | None = None
    ip: str | None = None
    params_like: str | None = None
    result_like: str | None = None
    start: str | None = None
    end: str | None = None
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)


class LogOut(StrictModel):
    id: int
    user_id: int
    username: str
    status: int
    message: str
    method: str
    path: str
    node: str
    title: str
    trace_id: str
    action_id: str
    session_id: str
    ip: str
    ua: str = ""
    params: str | None = None
    result: str | None = None
    status_code: int
    cost: int
    create_time: LocalDateTime


class LogDetailOut(LogOut):
    tenant_id: int | None = None
    prev_hash: str | None = None
    row_hash: str | None = None


class LogActionOut(StrictModel):
    action_id: str
    session_id: str = ""
    total: int = 0
    failed: int = 0
    cost: int = 0
    truncated: bool = False
    list: builtins.list[LogDetailOut] = Field(default_factory=list)


class LogSessionOut(StrictModel):
    session_id: str
    total: int = 0
    next_cursor: int | None = None
    has_more: bool = False
    list: builtins.list[LogOut] = Field(default_factory=list)


class LogAnalysisSummary(StrictModel):
    total: int = 0
    success: int = 0
    failed: int = 0
    failure_rate: float = 0.0
    fail_rate: float = 0.0
    failed_users: int = 0
    failed_ips: int = 0
    users: int = 0
    ips: int = 0


class LogTrendPoint(StrictModel):
    bucket: str
    total: int = 0
    success: int = 0
    failed: int = 0


class LogTopItem(StrictModel):
    name: str
    total: int = 0
    count: int = 0
    username: str = ""
    ip: str = ""


class LogIpChange(StrictModel):
    user_id: int
    username: str
    from_ip: str
    to_ip: str
    at: LocalDateTime
    time: LocalDateTime


class LogAnalysisOut(StrictModel):
    range: dict[str, object] = Field(default_factory=dict)
    summary: LogAnalysisSummary
    trend: list[LogTrendPoint] = Field(default_factory=list)
    top_users: list[LogTopItem] = Field(default_factory=list)
    top_ips: list[LogTopItem] = Field(default_factory=list)
    ip_changes: list[LogIpChange] = Field(default_factory=list)


class LogExportOut(StrictModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)

    task_id: int
    total: int
    async_: bool = Field(default=True, alias="async")
