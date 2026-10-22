"""create sys_password_history

口令历史（S-02）：改密时把被替换掉的哈希留一份，用于拒绝「改回旧口令」。
三条改密路径（自助改密 / 管理员重置 / 找回密码）都会写这张表，
每个用户只保留最近 ``CCCMS_PASSWORD_HISTORY`` 份（默认 5），更老的由服务层删除。

不存明文，也不与该表做业务查询，因此没有软删与状态列；
用户物理删除时随 ``sys_user`` 级联清理，租户外键与其它业务表一致用 ``RESTRICT``。

Revision ID: 0005_password_history
Revises: 0004_log_archive
"""

from __future__ import annotations

import sqlalchemy as sa
from alembic import op
from sqlalchemy import inspect

revision = "0005_password_history"
down_revision = "0004_log_archive"
branch_labels = None
depends_on = None

TABLE = "sys_password_history"


def _has_table() -> bool:
    return inspect(op.get_bind()).has_table(TABLE)


def upgrade() -> None:
    if _has_table():
        return
    op.create_table(
        TABLE,
        sa.Column("id", sa.BigInteger(), autoincrement=True, nullable=False),
        sa.Column("tenant_id", sa.BigInteger(), nullable=False),
        sa.Column("user_id", sa.BigInteger(), nullable=False),
        sa.Column("password_hash", sa.String(length=255), nullable=False),
        sa.Column("create_time", sa.DateTime(), server_default=sa.func.now(), nullable=False),
        sa.Column("update_time", sa.DateTime(), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("id", name="pk_sys_password_history"),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["sys_tenant.id"],
            name="fk_sys_password_history_tenant_id_sys_tenant",
            ondelete="RESTRICT",
        ),
        sa.ForeignKeyConstraint(
            ["user_id"],
            ["sys_user.id"],
            name="fk_sys_password_history_user_id_sys_user",
            ondelete="CASCADE",
        ),
        mysql_charset="utf8mb4",
    )
    op.create_index("ix_sys_password_history_tenant_id", TABLE, ["tenant_id"])
    op.create_index("ix_sys_password_history_create_time", TABLE, ["create_time"])
    op.create_index("ix_password_history_user", TABLE, ["user_id", "id"])


def downgrade() -> None:
    if _has_table():
        op.drop_table(TABLE)
