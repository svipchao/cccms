from __future__ import annotations

from datetime import datetime

from sqlalchemy import BigInteger, DateTime, ForeignKey, Index, SmallInteger, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from cccms.db.base import (
    ActiveFlagMixin,
    BaseModel,
    SoftDeleteMixin,
    TenantMixin,
    TimestampMixin,
)


class User(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_user"
    __table_args__ = (
        UniqueConstraint("tenant_id", "username", "active_flag", name="uq_user_tenant_username"),
        UniqueConstraint("tenant_id", "email", "active_flag", name="uq_user_tenant_email"),
        Index("ix_user_tenant_status", "tenant_id", "status"),
    )

    username: Mapped[str] = mapped_column(String(64), nullable=False)
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    nickname: Mapped[str] = mapped_column(String(64), nullable=False, default="")
    avatar: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    email: Mapped[str | None] = mapped_column(String(128), nullable=True)
    phone: Mapped[str | None] = mapped_column(String(32), nullable=True)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    last_login_ip: Mapped[str | None] = mapped_column(String(64), nullable=True)
    password_changed_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    mfa_enabled: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    mfa_secret_enc: Mapped[str | None] = mapped_column(String(255), nullable=True)
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class Role(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_role"
    __table_args__ = (
        UniqueConstraint("tenant_id", "code", "active_flag", name="uq_role_tenant_code"),
        Index("ix_role_tenant_status", "tenant_id", "status"),
    )

    code: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str] = mapped_column(String(64), nullable=False)
    parent_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_role.id", ondelete="RESTRICT"), nullable=True, index=True
    )
    data_scope: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=4, server_default="4")
    is_system: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class UserRole(BaseModel, TenantMixin):
    __tablename__ = "sys_user_role"
    __table_args__ = (UniqueConstraint("tenant_id", "user_id", "role_id", name="uq_user_role"),)

    user_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="CASCADE"), nullable=False, index=True
    )
    role_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_role.id", ondelete="CASCADE"), nullable=False, index=True
    )


class RoleNode(BaseModel):
    __tablename__ = "sys_role_node"
    __table_args__ = (UniqueConstraint("role_id", "node", name="uq_role_node"),)

    role_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_role.id", ondelete="CASCADE"), nullable=False, index=True
    )
    node: Mapped[str] = mapped_column(String(160), nullable=False)


class Dept(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_dept"
    __table_args__ = (Index("ix_dept_tenant_parent", "tenant_id", "parent_id"),)

    parent_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_dept.id", ondelete="RESTRICT"), nullable=True
    )
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    leader: Mapped[str | None] = mapped_column(String(64), nullable=True)
    phone: Mapped[str | None] = mapped_column(String(32), nullable=True)
    email: Mapped[str | None] = mapped_column(String(128), nullable=True)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    path: Mapped[str] = mapped_column(String(512), nullable=False, default="/", server_default="/")
    level: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")


class UserDept(BaseModel, TenantMixin):
    __tablename__ = "sys_user_dept"
    __table_args__ = (UniqueConstraint("tenant_id", "user_id", "dept_id", name="uq_user_dept"),)

    user_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="CASCADE"), nullable=False, index=True
    )
    dept_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_dept.id", ondelete="CASCADE"), nullable=False, index=True
    )


class DeptRole(BaseModel, TenantMixin):
    __tablename__ = "sys_dept_role"
    __table_args__ = (UniqueConstraint("tenant_id", "dept_id", "role_id", name="uq_dept_role"),)

    dept_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_dept.id", ondelete="CASCADE"), nullable=False, index=True
    )
    role_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_role.id", ondelete="CASCADE"), nullable=False, index=True
    )


class Post(BaseModel, TenantMixin, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_post"
    __table_args__ = (UniqueConstraint("tenant_id", "code", "active_flag", name="uq_post_tenant_code"),)

    code: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str] = mapped_column(String(64), nullable=False)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    remark: Mapped[str | None] = mapped_column(String(500), nullable=True)


class UserPost(BaseModel, TenantMixin):
    __tablename__ = "sys_user_post"
    __table_args__ = (UniqueConstraint("tenant_id", "user_id", "post_id", name="uq_user_post"),)

    user_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_user.id", ondelete="CASCADE"), nullable=False, index=True
    )
    post_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("sys_post.id", ondelete="CASCADE"), nullable=False, index=True
    )


class Menu(BaseModel, TimestampMixin, SoftDeleteMixin, ActiveFlagMixin):
    __tablename__ = "sys_menu"
    __table_args__ = (
        UniqueConstraint("node", "active_flag", name="uq_menu_node"),
        Index("ix_menu_parent_sort", "parent_id", "sort"),
    )

    parent_id: Mapped[int | None] = mapped_column(
        BigInteger, ForeignKey("sys_menu.id", ondelete="RESTRICT"), nullable=True
    )
    type: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=3, server_default="3")
    title: Mapped[str] = mapped_column(String(128), nullable=False)
    path: Mapped[str | None] = mapped_column(String(255), nullable=True)
    component: Mapped[str | None] = mapped_column(String(255), nullable=True)
    icon: Mapped[str | None] = mapped_column(String(64), nullable=True)
    sort: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
    node: Mapped[str | None] = mapped_column(String(160), nullable=True)
    status: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=1, server_default="1")
    keep_alive: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0, server_default="0")
