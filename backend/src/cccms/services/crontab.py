from __future__ import annotations

import logging
import secrets
import time
from collections.abc import Awaitable, Callable
from datetime import datetime, timedelta
from typing import Any

from croniter import croniter
from sqlalchemy import bindparam, delete, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.audit import seal_pending
from cccms.core.cache import get_redis
from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.scope import ScopeConfig, apply_scope
from cccms.core.sorting import apply_sort
from cccms.core.time import now, parse
from cccms.db.session import session_scope
from cccms.models.crontab import Crontab, CrontabLog, CrontabRetry
from cccms.models.log import OperationLog
from cccms.models.notice import Notice, NoticeRead
from cccms.models.tenant import Tenant
from cccms.schemas.crontab import (
    CrontabLogOut,
    CrontabLogQuery,
    CrontabOut,
    CrontabQuery,
    CrontabSave,
    CrontabTargetOut,
)
from cccms.services import export as export_service
from cccms.services import file as file_service

Task = Callable[[dict[str, Any] | None], Awaitable[Any]]

logger = logging.getLogger("cccms.crontab")


async def _seal_task(params: dict[str, Any] | None) -> str:
    batch = int((params or {}).get("batch", 200))
    sealed = await seal_pending(batch=max(1, min(2000, batch)))
    return f"已封存 {sealed} 条日志"


async def _clean_logs_task(params: dict[str, Any] | None) -> str:
    options = params or {}
    days = int(options.get("days", get_settings().log_keep_days))
    days = max(1, min(3650, days))
    cutoff = now() - timedelta(days=days)
    tenant_id = options.get("__tenant_id")
    result = await _clean_logs(cutoff, int(tenant_id) if tenant_id is not None else None)
    return f"已清理 {result} 条 {days} 天前的日志"


CLEAN_BATCH_SIZE = 5000


async def _clean_logs(cutoff: datetime, tenant_id: int | None = None) -> int:
    """Delete expired logs for one tenant.

    Only the platform tenant may clean across tenants; a tenant-level task is
    always restricted to its own rows so one customer cannot erase another's
    audit trail.
    """
    statement = select(OperationLog.id).where(OperationLog.create_time < cutoff)
    if tenant_id is None:
        statement = statement.where(OperationLog.tenant_id.is_(None))
    else:
        async with session_scope() as session:
            tenant = await session.get(Tenant, tenant_id)
        if not (tenant and tenant.is_platform):
            statement = statement.where(OperationLog.tenant_id == tenant_id)
    return await _delete_by_ids(OperationLog, statement)


async def _delete_by_ids(model: Any, select_ids: Any) -> int:
    removed = 0
    while True:
        async with session_scope() as session:
            ids = list((await session.scalars(select_ids.limit(CLEAN_BATCH_SIZE))).all())
            if not ids:
                break
            await session.execute(delete(model).where(model.id.in_(ids)))
        removed += len(ids)
        if len(ids) < CLEAN_BATCH_SIZE:
            break
    return removed


