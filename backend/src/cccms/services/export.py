from __future__ import annotations

from datetime import timedelta
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.context import UserContext
from cccms.core.errors import NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.time import now
from cccms.db.session import session_scope
from cccms.models.export import ExportTask
from cccms.schemas.export import ExportTaskOut

STATUS_PENDING = 0
STATUS_RUNNING = 1
STATUS_DONE = 2
STATUS_FAILED = 3

# 后台导出跑在 API 进程里：进程重启会让任务永远停在 0/1，前端会一直转圈。
STALE_TASK_MINUTES = 30


async def reconcile_stale_tasks() -> int:
    """启动期对账：把上一个进程遗留的未完成任务标记为失败。"""
    async with session_scope() as session:
        cutoff = now() - timedelta(minutes=STALE_TASK_MINUTES)
        rows = list(
            (
                await session.scalars(
                    select(ExportTask).where(
                        ExportTask.status.in_((STATUS_PENDING, STATUS_RUNNING)),
                        ExportTask.create_time < cutoff,
                    )
                )
            ).all()
        )
        for row in rows:
            row.status = STATUS_FAILED
            row.error = row.error or "执行进程重启，任务已中断，请重新导出"
        await session.commit()
    return len(rows)


def _to_out(row: ExportTask) -> ExportTaskOut:
    return ExportTaskOut(
        id=row.id,
        type=row.type,
        status=row.status,
        progress=row.progress,
        file_size=row.file_size,
        row_count=row.row_count,
        error=row.error,
        expires_at=row.expires_at,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def list_tasks(
    session: AsyncSession,
    actor: UserContext,
    *,
    page: int = 1,
    limit: int = 15,
) -> tuple[int, list[ExportTaskOut]]:
    statement = select(ExportTask).where(
        ExportTask.tenant_id == actor.tenant_id,
        ExportTask.user_id == actor.id,
    )
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(ExportTask.id.desc()).offset((page - 1) * limit).limit(limit)
            )
        ).all()
    )
    return total, [_to_out(row) for row in rows]


def export_root() -> Path:
    """导出目录独立于静态资源目录，下载必须经过鉴权接口。"""
    return get_settings().export_root


def purge_file(file_path: str | None) -> bool:
    """删除导出文件，路径必须落在导出根目录内。"""
    if not file_path:
        return False
    root = export_root().resolve()
    path = Path(file_path)
    if not path.is_absolute():
        path = root / path
    resolved = path.resolve()
    if root not in resolved.parents:
        return False
    if not resolved.is_file():
        return False
    resolved.unlink(missing_ok=True)
    return True


async def clean_expired(
    session: AsyncSession,
    tenant_id: int | None,
    *,
    all_tenants: bool = False,
) -> tuple[int, int]:
    """Delete expired export tasks and their files. Returns (tasks, files)."""
    statement = select(ExportTask).where(
        ExportTask.expires_at.is_not(None),
        ExportTask.expires_at < now(),
    )
    if not all_tenants:
        statement = statement.where(ExportTask.tenant_id == tenant_id)
    rows = list((await session.scalars(statement)).all())
    files = 0
    for row in rows:
        if purge_file(row.file_path):
            files += 1
        await session.delete(row)
    await session.commit()
    return len(rows), files


async def download_path(session: AsyncSession, actor: UserContext, task_id: int) -> tuple[Path, str]:
    row = await session.scalar(
        select(ExportTask).where(
            ExportTask.id == task_id,
            ExportTask.tenant_id == actor.tenant_id,
            ExportTask.user_id == actor.id,
        )
    )
    if row is None:
        raise NotFound("导出任务不存在")
    if row.status != STATUS_DONE or not row.file_path:
        raise ValidationFailed("导出任务尚未完成")
    if row.expires_at is not None and row.expires_at < now():
        raise NotFound("导出文件已过期")
    path = Path(row.file_path)
    if not path.is_absolute():
        path = export_root() / path
    resolved = path.resolve()
    if export_root().resolve() not in resolved.parents or not resolved.exists():
        raise NotFound("导出文件不存在或已过期")
    return resolved, f"{row.type}-{row.id}{resolved.suffix or '.csv'}"
