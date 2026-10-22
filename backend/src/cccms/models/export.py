from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import JSON, BigInteger, DateTime, ForeignKey, Index, SmallInteger, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel, TenantMixin, TimestampMixin


class ExportTask(BaseModel, TenantMixin, TimestampMixin):
    __tablename__ = "sys_export_task"
    __table_args__ = (Index("ix_export_task_user_status", "user_id", "status", "create_time"),)

    user_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="CASCADE"), nullable=False
    )
    type: Mapped[str] = mapped_column(String(32), nullable=False)
    params: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    progress: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    file_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    file_size: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0, server_default="0")
    row_count: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0, server_default="0")
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    expires_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
