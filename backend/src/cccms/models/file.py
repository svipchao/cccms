from __future__ import annotations

from sqlalchemy import BigInteger, ForeignKey, Index, String
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import BaseModel, SoftDeleteMixin, TenantMixin, TimestampMixin


class FileRecord(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "sys_file"
    __table_args__ = (
        Index("ix_file_tenant_hash", "tenant_id", "sha1", "size"),
        Index("ix_file_tenant_category", "tenant_id", "category_id"),
        Index("ix_file_tenant_creator", "tenant_id", "create_by"),
        # /storage 每次请求都按 (tenant_id, path) 反查记录。
        Index("ix_file_tenant_path", "tenant_id", "path"),
    )

    category_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_category.id", ondelete="SET NULL"), nullable=True
    )
    storage: Mapped[str] = mapped_column(String(16), nullable=False, default="local", server_default="local")
    path: Mapped[str] = mapped_column(String(512), nullable=False)
    url: Mapped[str] = mapped_column(String(1024), nullable=False)
    original_name: Mapped[str] = mapped_column(String(255), nullable=False)
    ext: Mapped[str] = mapped_column(String(32), nullable=False, default="", server_default="")
    mime: Mapped[str] = mapped_column(String(128), nullable=False, default="", server_default="")
    size: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0, server_default="0")
    sha1: Mapped[str] = mapped_column(String(40), nullable=False, default="", server_default="")
    sha256: Mapped[str] = mapped_column(String(64), nullable=False, default="", server_default="")
    create_by: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="RESTRICT"), nullable=False, index=True
    )
