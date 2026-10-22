"""baseline schema

第一个版本只负责把 SQLAlchemy 元数据落成库，供全新安装使用：

* 全新库：``alembic upgrade head`` 直接建表（含当前模型里声明的索引）。
* 已有库：``alembic stamp 0001_baseline`` 打上基线，再 ``upgrade head`` 只跑增量。

之后所有结构变更必须新增 revision（``alembic revision --autogenerate -m "..."``），
不允许再直接改模型后靠 ``create_all`` 撞运气。
"""

from __future__ import annotations

from alembic import op

import cccms.models  # noqa: F401  - ensure every table is registered
from cccms.db.base import Base

revision = "0001_baseline"
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    Base.metadata.create_all(bind=op.get_bind())


def downgrade() -> None:
    Base.metadata.drop_all(bind=op.get_bind())
