from __future__ import annotations

from typing import Any

from sqlalchemy import (
    JSON,
    BigInteger,
    Computed,
    ForeignKey,
    Index,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import ActiveFlagMixin, BaseModel, SoftDeleteMixin, TenantMixin, TimestampMixin


class Category(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "sys_category"
    __table_args__ = (Index("ix_category_tenant_module", "tenant_id", "module"),)

    module: Mapped[str] = mapped_column(String(32), nullable=False)
    parent_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_category.id", ondelete="RESTRICT"), nullable=True
    )
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")


class DictType(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_dict_type"
    __table_args__ = (UniqueConstraint("tenant_id", "type", "active_flag", name="uq_dict_type"),)

    category_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_category.id", ondelete="SET NULL"), nullable=True
    )
    type: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class DictData(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_dict_data"
    __table_args__ = (
        UniqueConstraint("tenant_id", "type_id", "value", "active_flag", name="uq_dict_data"),
        Index("ix_dict_data_tenant_type", "tenant_id", "type_id"),
    )

    type_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_dict_type.id", ondelete="CASCADE"), nullable=False
    )
    value: Mapped[str] = mapped_column(String(128), nullable=False)
    label: Mapped[str] = mapped_column(String(128), nullable=False)
    tag: Mapped[str | None] = mapped_column(String(32), nullable=True)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class Config(BaseModel, TimestampMixin):
    __tablename__ = "sys_config"
    __table_args__ = (
        UniqueConstraint("scope_key", "name", name="uq_config_scope_name"),
        Index("ix_config_group", "group_key", "sort"),
    )

    # 注意：scope_key 是依赖 tenant_id 的 STORED 生成列，MySQL 禁止对该列使用
    # ON DELETE CASCADE / SET NULL（级联写入会让生成列不可维护，直接报 1215）。
    # 这里与 TenantMixin 的其它租户外键保持一致，用 RESTRICT，删除租户需先清理配置。
    tenant_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_tenant.id", ondelete="RESTRICT"), nullable=True
    )
    scope_key: Mapped[int] = mapped_column(Computed("coalesce(tenant_id, 0)", persisted=True), nullable=False)
    group_key: Mapped[str] = mapped_column(String(64), nullable=False, default="system", server_default="system")
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    title: Mapped[str] = mapped_column(String(128), nullable=False)
    type: Mapped[str] = mapped_column(String(32), nullable=False, default="input", server_default="input")
    value: Mapped[str | None] = mapped_column(Text, nullable=True)
    options: Mapped[list[dict[str, Any]] | None] = mapped_column(JSON, nullable=True)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)
