from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import logging
from contextlib import suppress
from dataclasses import dataclass
from datetime import datetime
from typing import Any

from sqlalchemy import func, select

from cccms.config import get_settings
from cccms.core.metrics import AUDIT_DROPPED
from cccms.core.time import fmt
from cccms.db.session import session_scope
from cccms.models.log import AuditSeal, OperationLog

logger = logging.getLogger("cccms.audit")

HASH_VERSION = 1
HASH_FIELDS = (
    "id",
    "tenant_id",
    "user_id",
    "username",
    "method",
    "path",
    "node",
    "title",
    "status",
    "message",
    "trace_id",
    "action_id",
    "session_id",
    "ip",
    "ua",
    "params",
    "result",
    "status_code",
    "cost",
    "create_time",
)


@dataclass(slots=True)
class LogEvent:
    tenant_id: int | None
    user_id: int
    username: str
    status: int
    message: str
    method: str
    path: str
    node: str
    title: str
    trace_id: str
    action_id: str
    session_id: str
    ip: str
    ua: str
    params: str | None
    result: str | None
    status_code: int
    cost: int
    create_time: datetime


_queue: asyncio.Queue[LogEvent] | None = None
_writer: asyncio.Task[None] | None = None


def _get_queue() -> asyncio.Queue[LogEvent]:
    global _queue
    if _queue is None:
        _queue = asyncio.Queue(maxsize=5000)
    return _queue


async def start_log_writer() -> None:
    global _writer
    if _writer is None or _writer.done():
        _writer = asyncio.create_task(_writer_loop(), name="cccms-log-writer")


async def stop_log_writer() -> None:
    global _writer
    if _writer is None:
        return
    _writer.cancel()
    with suppress(asyncio.CancelledError):
        await _writer
    _writer = None


_dropped_logs = 0


def enqueue(event: LogEvent) -> None:
    global _dropped_logs
    if event.path.startswith(("/healthz", "/metrics", "/ping")):
        return
    queue = _get_queue()
    try:
        queue.put_nowait(event)
    except asyncio.QueueFull:
        _dropped_logs += 1
        # 指标用于长期观测（容器里的 logger.error 常没人看）。
        AUDIT_DROPPED.inc()
        # 不要每个请求刷屏：首条 + 每 100 条告警，便于监控发现丢审计。
        if _dropped_logs == 1 or _dropped_logs % 100 == 0:
            logger.error("audit queue is full, dropped=%s log rows", _dropped_logs)


async def _writer_loop() -> None:
    queue = _get_queue()
    while True:
        event = await queue.get()
        try:
            async with session_scope() as session:
                session.add(
                    OperationLog(
                        tenant_id=event.tenant_id,
                        user_id=event.user_id,
                        username=event.username,
                        status=event.status,
                        message=event.message,
                        method=event.method,
                        path=event.path,
                        node=event.node,
                        title=event.title,
                        trace_id=event.trace_id,
                        action_id=event.action_id,
                        session_id=event.session_id,
                        ip=event.ip,
                        ua=event.ua,
                        params=event.params,
                        result=event.result,
                        status_code=event.status_code,
                        cost=event.cost,
                        create_time=event.create_time,
                        hash_version=HASH_VERSION,
                    )
                )
        except Exception:
            logger.exception("failed to write operation log")
        finally:
            queue.task_done()


def _canonical(log: OperationLog) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for name in HASH_FIELDS:
        value = getattr(log, name, None)
        if name in {"tenant_id", "user_id", "status", "status_code", "cost"}:
            result[name] = int(value) if value is not None else 0
        elif name == "create_time":
            result[name] = fmt(value) if isinstance(value, datetime) else str(value or "")
        else:
            result[name] = "" if value is None else str(value)
    return result


def compute_row_hash(log: OperationLog, prev_hash: str) -> str:
    payload = json.dumps(_canonical(log), ensure_ascii=False, separators=(",", ":"))
    return hashlib.sha256((prev_hash + "\n" + payload).encode("utf-8")).hexdigest()


def _signature(head_hash: str) -> str:
    key = get_settings().audit_signing_key.encode("utf-8")
    return hmac.new(key, head_hash.encode("utf-8"), hashlib.sha256).hexdigest()


async def seal_pending(batch: int = 200) -> int:
    """Seal unchained rows.  Only this function writes hash fields and seals."""
    async with session_scope() as session:
        last = await session.scalar(select(AuditSeal).order_by(AuditSeal.id.desc()).limit(1))
        prev_hash = last.head_hash if last else ""
        rows = list(
            (
                await session.scalars(
                    select(OperationLog)
                    .where(OperationLog.row_hash.is_(None))
                    .order_by(OperationLog.id.asc())
                    .limit(batch)
                    .with_for_update(skip_locked=True)
                )
            ).all()
        )
        if not rows:
            return 0
        for row in rows:
            row.prev_hash = prev_hash
            row.row_hash = compute_row_hash(row, prev_hash)
            row.hash_version = HASH_VERSION
            prev_hash = row.row_hash
        session.add(
            AuditSeal(
                from_log_id=rows[0].id,
                to_log_id=rows[-1].id,
                head_hash=prev_hash,
                prev_hash=rows[0].prev_hash or "",
                signature=_signature(prev_hash),
                key_id="v1",
            )
        )
        return len(rows)


async def verify_chain(*, from_id: int | None = None, batch_size: int = 1000) -> dict[str, Any]:
    """Verify by keyset pagination so the whole log table never enters memory."""
    async with session_scope() as session:
        prev_hash = ""
        last_id = 0
        if from_id is not None:
            # Partial verification must start from the previous sealed hash,
            # otherwise every row would look "broken".
            previous = await session.scalar(
                select(OperationLog.row_hash)
                .where(OperationLog.id < from_id, OperationLog.row_hash.is_not(None))
                .order_by(OperationLog.id.desc())
                .limit(1)
            )
            prev_hash = previous or ""
            last_id = from_id - 1

        total = 0
        unchained = 0
        broken: list[int] = []
        modified: list[int] = []
        limit = max(100, min(5000, batch_size))
        while True:
            rows = list(
                (
                    await session.scalars(
                        select(OperationLog)
                        .where(OperationLog.id > last_id)
                        .order_by(OperationLog.id.asc())
                        .limit(limit)
                    )
                ).all()
            )
            if not rows:
                break
            last_id = rows[-1].id
            total += len(rows)
            for row in rows:
                if not row.row_hash:
                    unchained += 1
                    continue
                expected = compute_row_hash(row, prev_hash)
                if row.prev_hash != prev_hash:
                    broken.append(row.id)
                if expected != row.row_hash:
                    modified.append(row.id)
                prev_hash = row.row_hash

        last_seal = await session.scalar(select(AuditSeal).order_by(AuditSeal.id.desc()).limit(1))
        # A partial scan cannot judge whether the tail matches the latest seal.
        tail_stale = bool(from_id is None and last_seal and last_seal.head_hash != prev_hash)
        seal_ok = True
        if last_seal:
            seal_ok = hmac.compare_digest(last_seal.signature, _signature(last_seal.head_hash))
        return {
            "total": total,
            "unchained": unchained,
            "broken": broken,
            "modified": modified,
            "tail_stale": tail_stale,
            "seal_ok": seal_ok,
            "ok": not broken and not modified and not tail_stale and seal_ok,
        }


async def unsealed_count() -> int:
    async with session_scope() as session:
        value = await session.scalar(select(func.count()).select_from(OperationLog).where(OperationLog.row_hash.is_(None)))
        return int(value or 0)
