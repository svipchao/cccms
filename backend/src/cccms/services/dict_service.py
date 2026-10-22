from __future__ import annotations

from collections import defaultdict
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.cache import Keys, get_json, set_json, version
from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound
from cccms.core.pagination import count_query
from cccms.core.time import now
from cccms.models.dict import Category, DictData, DictType
from cccms.schemas.system import (
    CategoryNode,
    CategorySave,
    DictDataOut,
    DictDataSave,
    DictTypeOut,
    DictTypeSave,
)

DICT_CACHE_TTL = 300


def _category_node(row: Category, children: list[CategoryNode] | None = None) -> CategoryNode:
    return CategoryNode(
        id=row.id,
        module=row.module,
        parent_id=row.parent_id,
        name=row.name,
        sort=row.sort,
        status=row.status,
        children=children or [],
    )


async def category_tree(
    session: AsyncSession,
    actor: UserContext,
    module: str,
    *,
    trashed: bool = False,
) -> list[CategoryNode]:
    statement = select(Category).where(
        Category.tenant_id == actor.tenant_id,
        Category.module == module,
    )
    statement = statement.where(
        Category.delete_time.is_not(None) if trashed else Category.delete_time.is_(None)
    )
    rows = list((await session.scalars(statement.order_by(Category.sort.asc(), Category.id.asc()))).all())
    by_parent: dict[int | None, list[Category]] = defaultdict(list)
    for row in rows:
        by_parent[row.parent_id].append(row)

    def walk(parent_id: int | None) -> list[CategoryNode]:
        return [_category_node(row, walk(row.id)) for row in by_parent.get(parent_id, [])]

    return walk(None)


async def save_category(
    session: AsyncSession, actor: UserContext, payload: CategorySave, category_id: int | None = None
) -> CategoryNode:
    if payload.parent_id:
        parent = await session.scalar(
            select(Category).where(
                Category.id == payload.parent_id,
                Category.tenant_id == actor.tenant_id,
                Category.delete_time.is_(None),
            )
        )
        if parent is None:
            raise Conflict("上级分类不存在")
        if parent.module != payload.module:
            raise Conflict("上级分类不属于当前模块")
        if category_id and payload.parent_id == category_id:
            raise Conflict("上级分类不能是自己")
        if category_id:
            # 沿 parent 链回溯，禁止把分类挂到自己的子孙下面（会形成环并让整段子树消失）。
            current: Category | None = parent
            seen: set[int] = set()
            while current is not None and current.id not in seen:
                if current.id == category_id:
                    raise Conflict("上级分类不能是自己的子分类")
                seen.add(current.id)
                current = (
                    await session.scalar(
                        select(Category).where(
                            Category.id == current.parent_id,
                            Category.tenant_id == actor.tenant_id,
                            Category.delete_time.is_(None),
                        )
                    )
                    if current.parent_id
                    else None
                )
    if category_id:
        row = await session.scalar(
            select(Category).where(
                Category.id == category_id,
                Category.tenant_id == actor.tenant_id,
                Category.delete_time.is_(None),
            )
        )
        if row is None:
            raise NotFound("分类不存在")
        if row.module != payload.module:
            raise Conflict("分类所属模块不允许修改")
        for key, value in payload.model_dump().items():
            setattr(row, key, value)
        row.update_time = now()
    else:
        row = Category(tenant_id=actor.tenant_id, **payload.model_dump())
        session.add(row)
    await session.commit()
    await session.refresh(row)
    return _category_node(row)


