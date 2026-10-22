from __future__ import annotations

from typing import Any

from pydantic import Field

from cccms.schemas.base import LocalDateTime, OptionalLocalDateTime, StrictModel


class CrontabOut(StrictModel):
    id: int
    name: str
    cron: str
    target: str
    params: dict[str, Any] | None = None
    group_name: str | None = None
    overlap: str = "skip"
    timeout: int = 0
    retry_times: int = 0
    retry_interval: int = 60
    status: int = 1
    running: int = 0
    running_at: OptionalLocalDateTime = None
    last_run_time: OptionalLocalDateTime = None
    next_run_time: OptionalLocalDateTime = None
    last_status: int | None = None
    last_error: str | None = None
    remark: str | None = None
    create_time: LocalDateTime
    update_time: LocalDateTime


class CrontabSave(StrictModel):
    name: str = Field(min_length=1, max_length=128)
    cron: str = Field(min_length=1, max_length=64)
    target: str = Field(min_length=1, max_length=128)
    params: dict[str, Any] | None = None
    group_name: str | None = Field(default=None, max_length=64)
    overlap: str = Field(default="skip", pattern=r"^(skip|allow)$")
    timeout: int = Field(default=0, ge=0, le=86400)
    retry_times: int = Field(default=0, ge=0, le=10)
    retry_interval: int = Field(default=60, ge=1, le=86400)
    status: int = Field(default=1, ge=0, le=1)
    remark: str | None = Field(default=None, max_length=500)


class CrontabLogOut(StrictModel):
    id: int
    crontab_id: int
    status: int
    source: str
    output: str | None = None
    cost: int = 0
    run_time: OptionalLocalDateTime = None
    trace_id: str | None = None
    create_time: LocalDateTime


class CrontabTargetOut(StrictModel):
    value: str
    label: str


class CrontabQuery(StrictModel):
    keyword: str | None = None
    name: str | None = None
    group_name: str | None = None
    status: int | None = None
    trashed: int = 0
    start: str | None = None
    end: str | None = None
    next_start: str | None = None
    next_end: str | None = None
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)


class CrontabLogQuery(StrictModel):
    crontab_id: int | None = None
    status: int | None = None
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)
