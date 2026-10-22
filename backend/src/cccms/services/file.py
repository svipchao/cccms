from __future__ import annotations

import hashlib
import math
import mimetypes
import secrets
import shutil
import uuid
from collections.abc import AsyncIterator
from dataclasses import dataclass
from pathlib import Path

import aiofiles
from fastapi import UploadFile
from sqlalchemy import ColumnElement, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.cache import Keys, get_json, set_json
from cccms.core.cache import delete as cache_delete
from cccms.core.codes import BizCode
from cccms.core.context import UserContext
from cccms.core.errors import (
    ApiError,
    Conflict,
    Forbidden,
    NotFound,
    ServiceUnavailable,
    ValidationFailed,
)
from cccms.core.pagination import count_query
from cccms.core.scope import ScopeConfig, apply_field_actions, apply_scope, without_scope
from cccms.core.sorting import apply_sort
from cccms.core.time import now, parse
from cccms.models.dict import Category
from cccms.models.file import FileRecord
from cccms.models.user import User, UserDept
from cccms.schemas.file import FileOut, FileQuery, UploadCompleteRequest, UploadInitOut, UploadInitRequest
from cccms.schemas.system import CategoryNode
from cccms.services import dict_service
from cccms.services.storage import StorageDriver, get_driver, safe_join

IMAGE_EXT = {"jpg", "jpeg", "png", "gif", "webp", "bmp"}
PDF_EXT = {"pdf"}
READ_CHUNK = 1024 * 1024
MAX_CHUNK_INDEX = 100_000


@dataclass(frozen=True)
class Download:
    """一次附件下载：元信息 + 字节流入口。

    不再把本地 ``Path`` 交给 ``FileResponse``：对象存储没有本地路径可给，
    交给驱动出流才能一套代码服务两种存储（docs/16 §9.3 第 2 步）。
    """

    name: str
    mime: str
    size: int
    driver: StorageDriver
    key: str

    def stream(self, *, offset: int = 0, length: int | None = None) -> AsyncIterator[bytes]:
        return self.driver.open(self.key, offset=offset, length=length)


def _dept_scope(model: type[FileRecord], dept_ids: tuple[int, ...]) -> ColumnElement[bool]:
    return model.create_by.in_(select(UserDept.user_id).where(UserDept.dept_id.in_(dept_ids)))


FileRecord.__data_scope__ = ScopeConfig(owner="create_by", dept=_dept_scope)

# 允许排序的列：前端只给"大小"和"上传时间"留了排序入口。
SORTABLE = {
    "id": FileRecord.id,
    "size": FileRecord.size,
    "create_time": FileRecord.create_time,
}


def _safe_name(name: str | None) -> str:
    value = Path((name or "").replace("\\", "/")).name.replace("\x00", "").strip()
    if not value or value in {".", ".."}:
        raise ValidationFailed("文件名不合法")
    return value[:255]


def _ext_of(name: str) -> str:
    return Path(name).suffix.lower().lstrip(".")


def _staging_root() -> Path:
    """分片暂存目录永远是**本机**目录，与存储驱动无关。

    分片只是"上传到哪台机器、就在哪台机器合并"的临时数据，合并完成后成品才交给驱动；
    正因为如此，多节点部署下分片上传需要粘性路由（同一 upload_id 落在同一节点）。
    """
    return get_settings().storage_root / "chunks"


async def quarantine_file(relative: str) -> None:
    if not relative:
        return
    driver = get_driver()
    if not driver.exists(relative):
        return
    await driver.quarantine(relative)


async def unquarantine_file(relative: str) -> None:
    if not relative:
        return
    await get_driver().restore(relative)


async def purge_blob(relative: str) -> None:
    if not relative:
        return
    await get_driver().purge(relative)