async def delete_category(
    session: AsyncSession,
    actor: UserContext,
    category_id: int,
    *,
    module: str | None = None,
) -> None:
    row = await session.scalar(
        select(Category).where(
            Category.id == category_id,
            Category.tenant_id == actor.tenant_id,
            Category.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("分类不存在")
    if module is not None and row.module != module:
        raise NotFound("分类不存在")
    child = await session.scalar(
        select(Category.id).where(Category.parent_id == category_id, Category.delete_time.is_(None)).limit(1)
    )
    if child:
        raise Conflict("请先删除子分类")
    used = await session.scalar(select(DictType.id).where(DictType.category_id == category_id).limit(1))
    if used:
        raise Conflict("分类下仍有字典类型")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def list_types(
    session: AsyncSession,
    actor: UserContext,
    *,
    keyword: str | None = None,
    name: str | None = None,
    type: str | None = None,
    category_id: int | None = None,
    trashed: int = 0,
    page: int = 1,
    limit: int = 15,
) -> tuple[int, list[DictTypeOut]]:
    statement = select(DictType).where(DictType.tenant_id == actor.tenant_id)
    statement = statement.where(
        DictType.delete_time.is_not(None) if trashed else DictType.delete_time.is_(None)
    )
    if keyword:
        value = f"%{keyword.strip()}%"
        statement = statement.where(DictType.name.like(value) | DictType.type.like(value))
    if name:
        statement = statement.where(DictType.name.like(f"%{name.strip()}%"))
    if type:
        statement = statement.where(DictType.type.like(f"%{type.strip()}%"))
    if category_id:
        statement = statement.where(DictType.category_id == category_id)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(DictType.id.asc()).offset((page - 1) * limit).limit(limit)
            )
        ).all()
    )
    return total, [
        DictTypeOut(
            id=row.id,
            category_id=row.category_id,
            type=row.type,
            name=row.name,
            status=row.status,
            remark=row.remark,
        )
        for row in rows
    ]


async def save_type(
    session: AsyncSession, actor: UserContext, payload: DictTypeSave, type_id: int | None = None
) -> DictTypeOut:
    exists = await session.scalar(
        select(DictType.id).where(
            DictType.tenant_id == actor.tenant_id,
            DictType.type == payload.type,
            DictType.delete_time.is_(None),
            DictType.id != (type_id or 0),
        )
    )
    if exists:
        raise Conflict("字典类型已存在")
    if type_id:
        row = await session.scalar(
            select(DictType).where(
                DictType.id == type_id,
                DictType.tenant_id == actor.tenant_id,
                DictType.delete_time.is_(None),
            )
        )
        if row is None:
            raise NotFound("字典类型不存在")
        for key, value in payload.model_dump().items():
            setattr(row, key, value)
        row.update_time = now()
    else:
        row = DictType(tenant_id=actor.tenant_id, **payload.model_dump())
        session.add(row)
    await session.commit()
    await session.refresh(row)
    return DictTypeOut(
        id=row.id,
        category_id=row.category_id,
        type=row.type,
        name=row.name,
        status=row.status,
        remark=row.remark,
    )


