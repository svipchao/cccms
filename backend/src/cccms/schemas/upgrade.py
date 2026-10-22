from __future__ import annotations

from pydantic import Field

from cccms.schemas.base import LocalDateTime, StrictModel


class UpgradeStatusOut(StrictModel):
    version: str
    enabled: bool
    reason: str = ""
    branch: str = ""
    commit: str = ""
    commit_time: LocalDateTime | None = None
    latest_tag: str = ""
    dirty: bool = False
    behind: int = 0
    ahead: int = 0
    can_run: bool = False


class UpgradeCheckOut(StrictModel):
    behind: int = 0
    ahead: int = 0
    latest_tag: str = ""
    upstream: str = ""
    commits: list[str] = Field(default_factory=list)


class UpgradeTagOut(StrictModel):
    name: str
    subject: str = ""
    date: LocalDateTime | None = None


class UpgradeResultOut(StrictModel):
    output: str = ""
    changed: bool = False
    restart_required: bool = False