def cleanup_chunks(retention_hours: int = 48) -> int:
    """Remove abandoned chunk directories older than ``retention_hours``."""
    root = _staging_root()
    if not root.is_dir():
        return 0
    cutoff = now().timestamp() - max(1, retention_hours) * 3600
    removed = 0
    for directory in root.iterdir():
        if not directory.is_dir():
            continue
        try:
            if directory.stat().st_mtime >= cutoff:
                continue
        except OSError:
            continue
        shutil.rmtree(directory, ignore_errors=True)
        removed += 1
    return removed


def _is_image(ext: str) -> bool:
    return ext.lower() in IMAGE_EXT


def _is_pdf(ext: str) -> bool:
    return ext.lower() in PDF_EXT


def _to_out(row: FileRecord, *, create_by_name: str = "", category_name: str = "") -> FileOut:
    out: FileOut = apply_field_actions(
        FileRecord,
        FileOut(
            id=row.id,
            category_id=row.category_id,
            category_name=category_name,
            storage=row.storage,
            url=row.url,
            original_name=row.original_name,
            ext=row.ext,
            mime=row.mime,
            size=row.size,
            sha1=row.sha1,
            create_by=row.create_by,
            create_by_name=create_by_name,
            is_image=_is_image(row.ext),
            is_pdf=_is_pdf(row.ext),
            create_time=row.create_time,
            update_time=row.update_time,
        ),
    )
    return out


async def _names(session: AsyncSession, ids: set[int]) -> dict[int, str]:
    if not ids:
        return {}
    rows = await session.execute(select(User.id, User.nickname).where(User.id.in_(ids)))
    return {int(user_id): str(nickname or "") for user_id, nickname in rows.all()}


async def _visible_file(session: AsyncSession, actor: UserContext, file_id: int) -> FileRecord:
    row: FileRecord | None = await session.scalar(
        apply_scope(select(FileRecord).where(FileRecord.id == file_id), FileRecord)
    )
    if row is None:
        raise NotFound("附件不存在")
    return row


async def download_file(session: AsyncSession, actor: UserContext, file_id: int) -> Download:
    """返回「当前用户可见」的附件下载句柄，供 ``/api/file/{id}/download`` 直出。

    与 ``/storage/<path>`` 同一套可见性口径：先过 ``apply_scope``，查不到一律 404，
    不区分"不存在"与"无权看"，避免用 id 探测别人的附件。
    """
    row = await _visible_file(session, actor, file_id)
    driver = get_driver()
    if row.storage != driver.name:
        # 记录落在别的驱动上（迁移中常见）：当前实例读不到，按不存在处理而不是 500。
        raise NotFound("附件不存在")
    if not driver.exists(row.path):
        raise NotFound("附件不存在")
    return Download(
        name=row.original_name or "download",
        mime=row.mime or "application/octet-stream",
        size=driver.size(row.path),
        driver=driver,
        key=row.path,
    )


async def list_files(
    session: AsyncSession,
    actor: UserContext,
    params: FileQuery,
) -> tuple[int, list[FileOut]]:
    statement = apply_scope(
        select(FileRecord),
        FileRecord,
        include_deleted=params.trashed == 1,
    )
    if params.trashed == 1:
        statement = statement.where(FileRecord.delete_time.is_not(None))
    if params.keyword or params.original_name:
        value = f"%{(params.original_name or params.keyword or '').strip()}%"
        statement = statement.where(FileRecord.original_name.like(value))
    start = parse(params.start)
    end = parse(params.end)
    if start is not None:
        statement = statement.where(FileRecord.create_time >= start)
    if end is not None:
        statement = statement.where(FileRecord.create_time <= end)
    if params.category_id:
        statement = statement.where(FileRecord.category_id == params.category_id)
    if params.ext:
        statement = statement.where(FileRecord.ext == params.ext.strip().lower())
    total = int(await session.scalar(count_query(statement, FileRecord.id)) or 0)
    rows = list(
        (
            await session.scalars(
                apply_sort(
                    statement,
                    SORTABLE,
                    id_column=FileRecord.id,
                    fallback=(FileRecord.id.desc(),),
                    sort=params.sort,
                    order=params.order,
                )
                .offset((params.page - 1) * params.limit)
                .limit(params.limit)
            )
        ).all()
    )
    names = await _names(session, {row.create_by for row in rows})
    category_ids = {row.category_id for row in rows if row.category_id}
    categories = (
        dict(
            (
                await session.execute(select(Category.id, Category.name).where(Category.id.in_(category_ids)))
            ).all()
        )
        if category_ids
        else {}
    )
    return total, [
        _to_out(
            row,
            create_by_name=names.get(row.create_by, ""),
            category_name=str(categories.get(row.category_id or 0, "")),
        )
        for row in rows
    ]


