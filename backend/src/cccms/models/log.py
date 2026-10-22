from __future__ import annotations

from datetime import datetime

from sqlalchemy import BigInteger, DateTime, Index, Integer, SmallInteger, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel


class OperationLog(BaseModel):
    __tablename__ = "sys_log"
    __table_args__ = (
        Index("ix_log_create_id", "create_time", "id"),
        Index("ix_log_trace", "trace_id"),
        Index("ix_log_action", "action_id"),
        Index("ix_log_session", "session_id"),
        Index("ix_log_user_time", "user_id", "create_time"),
        Index("ix_log_tenant_time", "tenant_id", "create_time"),
        # 列表页固定按 tenant + id DESC 翻页：没有这个索引只能 filesort 全表。
        Index("ix_log_tenant_id", "tenant_id", "id"),
        # 登录分析按 path + 时间窗过滤。
        Index("ix_log_path_time", "path", "create_time"),
        # 审计封存与校验反复筛 row_hash IS NULL。
        Index("ix_log_row_hash", "row_hash"),
    )

    tenant_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    user_id: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0, server_default="0")
    username: Mapped[str] = mapped_column(String(64), nullable=False, default="", server_default="")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    message: Mapped[str] = mapped_column(String(255), nullable=False, default="", server_default="")
    method: Mapped[str] = mapped_column(String(16), nullable=False, default="", server_default="")
    path: Mapped[str] = mapped_column(String(255), nullable=False, default="", server_default="")
    node: Mapped[str] = mapped_column(String(160), nullable=False, default="", server_default="")
    title: Mapped[str] = mapped_column(String(128), nullable=False, default="", server_default="")
    trace_id: Mapped[str] = mapped_column(String(32), nullable=False, default="", server_default="")
    action_id: Mapped[str] = mapped_column(String(32), nullable=False, default="", server_default="")
    session_id: Mapped[str] = mapped_column(String(32), nullable=False, default="", server_default="")
    ip: Mapped[str] = mapped_column(String(64), nullable=False, default="", server_default="")
    ua: Mapped[str] = mapped_column(String(255), nullable=False, default="", server_default="")
    params: Mapped[str | None] = mapped_column(Text, nullable=True)
    result: Mapped[str | None] = mapped_column(Text, nullable=True)
    status_code: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=200, server_default="200")
    cost: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    create_time: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
    prev_hash: Mapped[str | None] = mapped_column(String(64), nullable=True)
    row_hash: Mapped[str | None] = mapped_column(String(64), nullable=True)
    hash_version: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")


class AuditSeal(BaseModel):
    __tablename__ = "sys_audit_seal"

    from_log_id: Mapped[int] = mapped_column(BigInteger, nullable=False, index=True)
    to_log_id: Mapped[int] = mapped_column(BigInteger, nullable=False, index=True)
    head_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    prev_hash: Mapped[str] = mapped_column(String(64), nullable=False, default="", server_default="")
    signature: Mapped[str] = mapped_column(String(128), nullable=False, default="", server_default="")
    key_id: Mapped[str] = mapped_column(String(32), nullable=False, default="v1", server_default="v1")
    create_time: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