async def delete_type(session: AsyncSession, actor: UserContext, type_id: int) -> None:
    row = await session.scalar(
        select(DictType).where(
            DictType.id == type_id,
            DictType.tenant_id == actor.tenant_id,
            DictType.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("字典类型不存在")
    row.delete_time = now()
    row.update_time = now()
    data_rows = list(
        (
            await session.scalars(
                select(DictData).where(DictData.type_id == type_id, DictData.delete_time.is_(None))
            )
        ).all()
    )
    for item in data_rows:
        item.delete_time = now()
        item.update_time = now()
    await session.commit()


async def list_data(
    session: AsyncSession,
    actor: UserContext,
    type_id: int,
    *,
    trashed: bool = False,
) -> list[DictDataOut]:
    # 下拉框是最热的一类接口：按 dt_version + 租户 + 类型缓存，字典写操作会 bump 版本。
    if not trashed:
        cache_key = Keys.dict_data(await version(Keys.DICT_VERSION), actor.tenant_id, type_id)
        cached = await get_json(cache_key)
        if isinstance(cached, list):
            return [DictDataOut.model_validate(item) for item in cached]

    statement = select(DictData).where(
        DictData.tenant_id == actor.tenant_id,
        DictData.type_id == type_id,
    )
    statement = statement.where(
        DictData.delete_time.is_not(None) if trashed else DictData.delete_time.is_(None)
    )
    rows = list((await session.scalars(statement.order_by(DictData.sort.asc(), DictData.id.asc()))).all())
    result = [
        DictDataOut(
            id=row.id,
            type_id=row.type_id,
            value=row.value,
            label=row.label,
            tag=row.tag,
            sort=row.sort,
            status=row.status,
            remark=row.remark,
        )
        for row in rows
    ]
    if not trashed:
        await set_json(cache_key, [item.model_dump(mode="json") for item in result], ttl=DICT_CACHE_TTL)
    return result


async def save_data(
    session: AsyncSession, actor: UserContext, payload: DictDataSave, data_id: int | None = None
) -> DictDataOut:
    parent_type = await session.scalar(
        select(DictType.id).where(
            DictType.id == payload.type_id,
            DictType.tenant_id == actor.tenant_id,
            DictType.delete_time.is_(None),
        )
    )
    if parent_type is None:
        raise NotFound("字典类型不存在")
    exists = await session.scalar(
        select(DictData.id).where(
            DictData.tenant_id == actor.tenant_id,
            DictData.type_id == payload.type_id,
            DictData.value == payload.value,
            DictData.delete_time.is_(None),
            DictData.id != (data_id or 0),
        )
    )
    if exists:
        raise Conflict("字典值已存在")
    if data_id:
        row = await session.scalar(
            select(DictData).where(
                DictData.id == data_id,
                DictData.tenant_id == actor.tenant_id,
                DictData.delete_time.is_(None),
            )
        )
        if row is None:
            raise NotFound("字典数据不存在")
        for key, value in payload.model_dump().items():
            setattr(row, key, value)
        row.update_time = now()
    else:
        row = DictData(tenant_id=actor.tenant_id, **payload.model_dump())
        session.add(row)
    await session.commit()
    await session.refresh(row)
    return DictDataOut(
        id=row.id,
        type_id=row.type_id,
        value=row.value,
        label=row.label,
        tag=row.tag,
        sort=row.sort,
        status=row.status,
        remark=row.remark,
    )


async def delete_data(session: AsyncSession, actor: UserContext, data_id: int) -> None:
    row = await session.scalar(
        select(DictData).where(
            DictData.id == data_id,
            DictData.tenant_id == actor.tenant_id,
            DictData.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("字典数据不存在")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def batch_status(
    session: AsyncSession,
    actor: UserContext,
    ids: list[int],
    status: int,
    *,
    data: bool = False,
) -> tuple[int, list[int]]:
    # 两个模型结构一致, 动态选择时按 Any 处理, 避免联合类型扩散到行对象。
    model: type[Any] = DictData if data else DictType
    affected = 0
    skipped: list[int] = []
    for item_id in dict.fromkeys(ids):
        row = await session.scalar(
            select(model).where(
                model.id == item_id,
                model.tenant_id == actor.tenant_id,
                model.delete_time.is_(None),
            )
        )
        if row is None:
            skipped.append(item_id)
            continue
        row.status = status
        row.update_time = now()
        affected += 1
    await session.commit()
    return affected, skipped


async def batch_delete(
    session: AsyncSession,
    actor: UserContext,
    ids: list[int],
    *,
    data: bool = False,
) -> tuple[int, list[int]]:
    # 两个模型结构一致, 动态选择时按 Any 处理, 避免联合类型扩散到行对象。
    model: type[Any] = DictData if data else DictType
    affected = 0
    skipped: list[int] = []
    for item_id in dict.fromkeys(ids):
        row = await session.scalar(
            select(model).where(
                model.id == item_id,
                model.tenant_id == actor.tenant_id,
                model.delete_time.is_(None),
            )
        )
        if row is None:
            skipped.append(item_id)
            continue
        row.delete_time = now()
        row.update_time = now()
        if not data:
            children = list(
                (
                    await session.scalars(
                        select(DictData).where(
                            DictData.type_id == item_id,
                            DictData.delete_time.is_(None),
                        )
                    )
                ).all()
            )
            for child in children:
                child.delete_time = now()
                child.update_time = now()
        affected += 1
    await session.commit()
    return affected, skipped