async def _assert_category(session: AsyncSession, actor: UserContext, category_id: int | None) -> None:
    if category_id is None:
        return
    exists = await session.scalar(
        select(Category.id).where(
            Category.id == category_id,
            Category.tenant_id == actor.tenant_id,
            Category.module == "file",
            Category.delete_time.is_(None),
        )
    )
    if not exists:
        raise Conflict("附件分类不存在")


async def _dedupe(
    session: AsyncSession,
    actor: UserContext,
    *,
    sha1: str,
    size: int,
) -> FileRecord | None:
    """秒传去重是租户级能力，不能被人均「仅本人」的数据范围收窄。

    命中他人上传的同一份文件只复用存储，不透露其记录内容；权限仍由后续
    下载/列表接口的 ``apply_scope`` 控制。
    """
    with without_scope("file dedupe"):
        return await session.scalar(
            select(FileRecord)
            .where(
                FileRecord.tenant_id == actor.tenant_id,
                FileRecord.sha1 == sha1,
                FileRecord.size == size,
                FileRecord.delete_time.is_(None),
            )
            .order_by(FileRecord.id.asc())
            .limit(1)
        )


async def _create_record(
    session: AsyncSession,
    actor: UserContext,
    *,
    original_name: str,
    relative: str,
    ext: str,
    mime: str,
    size: int,
    sha1: str,
    sha256: str,
    category_id: int | None,
) -> FileRecord:
    driver = get_driver()
    row = FileRecord(
        tenant_id=actor.tenant_id,
        category_id=category_id,
        storage=driver.name,
        path=relative,
        url=driver.url(relative),
        original_name=original_name,
        ext=ext,
        mime=mime,
        size=size,
        sha1=sha1,
        sha256=sha256,
        create_by=actor.id,
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return row


async def upload_file(
    session: AsyncSession,
    actor: UserContext,
    upload: UploadFile,
    *,
    category_id: int | None = None,
) -> FileOut:
    settings = get_settings()
    original = _safe_name(upload.filename)
    ext = _ext_of(original)
    if ext not in settings.upload_ext_set:
        raise ValidationFailed(f"不支持的文件类型：{ext or '未知'}")
    await _assert_category(session, actor, category_id)

    max_bytes = settings.upload_max_mb * 1024 * 1024
    current = now()
    relative_dir = current.strftime("%Y/%m/%d")
    stored = f"{current.strftime('%H%M%S')}{secrets.token_hex(8)}"
    if ext:
        stored = f"{stored}.{ext}"
    relative = f"{relative_dir}/{stored}"

    # 哈希与大小上限留在服务层，驱动只负责搬运字节：换成 S3 时这两条校验不会漏掉。
    driver = get_driver()
    sha1 = hashlib.sha1()  # noqa: S324 - 仅用于秒传去重与完整性校验, 非密码学用途
    sha256 = hashlib.sha256()
    total = 0

    async def _body() -> AsyncIterator[bytes]:
        nonlocal total
        while True:
            chunk = await upload.read(READ_CHUNK)
            if not chunk:
                return
            total += len(chunk)
            if total > max_bytes:
                raise ValidationFailed(
                    f"文件超过上限 {settings.upload_max_mb}MB",
                    code=BizCode.PAYLOAD_TOO_LARGE,
                    message_key="error.payload_too_large",
                )
            sha1.update(chunk)
            sha256.update(chunk)
            yield chunk

    try:
        await driver.save(relative, _body())
        if total == 0:
            raise ValidationFailed("不能上传空文件")
    except Exception:
        await driver.delete(relative)
        raise

    digest = sha1.hexdigest()
    existing = await _dedupe(session, actor, sha1=digest, size=total)
    if existing is not None:
        await driver.delete(relative)
        names = await _names(session, {existing.create_by})
        return _to_out(existing, create_by_name=names.get(existing.create_by, ""))

    row = await _create_record(
        session,
        actor,
        original_name=original,
        relative=relative,
        ext=ext,
        # 以扩展名推导线类型为准，客户端上报值仅作兜底（防止伪造 text/html）。
        mime=mimetypes.guess_type(original)[0] or upload.content_type or "application/octet-stream",
        size=total,
        sha1=digest,
        sha256=sha256.hexdigest(),
        category_id=category_id,
    )
    return _to_out(row, create_by_name=actor.nickname)


async def delete_files(session: AsyncSession, actor: UserContext, ids: list[int]) -> tuple[int, list[int]]:
    affected = 0
    skipped: list[int] = []
    removed: list[tuple[int, str]] = []
    for file_id in dict.fromkeys(ids):
        row = await session.scalar(
            apply_scope(select(FileRecord).where(FileRecord.id == file_id), FileRecord)
        )
        if row is None:
            skipped.append(file_id)
            continue
        row.delete_time = now()
        row.update_time = now()
        removed.append((row.id, row.path))
        affected += 1
    await session.commit()
    failed: list[int] = []
    for file_id, relative in removed:
        try:
            await quarantine_file(relative)
        except (OSError, ApiError):
            # 驱动可能抛 ServiceUnavailable（对象存储不可用）或 OSError（本地盘权限）。
            failed.append(file_id)
    if failed:
        # 未能移入隔离区的文件仍然公开可下载，必须把数据库删除回滚。
        await session.execute(
            update(FileRecord)
            .where(FileRecord.id.in_(failed))
            .values(delete_time=None, update_time=now())
        )
        await session.commit()
        raise ServiceUnavailable("附件文件隔离失败，请检查存储目录权限后重试")
    return affected, skipped


async def move_files(
    session: AsyncSession,
    actor: UserContext,
    ids: list[int],
    category_id: int | None,
) -> tuple[int, list[int]]:
    await _assert_category(session, actor, category_id)
    affected = 0
    skipped: list[int] = []
    for file_id in dict.fromkeys(ids):
        row = await session.scalar(
            apply_scope(select(FileRecord).where(FileRecord.id == file_id), FileRecord)
        )
        if row is None:
            skipped.append(file_id)
            continue
        row.category_id = category_id
        row.update_time = now()
        affected += 1
    await session.commit()
    return affected, skipped


# ---- 分类 ----------------------------------------------------------------


async def category_tree(
    session: AsyncSession, actor: UserContext, *, trashed: bool = False
) -> list[CategoryNode]:
    return await dict_service.category_tree(session, actor, "file", trashed=trashed)


async def delete_category(session: AsyncSession, actor: UserContext, category_id: int) -> None:
    category = await session.scalar(
        select(Category.id).where(
            Category.id == category_id,
            Category.tenant_id == actor.tenant_id,
            Category.module == "file",
            Category.delete_time.is_(None),
        )
    )
    if category is None:
        raise NotFound("分类不存在")
    child = await session.scalar(
        select(Category.id)
        .where(
            Category.parent_id == category_id,
            Category.tenant_id == actor.tenant_id,
            Category.delete_time.is_(None),
        )
        .limit(1)
    )
    if child:
        raise Conflict("请先删除子分类")
    used = await session.scalar(
        select(FileRecord.id)
        .where(
            FileRecord.category_id == category_id,
            FileRecord.tenant_id == actor.tenant_id,
            FileRecord.delete_time.is_(None),
        )
        .limit(1)
    )
    if used:
        raise Conflict("分类下仍有附件")
    await dict_service.delete_category(session, actor, category_id, module="file")


# ---- 分片上传 ------------------------------------------------------------

CHUNK_META_TTL = 86400


def _chunk_dir(upload_id: str) -> Path:
    # upload_id 是客户端回传的，必须防穿越（与 blob 路径同一套 safe_join）。
    return safe_join(_staging_root(), upload_id)


def _chunk_key(upload_id: str) -> str:
    return f"{Keys.UPLOAD}chunk:{upload_id}"


def _meta_int(meta: dict[str, object], key: str, default: int = 0) -> int:
    """从缓存元数据安全读取整数. 类型不符或无法解析时回退默认值."""
    raw = meta.get(key, default)
    if isinstance(raw, bool) or not isinstance(raw, (int, str)):
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _chunk_total(declared_size: int, chunk_size: int) -> int:
    """按声明大小计算分片总数. 大小未知或非法时至少允许 1 片."""
    if declared_size <= 0 or chunk_size <= 0:
        return 1
    return max(1, math.ceil(declared_size / chunk_size))


async def init_upload(session: AsyncSession, actor: UserContext, payload: UploadInitRequest) -> UploadInitOut:
    settings = get_settings()
    original = _safe_name(payload.filename)
    ext = _ext_of(original)
    if ext not in settings.upload_ext_set:
        raise ValidationFailed(f"不支持的文件类型：{ext or '未知'}")
    if payload.size > settings.upload_chunk_max_mb * 1024 * 1024:
        raise ValidationFailed(
            f"文件超过上限 {settings.upload_chunk_max_mb}MB",
            code=BizCode.PAYLOAD_TOO_LARGE,
            message_key="error.payload_too_large",
        )
    await _assert_category(session, actor, payload.category_id)

    if payload.sha1:
        existing = await _dedupe(session, actor, sha1=payload.sha1.lower(), size=payload.size)
        if existing is not None:
            names = await _names(session, {existing.create_by})
            return UploadInitOut(
                upload_id="",
                instant=True,
                received=[],
                file=_to_out(existing, create_by_name=names.get(existing.create_by, "")),
            )

    upload_id = uuid.uuid4().hex
    chunk_size = settings.upload_chunk_size
    total_chunks = _chunk_total(payload.size, chunk_size)
    directory = _chunk_dir(upload_id)
    directory.mkdir(parents=True, exist_ok=True)
    await set_json(
        _chunk_key(upload_id),
        {
            "user_id": actor.id,
            "tenant_id": actor.tenant_id,
            "filename": original,
            "size": payload.size,
            "sha1": payload.sha1.lower() if payload.sha1 else "",
            "category_id": payload.category_id,
            "created_at": now().strftime("%Y-%m-%d %H:%M:%S"),
        },
        ttl=CHUNK_META_TTL,
    )
    received = sorted(int(path.stem) for path in directory.glob("*.part") if path.stem.isdigit())
    return UploadInitOut(
        upload_id=upload_id,
        instant=False,
        received=received,
        chunk_size=chunk_size,
        total_chunks=total_chunks,
    )


async def _chunk_meta(actor: UserContext, upload_id: str) -> dict[str, object]:
    meta = await get_json(_chunk_key(upload_id))
    if not isinstance(meta, dict):
        raise NotFound("上传会话不存在或已过期")
    if _meta_int(meta, "user_id") != actor.id or _meta_int(meta, "tenant_id") != actor.tenant_id:
        raise Forbidden("上传会话不属于当前用户")
    return meta


async def save_chunk(
    actor: UserContext,
    upload_id: str,
    index: int,
    upload: UploadFile,
) -> int:
    if index < 0 or index > MAX_CHUNK_INDEX:
        raise ValidationFailed("分片序号不合法")
    meta = await _chunk_meta(actor, upload_id)
    settings = get_settings()
    declared_size = _meta_int(meta, "size")
    total_chunks = _chunk_total(declared_size, settings.upload_chunk_size)
    if index >= total_chunks:
        raise ValidationFailed("分片序号超出文件声明的分片总数")
    directory = _chunk_dir(upload_id)
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / f"{index}.part"
    limit = settings.upload_chunk_size + 1024 * 1024
    total = 0
    async with aiofiles.open(target, "wb") as handle:
        while True:
            chunk = await upload.read(READ_CHUNK)
            if not chunk:
                break
            total += len(chunk)
            if total > limit:
                target.unlink(missing_ok=True)
                raise ValidationFailed("单个分片过大")
            await handle.write(chunk)
    if total == 0:
        target.unlink(missing_ok=True)
        raise ValidationFailed("分片内容为空")
    return total


async def complete_upload(
    session: AsyncSession,
    actor: UserContext,
    payload: UploadCompleteRequest,
) -> FileOut:
    meta = await _chunk_meta(actor, payload.upload_id)
    directory = _chunk_dir(payload.upload_id)
    if not directory.exists():
        raise NotFound("分片目录不存在")
    parts = sorted(
        ((int(path.stem), path) for path in directory.glob("*.part") if path.stem.isdigit()),
        key=lambda item: item[0],
    )
    if not parts:
        raise ValidationFailed("没有已上传的分片")
    indexes = [index for index, _ in parts]
    if indexes != list(range(len(indexes))):
        missing = sorted(set(range(max(indexes) + 1)) - set(indexes))
        raise ValidationFailed(f"缺少分片：{','.join(str(item) for item in missing[:20])}")

    declared_size = _meta_int(meta, "size")
    total = sum(path.stat().st_size for _, path in parts)
    if declared_size and total != declared_size:
        shutil.rmtree(directory, ignore_errors=True)
        raise ValidationFailed("合并后大小与声明不一致，请重新上传")

    declared_sha1 = str(meta.get("sha1") or "")
    if payload.sha1:
        declared_sha1 = payload.sha1.lower()

    original = str(meta.get("filename") or "file")
    ext = _ext_of(original)
    current = now()
    relative_dir = current.strftime("%Y/%m/%d")
    stored = f"{current.strftime('%H%M%S')}{secrets.token_hex(8)}"
    if ext:
        stored = f"{stored}.{ext}"
    relative = f"{relative_dir}/{stored}"

    # 分片目录是本机暂存，合并后的成品交给驱动；哈希校验同样留在服务层。
    driver = get_driver()
    sha1 = hashlib.sha1()  # noqa: S324 - 仅用于秒传去重与完整性校验, 非密码学用途
    sha256 = hashlib.sha256()

    async def _body() -> AsyncIterator[bytes]:
        for _, path in parts:
            async with aiofiles.open(path, "rb") as source:
                while True:
                    chunk = await source.read(READ_CHUNK)
                    if not chunk:
                        break
                    sha1.update(chunk)
                    sha256.update(chunk)
                    yield chunk

    try:
        await driver.save(relative, _body())
        digest = sha1.hexdigest()
        if declared_sha1 and declared_sha1 != digest:
            raise ValidationFailed("文件哈希校验失败，请重新上传")
    except Exception:
        await driver.delete(relative)
        raise
    finally:
        shutil.rmtree(directory, ignore_errors=True)
        await cache_delete(_chunk_key(payload.upload_id))

    category_id = _meta_int(meta, "category_id") if meta.get("category_id") is not None else None
    row = await _create_record(
        session,
        actor,
        original_name=original,
        relative=relative,
        ext=ext,
        mime=mimetypes.guess_type(original)[0] or "application/octet-stream",
        size=total,
        sha1=digest,
        sha256=sha256.hexdigest(),
        category_id=category_id,
    )
    return _to_out(row, create_by_name=actor.nickname)
