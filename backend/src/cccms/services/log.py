from __future__ import annotations

import asyncio
import csv
import io
import logging
import time
from collections.abc import Coroutine
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

import aiofiles
from sqlalchemy import ColumnElement, Select, case, delete, distinct, func, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.audit import verify_chain as verify_audit_chain
from cccms.core.context import UserContext, current_context
from cccms.core.errors import Conflict, Forbidden, NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.scope import (
    ScopeConfig,
    apply_field_actions,
    apply_scope,
    field_actions,
    redact_field_value,
)
from cccms.core.sorting import apply_sort
from cccms.core.time import now, parse
from cccms.db.session import session_scope
from cccms.models.export import ExportTask
from cccms.models.log import OperationLog
from cccms.models.user import UserDept
from cccms.schemas.log import (
    LogActionOut,
    LogAnalysisOut,
    LogAnalysisSummary,
    LogDetailOut,
    LogExportOut,
    LogIpChange,
    LogOut,
    LogQuery,
    LogSessionOut,
    LogTopItem,
    LogTrendPoint,
)
from cccms.services.export import STATUS_DONE, STATUS_FAILED, STATUS_PENDING, STATUS_RUNNING, export_root

logger = logging.getLogger("cccms.export")

#: 日志里存的是 ASGI 原始路径（带 /api 或 /api/v1 挂载前缀），只按后缀匹配。
LOGIN_PATH_SUFFIX = "/auth/login"
MAX_ACTION_ROWS = 500
MAX_TRACE_ROWS = 200
EXPORT_KEEP_MINUTES = 60

# 保活后台任务引用，避免任务对象被 GC 提前回收。
_BACKGROUND_TASKS: set[asyncio.Task[None]] = set()


def _dept_scope(model: type[OperationLog], dept_ids: tuple[int, ...]) -> ColumnElement[bool]:
    return model.user_id.in_(select(UserDept.user_id).where(UserDept.dept_id.in_(dept_ids)))


OperationLog.__data_scope__ = ScopeConfig(owner="user_id", dept=_dept_scope)

# 允许排序的列（仅前端声明了 sortable: 'custom' 的两列 + 主键）。
SORTABLE = {
    "id": OperationLog.id,
    "create_time": OperationLog.create_time,
    "cost": OperationLog.cost,
}


def _to_out(row: OperationLog) -> LogOut:
    out: LogOut = apply_field_actions(
        OperationLog,
        LogOut(
            id=row.id,
            user_id=row.user_id,
            username=row.username,
            status=row.status,
            message=row.message,
            method=row.method,
            path=row.path,
            node=row.node,
            title=row.title,
            trace_id=row.trace_id,
            action_id=row.action_id,
            session_id=row.session_id,
            ip=row.ip,
            ua=row.ua,
            params=row.params,
            result=row.result,
            status_code=row.status_code,
            cost=row.cost,
            create_time=row.create_time,
        ),
    )
    return out


def _to_detail(row: OperationLog) -> LogDetailOut:
    return LogDetailOut(
        **_to_out(row).model_dump(),
        tenant_id=row.tenant_id,
        prev_hash=row.prev_hash,
        row_hash=row.row_hash,
    )


def _filtered[StatementT: Select[*tuple[Any, ...]]](statement: StatementT, params: LogQuery) -> StatementT:
    if params.keyword:
        value = f"%{params.keyword.strip()}%"
        statement = statement.where(
            OperationLog.username.like(value)
            | OperationLog.title.like(value)
            | OperationLog.path.like(value)
            | OperationLog.message.like(value)
        )
    if params.username:
        statement = statement.where(OperationLog.username.like(f"%{params.username.strip()}%"))
    if params.title:
        statement = statement.where(OperationLog.title.like(f"%{params.title.strip()}%"))
    if params.path:
        statement = statement.where(OperationLog.path == params.path.strip())
    if params.method:
        statement = statement.where(OperationLog.method == params.method.strip().upper())
    if params.status is not None:
        statement = statement.where(OperationLog.status == params.status)
    if params.node:
        statement = statement.where(OperationLog.node == params.node.strip())
    if params.trace_id:
        statement = statement.where(OperationLog.trace_id == params.trace_id.strip())
    if params.action_id:
        statement = statement.where(OperationLog.action_id == params.action_id.strip())
    if params.session_id:
        statement = statement.where(OperationLog.session_id == params.session_id.strip())
    if params.ip:
        statement = statement.where(OperationLog.ip == params.ip.strip())
    if params.params_like:
        statement = statement.where(OperationLog.params.like(f"%{params.params_like.strip()}%"))
    if params.result_like:
        statement = statement.where(OperationLog.result.like(f"%{params.result_like.strip()}%"))
    start = parse(params.start)
    if start is not None:
        statement = statement.where(OperationLog.create_time >= start)
    end = parse(params.end)
    if end is not None:
        statement = statement.where(OperationLog.create_time <= end)
    return statement


