from __future__ import annotations

from typing import Annotated

from fastapi import Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest, IdsRequest
from cccms.schemas.user import (
    BatchAssignRequest,
    BatchResult,
    BatchStatusRequest,
    PasswordReset,
    UserCreate,
    UserOut,
    UserQuery,
    UserUpdate,
)
from cccms.services import user as user_service

router = make_router(prefix="/user", tags=["user"])


@router.get("", response_model=ApiResponse[PageResult[UserOut]])
@permission("cccms:user:index", "用户列表")
async def index(
    params: Annotated[UserQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await user_service.list_users(session, user, params)
    return ok(page_result(total, rows))


@router.get("/read", response_model=ApiResponse[UserOut])
@permission("cccms:user:read", "用户详情")
async def read(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await user_service.read_user(session, user, id))


@router.post("/save", response_model=ApiResponse[UserOut])
@permission("cccms:user:save", "新增用户", methods=["POST"])
async def save(
    payload: UserCreate,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await user_service.create_user(session, user, payload))


@router.post("/update", response_model=ApiResponse[UserOut])
@permission("cccms:user:update", "修改用户", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    user_id = as_int(payload.pop("id"))
    return ok(await user_service.update_user(session, user, user_id, UserUpdate.model_validate(payload)))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:user:delete", "删除用户", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await user_service.delete_user(session, user, payload.id)
    return ok(None)


@router.post("/batchStatus", response_model=ApiResponse[BatchResult])
@permission("cccms:user:batch_status", "批量修改用户状态", methods=["POST"])
async def batch_status(
    payload: BatchStatusRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await user_service.batch_status(session, user, payload))


@router.post("/batchDelete", response_model=ApiResponse[BatchResult])
@permission("cccms:user:batch_delete", "批量删除用户", methods=["POST"])
async def batch_delete(
    payload: IdsRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await user_service.batch_delete(session, user, payload.ids))


@router.post("/batchAssign", response_model=ApiResponse[BatchResult])
@permission("cccms:user:batch_assign", "批量分配", methods=["POST"])
async def batch_assign(
    payload: BatchAssignRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await user_service.batch_assign(session, user, payload))


@router.post("/resetPassword", response_model=ApiResponse[None])
@permission("cccms:user:reset_password", "重置密码", methods=["POST"])
async def reset_password(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    user_id = as_int(payload.pop("id"))
    await user_service.reset_password(session, user, user_id, PasswordReset.model_validate(payload))
    return ok(None)
