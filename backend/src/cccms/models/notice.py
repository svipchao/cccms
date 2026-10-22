from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    BigInteger,
    DateTime,
    ForeignKey,
    Index,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel, SoftDeleteMixin, TenantMixin, TimestampMixin


class Notice(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "sys_notice"
    __table_args__ = (
        Index("ix_notice_tenant_status", "tenant_id", "status", "publish_at"),
        # 列表固定 ORDER BY pinned DESC, publish_at DESC, id DESC。
        Index("ix_notice_tenant_pinned", "tenant_id", "pinned", "publish_at", "id"),
    )

    type: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    level: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    title: Mapped[str] = mapped_column(String(255), nullable=False)
    summary: Mapped[str | None] = mapped_column(String(500), nullable=True)
    content: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    scope: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    pinned: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    publish_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    expire_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    create_by: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="SET NULL"), nullable=True
    )
    read_count: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0, server_default="0")


class NoticeTarget(BaseModel):
    __tablename__ = "sys_notice_target"
    __table_args__ = (
        UniqueConstraint("notice_id", "target_type", "target_id", name="uq_notice_target"),
        Index("ix_notice_target_lookup", "target_type", "target_id"),
    )

    notice_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_notice.id", ondelete="CASCADE"), nullable=False
    )
    target_type: Mapped[str] = mapped_column(String(16), nullable=False)
    target_id: Mapped[int] = mapped_column(BigInteger, nullable=False)


class NoticeRead(BaseModel):
    __tablename__ = "sys_notice_read"
    __table_args__ = (UniqueConstraint("notice_id", "user_id", name="uq_notice_user"),)

    notice_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_notice.id", ondelete="CASCADE"), nullable=False
    )
    user_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="CASCADE"), nullable=False, index=True
    )
    read_time: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
