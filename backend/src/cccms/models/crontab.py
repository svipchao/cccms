from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import (
    JSON,
    BigInteger,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    SmallInteger,
    String,
    Text,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel, SoftDeleteMixin, TenantMixin, TimestampMixin


class Crontab(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "sys_crontab"
    __table_args__ = (Index("ix_crontab_tenant_status", "tenant_id", "status", "next_run_time"),)

    name: Mapped[str] = mapped_column(String(128), nullable=False)
    cron: Mapped[str] = mapped_column(String(64), nullable=False)
    target: Mapped[str] = mapped_column(String(128), nullable=False)
    params: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    group_name: Mapped[str | None] = mapped_column(String(64), nullable=True)
    overlap: Mapped[str] = mapped_column(String(8), nullable=False, default="skip", server_default="skip")
    timeout: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    retry_times: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    retry_interval: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=60, server_default="60")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    running: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    running_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    last_run_time: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    next_run_time: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    last_status: Mapped[int | None] = mapped_column(SmallInteger, nullable=True)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)
    locked_by: Mapped[str | None] = mapped_column(String(64), nullable=True)
    locked_until: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class CrontabLog(BaseModel):
    __tablename__ = "sys_crontab_log"
    __table_args__ = (
        Index("ix_crontab_log_task_time", "crontab_id", "create_time"),
        # 日志页按 id DESC 翻页。
        Index("ix_crontab_log_task_id", "crontab_id", "id"),
    )

    crontab_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_crontab.id", ondelete="CASCADE"), nullable=False
    )
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    source: Mapped[str] = mapped_column(String(16), nullable=False, default="cron", server_default="cron")
    output: Mapped[str | None] = mapped_column(Text, nullable=True)
    cost: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    run_time: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    trace_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    create_time: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())


class CrontabRetry(BaseModel):
    __tablename__ = "sys_crontab_retry"
    __table_args__ = (Index("ix_crontab_retry_at", "retry_at"),)

    crontab_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_crontab.id", ondelete="CASCADE"), nullable=False
    )
    payload: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    attempt: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    # 索引已由 __table_args__ 的 ix_crontab_retry_at 显式声明，勿再加 index=True。
    retry_at: Mapped[datetime] = mapped_column(DateTime, nullable=False)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)
    create_time: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
