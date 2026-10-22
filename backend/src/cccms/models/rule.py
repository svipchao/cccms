from __future__ import annotations

from sqlalchemy import JSON, BigInteger, Index, SmallInteger, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel, SoftDeleteMixin, TenantMixin, TimestampMixin


class DataRule(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "sys_data_rule"
    __table_args__ = (Index("ix_data_rule_tenant_table", "tenant_id", "table_name", "status"),)

    name: Mapped[str] = mapped_column(String(128), nullable=False)
    user_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True, index=True)
    post_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True, index=True)
    role_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True, index=True)
    dept_ids: Mapped[list[int] | None] = mapped_column(JSON, nullable=True)
    bind_mode: Mapped[str] = mapped_column(String(8), nullable=False, default="or", server_default="or")
    table_name: Mapped[str | None] = mapped_column(String(64), nullable=True)
    action: Mapped[str] = mapped_column(String(16), nullable=False, default="row", server_default="row")
    field: Mapped[str | None] = mapped_column(String(64), nullable=True)
    operator: Mapped[str | None] = mapped_column(String(16), nullable=True)
    value: Mapped[str | None] = mapped_column(Text, nullable=True)
    value_type: Mapped[str] = mapped_column(String(16), nullable=False, default="static", server_default="static")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")


class DataScopeTable(BaseModel, TenantMixin, TimestampMixin):
    __tablename__ = "sys_data_scope_table"
    __table_args__ = (UniqueConstraint("tenant_id", "table_name", name="uq_scope_table"),)

    table_name: Mapped[str] = mapped_column(String(64), nullable=False)
    title: Mapped[str] = mapped_column(String(128), nullable=False)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


__all__ = ["DataRule", "DataScopeTable"]