async def list_logs(
    session: AsyncSession,
    actor: UserContext,
    params: LogQuery,
) -> tuple[int, list[LogOut]]:
    statement = _filtered(apply_scope(select(OperationLog), OperationLog), params)
    total = int(await session.scalar(count_query(statement, OperationLog.id)) or 0)
    rows = list(
        (
            await session.scalars(
                apply_sort(
                    statement,
                    SORTABLE,
                    id_column=OperationLog.id,
                    fallback=(OperationLog.id.desc(),),
                    sort=params.sort,
                    order=params.order,
                )
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    return total, [_to_out(row) for row in rows]


async def read_log(session: AsyncSession, actor: UserContext, log_id: int) -> LogDetailOut:
    row = await session.scalar(
        apply_scope(select(OperationLog).where(OperationLog.id == log_id), OperationLog)
    )
    if row is None:
        raise NotFound("日志不存在")
    return _to_detail(row)


async def trace(session: AsyncSession, actor: UserContext, trace_id: str) -> LogDetailOut:
    if not trace_id.strip():
        raise ValidationFailed("缺少 trace_id")
    row = await session.scalar(
        apply_scope(
            select(OperationLog).where(OperationLog.trace_id == trace_id.strip()), OperationLog
        ).limit(1)
    )
    if row is None:
        raise NotFound("日志不存在")
    return _to_detail(row)


async def action(session: AsyncSession, actor: UserContext, action_id: str) -> LogActionOut:
    value = action_id.strip()
    if not value:
        raise ValidationFailed("缺少 action_id")
    statement = apply_scope(select(OperationLog).where(OperationLog.action_id == value), OperationLog)
    rows = list(
        (await session.scalars(statement.order_by(OperationLog.id.asc()).limit(MAX_ACTION_ROWS + 1))).all()
    )
    truncated = len(rows) > MAX_ACTION_ROWS
    rows = rows[:MAX_ACTION_ROWS]
    return LogActionOut(
        action_id=value,
        session_id=rows[0].session_id if rows else "",
        total=len(rows),
        failed=sum(1 for row in rows if row.status == 0),
        cost=sum(row.cost for row in rows),
        truncated=truncated,
        list=[_to_detail(row) for row in rows],
    )


async def session_trace(
    session: AsyncSession,
    actor: UserContext,
    session_id: str,
    *,
    after_id: int = 0,
    limit: int = 50,
) -> LogSessionOut:
    value = session_id.strip()
    if not value:
        raise ValidationFailed("缺少 session_id")
    base = apply_scope(select(OperationLog).where(OperationLog.session_id == value), OperationLog)
    total = int(await session.scalar(count_query(base, OperationLog.id)) or 0)
    statement = base
    if after_id:
        statement = statement.where(OperationLog.id > after_id)
    rows = list((await session.scalars(statement.order_by(OperationLog.id.asc()).limit(limit + 1))).all())
    has_more = len(rows) > limit
    rows = rows[:limit]
    return LogSessionOut(
        session_id=value,
        total=total,
        next_cursor=rows[-1].id if rows else None,
        has_more=has_more,
        list=[_to_out(row) for row in rows],
    )


def _window(days: int, start: str | None, end: str | None) -> tuple[datetime, datetime, bool]:
    end_at = parse(end) or now()
    start_at = parse(start)
    if start_at is None:
        clamped = min(90, max(1, days))
        start_at = end_at - timedelta(days=clamped)
    hourly = (end_at - start_at) <= timedelta(hours=48)
    return start_at, end_at, hourly


async def login_analysis(
    session: AsyncSession,
    actor: UserContext,
    *,
    days: int = 7,
    start: str | None = None,
    end: str | None = None,
) -> LogAnalysisOut:
    start_at, end_at, hourly = _window(days, start, end)
    base = apply_scope(
        select(OperationLog).where(
            OperationLog.path.like(f"%{LOGIN_PATH_SUFFIX}"),
            OperationLog.create_time >= start_at,
            OperationLog.create_time < end_at,
        ),
        OperationLog,
    )
    subq = base.subquery()
    summary_row = (
        await session.execute(
            select(
                func.count(subq.c.id),
                func.coalesce(func.sum(case((subq.c.status == 1, 1), else_=0)), 0),
                func.count(distinct(case((subq.c.status == 0, subq.c.username), else_=None))),
                func.count(distinct(case((subq.c.status == 0, subq.c.ip), else_=None))),
            )
        )
    ).one()
    total = int(summary_row[0] or 0)
    success = int(summary_row[1] or 0)
    failed_users = int(summary_row[2] or 0)
    failed_ips = int(summary_row[3] or 0)
    failed = max(0, total - success)

    bucket_expr = func.date_format(OperationLog.create_time, "%Y-%m-%d %H:00:00" if hourly else "%Y-%m-%d")
    trend_rows = (
        await session.execute(
            select(
                bucket_expr.label("bucket"),
                func.count(OperationLog.id),
                func.coalesce(func.sum(case((OperationLog.status == 0, 1), else_=0)), 0),
            )
            .where(OperationLog.id.in_(select(subq.c.id)))
            .group_by(bucket_expr)
        )
    ).all()
    bucket_map = {str(row[0]): (int(row[1] or 0), int(row[2] or 0)) for row in trend_rows}
    trend: list[LogTrendPoint] = []
    cursor = (
        start_at.replace(minute=0, second=0, microsecond=0)
        if hourly
        else start_at.replace(hour=0, minute=0, second=0, microsecond=0)
    )
    step = timedelta(hours=1) if hourly else timedelta(days=1)
    pattern = "%Y-%m-%d %H:00:00" if hourly else "%Y-%m-%d"
    while cursor < end_at:
        key = cursor.strftime(pattern)
        item = bucket_map.get(key, (0, 0))
        trend.append(
            LogTrendPoint(bucket=key, total=item[0], success=max(0, item[0] - item[1]), failed=item[1])
        )
        cursor += step

    top_users = [
        LogTopItem(name=str(name), total=int(count), count=int(count), username=str(name))
        for name, count in (
            await session.execute(
                select(OperationLog.username, func.count(OperationLog.id))
                .where(
                    OperationLog.id.in_(select(subq.c.id)),
                    OperationLog.status == 0,
                    OperationLog.username != "",
                )
                .group_by(OperationLog.username)
                .order_by(func.count(OperationLog.id).desc())
                .limit(10)
            )
        ).all()
    ]
    top_ips = [
        LogTopItem(name=str(name), total=int(count), count=int(count), ip=str(name))
        for name, count in (
            await session.execute(
                select(OperationLog.ip, func.count(OperationLog.id))
                .where(
                    OperationLog.id.in_(select(subq.c.id)),
                    OperationLog.status == 0,
                    OperationLog.ip != "",
                )
                .group_by(OperationLog.ip)
                .order_by(func.count(OperationLog.id).desc())
                .limit(10)
            )
        ).all()
    ]

    login_rows = (
        await session.execute(
            select(
                OperationLog.id,
                OperationLog.user_id,
                OperationLog.username,
                OperationLog.ip,
                OperationLog.create_time,
            )
            .where(
                OperationLog.id.in_(select(subq.c.id)),
                OperationLog.status == 1,
                OperationLog.ip != "",
            )
            .order_by(OperationLog.user_id.asc(), OperationLog.id.asc())
        )
    ).all()
    changes: list[LogIpChange] = []
    previous: dict[int, tuple[str, str]] = {}
    for _log_id, user_id, username, ip, create_time in login_rows:
        last = previous.get(int(user_id))
        if last is not None and last[1] != ip:
            changes.append(
                LogIpChange(
                    user_id=int(user_id),
                    username=str(username or last[0]),
                    from_ip=last[1],
                    to_ip=str(ip),
                    at=create_time,
                    time=create_time,
                )
            )
        previous[int(user_id)] = (str(username or ""), str(ip))

    rate = round(failed / total * 100, 2) if total else 0.0
    return LogAnalysisOut(
        range={
            "start": start_at.strftime("%Y-%m-%d %H:%M:%S"),
            "end": end_at.strftime("%Y-%m-%d %H:%M:%S"),
            "granularity": "hour" if hourly else "day",
        },
        summary=LogAnalysisSummary(
            total=total,
            success=success,
            failed=failed,
            failure_rate=rate,
            fail_rate=rate,
            failed_users=failed_users,
            failed_ips=failed_ips,
            users=failed_users,
            ips=failed_ips,
        ),
        trend=trend,
        top_users=top_users,
        top_ips=top_ips,
        ip_changes=changes[-10:],
    )


async def delete_logs(session: AsyncSession, actor: UserContext, ids: list[int]) -> tuple[int, list[int]]:
    # 已封存的日志参与哈希链校验，删除会让链断裂；只允许清理尚未封存的新行。
    rows = (
        await session.execute(
            apply_scope(
                select(OperationLog.id, OperationLog.row_hash).where(OperationLog.id.in_(ids)),
                OperationLog,
            )
        )
    ).all()
    deletable = {int(row_id) for row_id, row_hash in rows if not row_hash}
    sealed = {int(row_id) for row_id, row_hash in rows if row_hash}
    if sealed:
        raise Conflict("已封存的审计日志不可删除，请通过归档与清理任务处理")
    skipped = [item for item in dict.fromkeys(ids) if item not in deletable]
    if not deletable:
        return 0, skipped
    await session.execute(delete(OperationLog).where(OperationLog.id.in_(deletable)))
    await session.commit()
    return len(deletable), skipped


def _csv_safe(value: object) -> str:
    text = "" if value is None else str(value)
    if text.startswith(("=", "+", "-", "@")):
        return f"'{text}"
    return text


EXPORT_MAX_ROWS = 100_000
EXPORT_COLUMNS = (
    "id",
    "username",
    "status",
    "message",
    "method",
    "path",
    "node",
    "title",
    "action_id",
    "trace_id",
    "session_id",
    "ip",
    "status_code",
    "cost",
    "create_time",
)


def _csv_header() -> str:
    buffer = io.StringIO()
    csv.writer(buffer).writerow(EXPORT_COLUMNS)
    return buffer.getvalue()


def _csv_line(row: OperationLog, actions: dict[str, str]) -> str:
    buffer = io.StringIO()
    csv.writer(buffer).writerow(
        [
            _csv_safe(
                redact_field_value(column, actions[column], getattr(row, column))
                if column in actions
                else getattr(row, column)
            )
            for column in EXPORT_COLUMNS
        ]
    )
    return buffer.getvalue()


async def _stream_csv(path: Path, statement: Select[Any], actions: dict[str, str]) -> int:
    """边读边写：不再把 10 万条 ORM 对象整体驻留内存。"""
    path.parent.mkdir(parents=True, exist_ok=True)
    written = 0
    buffer: list[str] = []
    async with aiofiles.open(path, "w", encoding="utf-8-sig", newline="") as handle:
        await handle.write(_csv_header())
        async with session_scope() as session:
            result = await session.stream(statement.execution_options(yield_per=1000))
            async for row in result.scalars():
                buffer.append(_csv_line(row, actions))
                written += 1
                if len(buffer) >= 1000:
                    await handle.write("".join(buffer))
                    buffer.clear()
        if buffer:
            await handle.write("".join(buffer))
    return written


def _schedule(coro: Coroutine[Any, Any, None]) -> None:
    task = asyncio.create_task(coro)
    _BACKGROUND_TASKS.add(task)
    task.add_done_callback(_BACKGROUND_TASKS.discard)


_EXPORT_SEMAPHORE: asyncio.Semaphore | None = None


def _export_semaphore() -> asyncio.Semaphore:
    """进程内并发闸门：导出是 CPU+IO 重活，同时跑太多会把数据库拖慢。"""
    global _EXPORT_SEMAPHORE
    if _EXPORT_SEMAPHORE is None:
        _EXPORT_SEMAPHORE = asyncio.Semaphore(max(1, get_settings().export_max_concurrency))
    return _EXPORT_SEMAPHORE


async def _mark_export_failed(task_id: int, path: Path | None, reason: str) -> None:
    if path is not None:
        # 落盘目录可能挂在慢盘上, 删除动作放到线程池, 不阻塞事件循环。
        await asyncio.to_thread(path.unlink, missing_ok=True)
    try:
        async with asyncio.timeout(3):
            async with session_scope() as session:
                task = await session.get(ExportTask, task_id)
                if task is not None:
                    task.status = STATUS_FAILED
                    task.error = reason[:500]
                    await session.commit()
    except Exception:
        logger.warning("failed to persist export failure task=%s", task_id, exc_info=True)


async def shutdown_exports() -> None:
    """停掉未完成的导出任务。

    不取消的话，进程退出后任务会停在 RUNNING，只能等启动期对账（30 分钟）才恢复。
    """
    pending = [task for task in _BACKGROUND_TASKS if not task.done()]
    if not pending:
        return
    for task in pending:
        task.cancel()
    await asyncio.gather(*pending, return_exceptions=True)
    logger.info("cancelled %s in-flight export task(s)", len(pending))


async def _run_log_export(task_id: int, statement: Select[Any], actions: dict[str, str]) -> None:
    """后台生成导出文件。

    语句与字段脱敏快照都在请求内构建好再传进来：后台协程没有 RequestContext，
    现场调用 ``apply_scope``/``field_actions`` 会静默退化（等于绕过租户与脱敏）。
    """
    path = export_root() / f"log-{task_id}.csv"
    try:
        async with session_scope() as session:
            task = await session.get(ExportTask, task_id)
            if task is None:
                return
            task.status = STATUS_RUNNING
            await session.commit()
        async with _export_semaphore():
            written = await _stream_csv(path, statement, actions)
        async with session_scope() as session:
            task = await session.get(ExportTask, task_id)
            if task is None:
                return
            task.status = STATUS_DONE
            task.progress = 100
            task.file_path = path.name
            task.file_size = path.stat().st_size
            task.row_count = written
            await session.commit()
    except asyncio.CancelledError:
        await _mark_export_failed(task_id, path, "服务重启，导出任务已取消")
        raise
    except Exception as exc:
        logger.exception("log export failed task=%s", task_id)
        await _mark_export_failed(task_id, path, str(exc))


async def export_logs(session: AsyncSession, actor: UserContext, params: LogQuery) -> LogExportOut:
    active = int(
        await session.scalar(
            select(func.count())
            .select_from(ExportTask)
            .where(
                ExportTask.user_id == actor.id,
                ExportTask.status.in_((STATUS_PENDING, STATUS_RUNNING)),
            )
        )
        or 0
    )
    if active >= max(1, get_settings().export_max_active_per_user):
        raise Conflict("已有导出任务在执行中，请等待完成后再试")

    statement = _filtered(apply_scope(select(OperationLog), OperationLog), params)
    total = int(await session.scalar(count_query(statement, OperationLog.id)) or 0)
    task = ExportTask(
        tenant_id=actor.tenant_id,
        user_id=actor.id,
        type="log",
        params=params.model_dump(mode="json"),
        status=STATUS_PENDING,
        progress=0,
        expires_at=now() + timedelta(minutes=EXPORT_KEEP_MINUTES),
    )
    session.add(task)
    await session.commit()

    export_root().mkdir(parents=True, exist_ok=True)
    stream_statement = (
        statement.order_by(OperationLog.id.asc()).limit(EXPORT_MAX_ROWS).execution_options(yield_per=1000)
    )
    # 字段级规则在这里快照：后台协程没有上下文，必须复用请求内算好的结果。
    _schedule(_run_log_export(task.id, stream_statement, field_actions(OperationLog)))
    return LogExportOut(task_id=task.id, total=total, async_=True)


# ---- 审计链校验（A-02） ----

AUDIT_SAMPLE_SIZE = 20


def summarize_audit(raw: dict[str, Any], *, duration_ms: int) -> dict[str, Any]:
    """把 ``verify_chain`` 的原始结果压成响应体。

    命中 id 只留前 ``AUDIT_SAMPLE_SIZE`` 条，数量单独给：链断了通常一次一大片，
    全量 id 既没用又会把响应体撑爆。
    """
    broken = [int(item) for item in raw.get("broken") or []]
    modified = [int(item) for item in raw.get("modified") or []]
    return {
        "ok": bool(raw.get("ok")),
        "total": int(raw.get("total") or 0),
        "unchained": int(raw.get("unchained") or 0),
        "broken_count": len(broken),
        "modified_count": len(modified),
        "broken_ids": broken[:AUDIT_SAMPLE_SIZE],
        "modified_ids": modified[:AUDIT_SAMPLE_SIZE],
        "tail_stale": bool(raw.get("tail_stale")),
        "seal_ok": bool(raw.get("seal_ok")),
        "duration_ms": duration_ms,
    }


async def verify_audit(actor: UserContext) -> dict[str, Any]:
    """校验审计哈希链。

    链是**全局**的（``prev_hash`` 跨租户连续），只按本租户切一片会让校验必然报 broken，
    所以这里只允许平台租户超级管理员触发——这是数据边界，不是权限节点的重复。
    ``verify_chain`` 自己开 session 并按 keyset 分页，不占用请求会话、不把整表读进内存。
    """
    ctx = current_context()
    platform_admin = bool(
        actor.is_super_admin and ctx is not None and ctx.tenant is not None and ctx.tenant.is_platform
    )
    if not platform_admin:
        raise Forbidden("审计链覆盖全部租户，仅平台租户超级管理员可校验")
    started = time.perf_counter()
    raw = await verify_audit_chain()
    return summarize_audit(raw, duration_ms=int((time.perf_counter() - started) * 1000))