async def _archive_logs_task(params: dict[str, Any] | None) -> str:
    """把热表里更早的日志搬到 ``sys_log_archive``，而不是直接删除。

    只处理未封存的行（``row_hash IS NULL``）：参与哈希链的行一经移动，
    ``cccms.audit.verify`` 的结果就会失真。
    """
    options = params or {}
    days = max(1, min(3650, int(options.get("days", 90))))
    tenant_id = options.get("__tenant_id")
    if tenant_id is None:
        return "缺少租户上下文，已跳过"

    cutoff = now() - timedelta(days=days)
    async with session_scope() as session:
        tenant = await session.get(Tenant, int(tenant_id))
        scope_filter = OperationLog.create_time < cutoff
        scope_filter = scope_filter & OperationLog.row_hash.is_(None)
        if not (tenant and tenant.is_platform):
            scope_filter = scope_filter & (OperationLog.tenant_id == int(tenant_id))
        select_ids = select(OperationLog.id).where(scope_filter)

        columns = ", ".join(column.name for column in OperationLog.__table__.columns)
        # 列名来自模型元数据(非用户输入), 主键集合走绑定参数, 不存在注入面。
        insert_sql = text(
            f"INSERT INTO sys_log_archive ({columns}) SELECT {columns} FROM sys_log WHERE id IN :ids"  # noqa: S608
        ).bindparams(bindparam("ids", expanding=True))

        archived = 0
        while True:
            ids = list((await session.scalars(select_ids.limit(CLEAN_BATCH_SIZE))).all())
            if not ids:
                break
            await session.execute(insert_sql, {"ids": ids})
            await session.execute(delete(OperationLog).where(OperationLog.id.in_(ids)))
            await session.commit()
            archived += len(ids)
            if len(ids) < CLEAN_BATCH_SIZE:
                break
    return f"已归档 {archived} 条 {days} 天前的未封存日志"


async def _clean_crontab_logs_task(params: dict[str, Any] | None) -> str:
    """任务日志每次执行都会写一行，不清理会按执行频率线性膨胀。"""
    options = params or {}
    days = max(1, min(3650, int(options.get("days", 30))))
    tenant_id = options.get("__tenant_id")
    if tenant_id is None:
        return "缺少租户上下文，已跳过"
    cutoff = now() - timedelta(days=days)
    async with session_scope() as session:
        tenant = await session.get(Tenant, int(tenant_id))
    statement = (
        select(CrontabLog.id)
        .join(Crontab, Crontab.id == CrontabLog.crontab_id)
        .where(CrontabLog.create_time < cutoff)
    )
    if not (tenant and tenant.is_platform):
        statement = statement.where(Crontab.tenant_id == int(tenant_id))
    removed = await _delete_by_ids(CrontabLog, statement)
    return f"已清理 {removed} 条 {days} 天前的任务日志"


async def _clean_notice_reads_task(params: dict[str, Any] | None) -> str:
    """已读回执按“用户 × 公告”增长，需要保留期。"""
    options = params or {}
    days = max(1, min(3650, int(options.get("days", 90))))
    tenant_id = options.get("__tenant_id")
    if tenant_id is None:
        return "缺少租户上下文，已跳过"
    cutoff = now() - timedelta(days=days)
    async with session_scope() as session:
        tenant = await session.get(Tenant, int(tenant_id))
    statement = (
        select(NoticeRead.id)
        .join(Notice, Notice.id == NoticeRead.notice_id)
        .where(NoticeRead.read_time < cutoff)
    )
    if not (tenant and tenant.is_platform):
        statement = statement.where(Notice.tenant_id == int(tenant_id))
    removed = await _delete_by_ids(NoticeRead, statement)
    return f"已清理 {removed} 条 {days} 天前的已读回执"


async def _clean_exports_task(params: dict[str, Any] | None) -> str:
    tenant_id = (params or {}).get("__tenant_id")
    if tenant_id is None:
        return "缺少租户上下文，已跳过"
    async with session_scope() as session:
        tenant = await session.get(Tenant, int(tenant_id))
        all_tenants = bool(tenant and tenant.is_platform)
        tasks, files = await export_service.clean_expired(
            session,
            int(tenant_id),
            all_tenants=all_tenants,
        )
    return f"已清理 {tasks} 个过期导出任务、{files} 个文件"


async def _clean_chunks_task(params: dict[str, Any] | None) -> str:
    options = params or {}
    tenant_id = options.get("__tenant_id")
    if tenant_id is None:
        return "缺少租户上下文，已跳过"
    async with session_scope() as session:
        tenant = await session.get(Tenant, int(tenant_id))
    if not (tenant and tenant.is_platform):
        return "仅平台租户执行分片清理"
    hours = max(1, min(24 * 30, int(options.get("hours", 48))))
    removed = file_service.cleanup_chunks(hours)
    return f"已清理 {removed} 个超过 {hours} 小时的分片目录"


