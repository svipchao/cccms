"""index sys_file by (tenant_id, path)

``/storage/**`` 现在每次请求都按 ``(tenant_id, path)`` 反查 ``sys_file``，
没有索引就是一次全表扫描，图片多的时候会把库拖死。

Revision ID: 0003_file_path_index
Revises: 0002_hot_indexes
"""

from __future__ import annotations

from alembic import op
from sqlalchemy import inspect

revision = "0003_file_path_index"
down_revision = "0002_hot_indexes"
branch_labels = None
depends_on = None

INDEX_NAME = "ix_file_tenant_path"
TABLE = "sys_file"


def _exists() -> bool:
    inspector = inspect(op.get_bind())
    if not inspector.has_table(TABLE):
        return False
    return INDEX_NAME in {str(index["name"]) for index in inspector.get_indexes(TABLE)}


def upgrade() -> None:
    if not _exists():
        op.create_index(INDEX_NAME, TABLE, ["tenant_id", "path"])


def downgrade() -> None:
    if _exists():
        op.drop_index(INDEX_NAME, table_name=TABLE)
