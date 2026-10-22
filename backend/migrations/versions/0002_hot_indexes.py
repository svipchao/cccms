"""hot query indexes

补齐列表页 / 审计链 / 定时任务日志的缺失索引。存量库跑这一版即可，
全新库因为基线已按模型建过索引，这里会自动跳过。

Revision ID: 0002_hot_indexes
Revises: 0001_baseline
"""

from __future__ import annotations

from alembic import op
from sqlalchemy import inspect

revision = "0002_hot_indexes"
down_revision = "0001_baseline"
branch_labels = None
depends_on = None

INDEXES: tuple[tuple[str, str, tuple[str, ...]], ...] = (
    ("sys_log", "ix_log_tenant_id", ("tenant_id", "id")),
    ("sys_log", "ix_log_path_time", ("path", "create_time")),
    ("sys_log", "ix_log_row_hash", ("row_hash",)),
    ("sys_crontab_log", "ix_crontab_log_task_id", ("crontab_id", "id")),
    ("sys_notice", "ix_notice_tenant_pinned", ("tenant_id", "pinned", "publish_at", "id")),
)


def _existing(table: str) -> set[str]:
    inspector = inspect(op.get_bind())
    if not inspector.has_table(table):
        return set()
    return {str(index["name"]) for index in inspector.get_indexes(table)}


def upgrade() -> None:
    cache: dict[str, set[str]] = {}
    for table, name, columns in INDEXES:
        names = cache.setdefault(table, _existing(table))
        if name in names:
            continue
        op.create_index(name, table, list(columns))
        names.add(name)


def downgrade() -> None:
    for table, name, _ in reversed(INDEXES):
        if name in _existing(table):
            op.drop_index(name, table_name=table)