TARGETS: dict[str, tuple[str, Task]] = {
    "cccms.audit.seal": ("封存审计日志链", _seal_task),
    "cccms.log.archive": ("归档历史操作日志", _archive_logs_task),
    "cccms.log.clean": ("清理过期操作日志", _clean_logs_task),
    "cccms.crontabLog.clean": ("清理过期任务日志", _clean_crontab_logs_task),
    "cccms.noticeRead.clean": ("清理过期已读回执", _clean_notice_reads_task),
    "cccms.export.clean": ("清理过期导出文件", _clean_exports_task),
    "cccms.upload.cleanChunks": ("清理未完成分片", _clean_chunks_task),
}

# 定时任务没有 owner / dept 列，只接受自定义规则（no_baseline）。
Crontab.__data_scope__ = ScopeConfig(no_baseline=True)

# 允许排序的列：前端只给"上次执行时间"留了排序入口。
SORTABLE = {"id": Crontab.id, "last_run_time": Crontab.last_run_time}


def _to_out(row: Crontab) -> CrontabOut:
    return CrontabOut(
        id=row.id,
        name=row.name,
        cron=row.cron,
        target=row.target,
        params=row.params,
        group_name=row.group_name,
        overlap=row.overlap,
        timeout=row.timeout,
        retry_times=row.retry_times,
        retry_interval=row.retry_interval,
        status=row.status,
        running=row.running,
        running_at=row.running_at,
        last_run_time=row.last_run_time,
        next_run_time=row.next_run_time,
        last_status=row.last_status,
        last_error=row.last_error,
        remark=row.remark,
        create_time=row.create_time,
        update_time=row.update_time,
    )


def _log_out(row: CrontabLog) -> CrontabLogOut:
    return CrontabLogOut(
        id=row.id,
        crontab_id=row.crontab_id,
        status=row.status,
        source=row.source,
        output=row.output,
        cost=row.cost,
        run_time=row.run_time,
        trace_id=row.trace_id,
        create_time=row.create_time,
    )


def _next_run(expr: str, base: datetime | None = None) -> datetime | None:
    try:
        # croniter 没有类型标注, 显式标注返回值避免 Any 泄漏到调度逻辑。
        next_run: datetime = croniter(expr, base or now()).get_next(datetime)
        return next_run
    except (ValueError, KeyError):
        return None


def _validate(payload: CrontabSave) -> None:
    if not croniter.is_valid(payload.cron):
        raise ValidationFailed("Cron 表达式不合法（支持秒 分 时 日 月 周）")
    if payload.target not in TARGETS:
        raise ValidationFailed("任务目标不在白名单内")


async def list_crontab(
    session: AsyncSession,
    actor: UserContext,
    params: CrontabQuery,
) -> tuple[int, list[CrontabOut]]:
    statement = apply_scope(select(Crontab), Crontab, include_deleted=params.trashed == 1)
    statement = statement.where(
        Crontab.delete_time.is_not(None) if params.trashed else Crontab.delete_time.is_(None)
    )
    if params.keyword or params.name:
        value = f"%{(params.name or params.keyword or '').strip()}%"
        statement = statement.where(Crontab.name.like(value) | Crontab.target.like(value))
    if params.group_name:
        statement = statement.where(Crontab.group_name == params.group_name)
    if params.status is not None:
        statement = statement.where(Crontab.status == params.status)
    start = parse(params.start)
    end = parse(params.end)
    if start is not None:
        statement = statement.where(Crontab.create_time >= start)
    if end is not None:
        statement = statement.where(Crontab.create_time <= end)
    next_start = parse(params.next_start)
    next_end = parse(params.next_end)
    if next_start is not None:
        statement = statement.where(Crontab.next_run_time >= next_start)
    if next_end is not None:
        statement = statement.where(Crontab.next_run_time <= next_end)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                apply_sort(
                    statement,
                    SORTABLE,
                    id_column=Crontab.id,
                    fallback=(Crontab.id.asc(),),
                    sort=params.sort,
                    order=params.order,
                )
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    return total, [_to_out(row) for row in rows]


