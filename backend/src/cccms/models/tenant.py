from __future__ import annotations

from datetime import datetime

from sqlalchemy import DateTime, SmallInteger, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import ActiveFlagMixin, BaseModel, SoftDeleteMixin, TimestampMixin


class Tenant(BaseModel, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_tenant"
    __table_args__ = (
        UniqueConstraint("code", "active_flag", name="uq_tenant_code"),
        UniqueConstraint("domain", "active_flag", name="uq_tenant_domain"),
    )

    code: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    domain: Mapped[str | None] = mapped_column(String(255), nullable=True)
    is_platform: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1", index=True)
    expire_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    contact: Mapped[str | None] = mapped_column(String(128), nullable=True)
    max_users: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)
