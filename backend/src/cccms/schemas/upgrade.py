from __future__ import annotations

from pydantic import Field

from cccms.schemas.base import LocalDateTime, StrictModel


class UpgradeRemoteOut(StrictModel):
    """页面下拉用的 git 远端；url 已去掉 userinfo 与查询串。"""

    name: str
    url: str = ""
    is_default: bool = False


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
    # 当前分支用于比对的上游（已设置跟踪则形如 cccms/main，未设置时按远端兜底）
    upstream: str = ""
    can_run: bool = False
    # 仓库已配置的远端，供页面选择同步来源；无远端或拉取失败时为空
    remotes: list[UpgradeRemoteOut] = Field(default_factory=list)


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
