"""create sys_log_archive

操作日志主表只保留热数据；更早的行由 ``cccms.log.archive`` 搬到归档表，
而不是直接删除。归档表结构与主表一致（``LIKE`` 复制列与索引）。

只归档**未进入审计哈希链**的行（``row_hash IS NULL``）：已封存的行参与链式校验，
移动或删除都会让校验结果失真，保留期策略请通过 ``cccms.log.clean`` 显式决定。

Revision ID: 0004_log_archive
Revises: 0003_file_path_index
"""

from __future__ import annotations

from alembic import op

revision = "0004_log_archive"
down_revision = "0003_file_path_index"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute("CREATE TABLE IF NOT EXISTS sys_log_archive LIKE sys_log")


def downgrade() -> None:
    op.execute("DROP TABLE IF EXISTS sys_log_archive")
