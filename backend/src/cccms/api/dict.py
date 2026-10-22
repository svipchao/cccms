from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageLimit, PageNum, PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest, IdsRequest
from cccms.schemas.system import (
    CategoryNode,
    CategorySave,
    DictDataOut,
    DictDataSave,
    DictTypeOut,
    DictTypeSave,
)
from cccms.schemas.user import BatchStatusRequest
from cccms.services import dict_service

router = make_router(prefix="/dict", tags=["dict"])


@router.get("/category", response_model=ApiResponse[list[CategoryNode]])
@permission("cccms:dict:category", "字典分类树")
async def category_tree(
    module: str = "dict",
    trashed: int = 0,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dict_service.category_tree(session, user, module, trashed=bool(trashed)))


@router.post("/category/save", response_model=ApiResponse[CategoryNode])
@permission("cccms:dict:category_save", "新增字典分类", methods=["POST"])
async def category_save(
    payload: CategorySave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    payload = payload.model_copy(update={"module": "dict"})
    return ok(await dict_service.save_category(session, user, payload))


@router.post("/category/update", response_model=ApiResponse[CategoryNode])
@permission("cccms:dict:category_update", "修改字典分类", methods=["POST"])
async def category_update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    category_id = as_int(payload.pop("id"))
    payload["module"] = "dict"
    return ok(await dict_service.save_category(session, user, CategorySave.model_validate(payload), category_id))


@router.post("/category/delete", response_model=ApiResponse[None])
@permission("cccms:dict:category_delete", "删除字典分类", methods=["POST"])
async def category_delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await dict_service.delete_category(session, user, payload.id, module="dict")
    return ok(None)


@router.get("", response_model=ApiResponse[PageResult[DictTypeOut]])
@permission("cccms:dict:index", "字典类型列表")
async def index(
    keyword: str | None = None,
    name: str | None = None,
    type: str | None = None,
    category_id: int | None = None,
    trashed: int = 0,
    page: PageNum = 1,
    limit: PageLimit = 15,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await dict_service.list_types(
        session,
        user,
        keyword=keyword,
        name=name,
        type=type,
        category_id=category_id,
        trashed=trashed,
        page=page,
        limit=limit,
    )
    return ok(page_result(total, rows))


@router.post("/save", response_model=ApiResponse[DictTypeOut])
@permission("cccms:dict:save", "新增字典类型", methods=["POST"])
async def save(
    payload: DictTypeSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dict_service.save_type(session, user, payload))


@router.post("/update", response_model=ApiResponse[DictTypeOut])
@permission("cccms:dict:update", "修改字典类型", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    type_id = as_int(payload.pop("id"))
    return ok(await dict_service.save_type(session, user, DictTypeSave.model_validate(payload), type_id))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:dict:delete", "删除字典类型", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await dict_service.delete_type(session, user, payload.id)
    return ok(None)


@router.get("/data", response_model=ApiResponse[list[DictDataOut]])
@permission("cccms:dict:data", "字典数据")
async def data(
    type_id: int,
    trashed: int = 0,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dict_service.list_data(session, user, type_id, trashed=bool(trashed)))


@router.post("/saveData", response_model=ApiResponse[DictDataOut])
@permission("cccms:dict:save_data", "新增字典数据", methods=["POST"])
async def save_data(
    payload: DictDataSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dict_service.save_data(session, user, payload))


@router.post("/updateData", response_model=ApiResponse[DictDataOut])
@permission("cccms:dict:update_data", "修改字典数据", methods=["POST"])
async def update_data(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    data_id = as_int(payload.pop("id"))
    return ok(await dict_service.save_data(session, user, DictDataSave.model_validate(payload), data_id))


@router.post("/deleteData", response_model=ApiResponse[None])
@permission("cccms:dict:delete_data", "删除字典数据", methods=["POST"])
async def delete_data(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await dict_service.delete_data(session, user, payload.id)
    return ok(None)


@router.post("/batchStatus", response_model=ApiResponse[dict[str, object]])
@permission("cccms:dict:batch_status", "批量修改字典类型状态", methods=["POST"])
async def batch_status(
    payload: BatchStatusRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await dict_service.batch_status(session, user, payload.ids, payload.status)
    return ok({"affected": affected, "skipped": skipped})


@router.post("/batchDelete", response_model=ApiResponse[dict[str, object]])
@permission("cccms:dict:batch_delete", "批量删除字典类型", methods=["POST"])
async def batch_delete(
    payload: IdsRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await dict_service.batch_delete(session, user, payload.ids)
    return ok({"affected": affected, "skipped": skipped})


@router.post("/batchStatusData", response_model=ApiResponse[dict[str, object]])
@permission("cccms:dict:batch_status_data", "批量修改字典数据状态", methods=["POST"])
async def batch_status_data(
    payload: BatchStatusRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await dict_service.batch_status(session, user, payload.ids, payload.status, data=True)
    return ok({"affected": affected, "skipped": skipped})


@router.post("/batchDeleteData", response_model=ApiResponse[dict[str, object]])
@permission("cccms:dict:batch_delete_data", "批量删除字典数据", methods=["POST"])
async def batch_delete_data(
    payload: IdsRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    affected, skipped = await dict_service.batch_delete(session, user, payload.ids, data=True)
    return ok({"affected": affected, "skipped": skipped})
