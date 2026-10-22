from __future__ import annotations

import logging
from typing import Any

from sqlalchemy import delete, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import ApiError, Forbidden, ValidationFailed
from cccms.core.scope import apply_scope
from cccms.core.time import now
from cccms.models.crontab import Crontab
from cccms.models.dict import Category, DictData, DictType
from cccms.models.file import FileRecord
from cccms.models.notice import Notice
from cccms.models.rule import DataRule
from cccms.models.user import Dept, Menu, Post, Role, RoleNode, User, UserRole
from cccms.services.file import purge_blob, unquarantine_file

SUPER_ADMIN_CODE = "super_admin"

logger = logging.getLogger("cccms.recycle")

MODELS: dict[str, Any] = {
    "user": User,
    "role": Role,
    "dept": Dept,
    "post": Post,
    "dict": DictType,
    "dict_type": DictType,
    "dict_data": DictData,
    "category": Category,
    "menu": Menu,
    "file": FileRecord,
    "notice": Notice,
    "crontab": Crontab,
    "data_rule": DataRule,
}


def _model(kind: str) -> Any:
    model = MODELS.get(kind)
    if model is None:
        raise ValidationFailed("不支持的回收站类型")
    return model


def _deleted_row(model: Any, item_id: int, actor: UserContext) -> Any:
    statement = select(model).where(model.id == item_id, model.delete_time.is_not(None))
    if getattr(model, "__data_scope__", None) is not None:
        # 声明了数据权限的模型，回收站同样只能看到自己范围内的行。
        statement = apply_scope(statement, model, include_deleted=True)
    elif hasattr(model, "tenant_id"):
        statement = statement.where(model.tenant_id == actor.tenant_id)
    return statement


async def _guard_super_admin(session: AsyncSession, model: Any, row: Any, actor: UserContext) -> None:
    """回收站不能成为绕过删除保护的后门。"""
    if actor.is_super_admin:
        return
    if model is Menu:
        raise Forbidden("仅超级管理员可维护平台菜单")
    if model is Role and row.code == SUPER_ADMIN_CODE:
        raise Forbidden("不能操作超级管理员角色")
    if model is User:
        assigned = await session.scalar(
            select(Role.id)
            .join(UserRole, UserRole.role_id == Role.id)
            .where(UserRole.user_id == row.id, Role.code == SUPER_ADMIN_CODE)
        )
        if assigned is not None:
            raise Forbidden("不能操作超级管理员账号")


async def restore(
    session: AsyncSession, actor: UserContext, kind: str, ids: list[int]
) -> tuple[int, list[int]]:
    model = _model(kind)
    affected = 0
    skipped: list[int] = []
    restored_files: list[str] = []
    for item_id in dict.fromkeys(ids):
        row = await session.scalar(_deleted_row(model, item_id, actor))
        if row is None:
            skipped.append(item_id)
            continue
        await _guard_super_admin(session, model, row, actor)
        try:
            async with session.begin_nested():
                row.delete_time = None
                if hasattr(row, "update_time"):
                    row.update_time = now()
                await session.flush()
        except IntegrityError:
            skipped.append(item_id)
            continue
        if model is FileRecord:
            restored_files.append(row.path)
        affected += 1
    await session.commit()
    for relative in restored_files:
        try:
            await unquarantine_file(relative)
        except (OSError, ApiError):
            # 数据库已恢复记录, 文件回迁失败需人工介入, 不能静默吞掉。
            logger.warning("恢复附件文件失败: %s", relative, exc_info=True)
    return affected, skipped


async def purge(
    session: AsyncSession, actor: UserContext, kind: str, ids: list[int]
) -> tuple[int, list[int]]:
    model = _model(kind)
    affected = 0
    skipped: list[int] = []
    purged_files: list[str] = []
    for item_id in dict.fromkeys(ids):
        row = await session.scalar(_deleted_row(model, item_id, actor))
        if row is None:
            skipped.append(item_id)
            continue
        await _guard_super_admin(session, model, row, actor)
        try:
            async with session.begin_nested():
                if model is Menu and row.node:
                    await session.execute(delete(RoleNode).where(RoleNode.node == row.node))
                if model is FileRecord:
                    purged_files.append(row.path)
                await session.delete(row)
                await session.flush()
        except IntegrityError:
            skipped.append(item_id)
            continue
        affected += 1
    await session.commit()
    for relative in purged_files:
        try:
            await purge_blob(relative)
        except (OSError, ApiError):
            logger.warning("彻底删除附件文件失败: %s", relative, exc_info=True)
    return affected, skipped