def targets() -> list[CrontabTargetOut]:
    return [CrontabTargetOut(value=key, label=value[0]) for key, value in TARGETS.items()]


async def _get_row(session: AsyncSession, actor: UserContext, crontab_id: int) -> Crontab:
    row = await session.scalar(apply_scope(select(Crontab).where(Crontab.id == crontab_id), Crontab))
    if row is None:
        raise NotFound("定时任务不存在")
    return row


async def create_crontab(session: AsyncSession, actor: UserContext, payload: CrontabSave) -> CrontabOut:
    _validate(payload)
    row = Crontab(
        tenant_id=actor.tenant_id,
        next_run_time=_next_run(payload.cron) if payload.status == 1 else None,
        **payload.model_dump(),
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return _to_out(row)


async def update_crontab(
    session: AsyncSession,
    actor: UserContext,
    crontab_id: int,
    payload: CrontabSave,
) -> CrontabOut:
    row = await _get_row(session, actor, crontab_id)
    _validate(payload)
    for key, value in payload.model_dump().items():
        setattr(row, key, value)
    row.next_run_time = _next_run(payload.cron) if payload.status == 1 else None
    row.update_time = now()
    await session.commit()
    return _to_out(row)


async def delete_crontab(session: AsyncSession, actor: UserContext, crontab_id: int) -> None:
    row = await _get_row(session, actor, crontab_id)
    if row.running:
        raise Conflict("任务正在运行，无法删除")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def list_logs(
    session: AsyncSession,
    actor: UserContext,
    params: CrontabLogQuery,
) -> tuple[int, list[CrontabLogOut]]:
    task_ids = select(Crontab.id).where(
        Crontab.tenant_id == actor.tenant_id,
        Crontab.delete_time.is_(None),
    )
    statement = select(CrontabLog).where(CrontabLog.crontab_id.in_(task_ids))
    if params.crontab_id:
        statement = statement.where(CrontabLog.crontab_id == params.crontab_id)
    if params.status is not None:
        statement = statement.where(CrontabLog.status == params.status)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(CrontabLog.id.desc())
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    return total, [_log_out(row) for row in rows]


async def _execute(
    session: AsyncSession,
    row: Crontab,
    *,
    source: str,
    attempt: int = 0,
) -> CrontabLogOut:
    entry = TARGETS.get(row.target)
    if entry is None:
        raise ValidationFailed("任务目标不在白名单内")

    row.running = 1
    row.running_at = now()
    await session.commit()

    started = time.perf_counter()
    status = 1
    output: str | None = None
    try:
        # 任务本身不知道自己属于哪个租户，显式注入，避免跨租户批量操作。
        task_params = dict(row.params or {})
        task_params["__tenant_id"] = row.tenant_id
        result = await entry[1](task_params)
        output = str(result) if result is not None else "ok"
    # 单次任务失败要落到 sys_crontab_log, 不能让调度循环中断。
    except Exception as exc:  # noqa: BLE001
        status = 0
        output = f"{type(exc).__name__}: {exc}"
    cost = int((time.perf_counter() - started) * 1000)

    log = CrontabLog(
        crontab_id=row.id,
        status=status,
        source=source,
        output=output[:8000] if output else None,
        cost=cost,
        run_time=now(),
        create_time=now(),
    )
    session.add(log)
    row.running = 0
    row.running_at = None
    row.last_run_time = now()
    row.last_status = status
    row.last_error = None if status == 1 else (output or "")[:2000]
    row.next_run_time = _next_run(row.cron) if row.status == 1 else None

    if status == 0 and row.retry_times > attempt:
        session.add(
            CrontabRetry(
                crontab_id=row.id,
                payload=row.params,
                attempt=attempt + 1,
                retry_at=now() + timedelta(seconds=max(5, row.retry_interval)),
                last_error=(output or "")[:2000],
                create_time=now(),
            )
        )

    await session.commit()
    await session.refresh(log)
    return _log_out(log)


async def run_now(session: AsyncSession, actor: UserContext, crontab_id: int) -> CrontabLogOut:
    row = await _get_row(session, actor, crontab_id)
    if row.running and row.overlap == "skip":
        raise Conflict("任务正在运行")
    token = await _acquire("task", row.id, max(row.timeout, get_settings().cron_lock_ttl))
    if token is None:
        raise Conflict("任务正在运行")
    try:
        return await _execute(session, row, source="manual")
    finally:
        await _release("task", row.id, token)


def _lock_key(kind: str, item_id: int) -> str:
    return f"cccms:cron:{kind}:{item_id}"


async def _acquire(kind: str, item_id: int, ttl: int) -> str | None:
    token = secrets.token_hex(8)
    try:
        client = get_redis()
        if await client.set(_lock_key(kind, item_id), token, nx=True, ex=max(30, ttl)):
            return token
    except Exception:  # Redis 不可用时调度器跳过本轮
        logger.warning("cron lock unavailable", exc_info=True)
    return None


async def _release(kind: str, item_id: int, token: str) -> None:
    try:
        client = get_redis()
        key = _lock_key(kind, item_id)
        if await client.get(key) == token:
            await client.delete(key)
    except Exception:
        logger.debug("cron lock release failed", exc_info=True)


async def _run_retries(session: AsyncSession) -> int:
    rows = list(
        (
            await session.scalars(
                select(CrontabRetry)
                .where(CrontabRetry.retry_at <= now())
                .order_by(CrontabRetry.retry_at.asc())
                .limit(20)
            )
        ).all()
    )
    ran = 0
    for retry in rows:
        token = await _acquire("retry", retry.id, get_settings().cron_lock_ttl)
        if token is None:
            continue
        try:
            task = await session.scalar(
                select(Crontab).where(
                    Crontab.id == retry.crontab_id,
                    Crontab.delete_time.is_(None),
                )
            )
            if task is None:
                await session.delete(retry)
                await session.commit()
                continue
            await session.delete(retry)
            await session.commit()
            await _execute(session, task, source="retry", attempt=retry.attempt)
            ran += 1
        finally:
            await _release("retry", retry.id, token)
    return ran


async def run_due(limit: int = 50) -> int:
    """Run scheduled tasks that are due.  Called by ``cccms.scheduler``."""
    settings = get_settings()
    async with session_scope() as session:
        rows = list(
            (
                await session.scalars(
                    select(Crontab)
                    .where(
                        Crontab.status == 1,
                        Crontab.delete_time.is_(None),
                        Crontab.next_run_time.is_not(None),
                        Crontab.next_run_time <= now(),
                    )
                    .order_by(Crontab.next_run_time.asc())
                    .limit(limit)
                )
            ).all()
        )
        ran = 0
        for row in rows:
            if row.running:
                stale_after = max(60, row.timeout or settings.cron_lock_ttl)
                if row.running_at and (now() - row.running_at).total_seconds() <= stale_after:
                    row.next_run_time = _next_run(row.cron)
                    await session.commit()
                    continue
                # 执行进程崩溃后残留的 running 标志，超过阈值视为失效
                row.running = 0
                row.running_at = None
                await session.commit()
            token = await _acquire("task", row.id, max(row.timeout, settings.cron_lock_ttl))
            if token is None:
                continue
            try:
                await _execute(session, row, source="cron")
                ran += 1
            finally:
                await _release("task", row.id, token)

        ran += await _run_retries(session)
        return ran
