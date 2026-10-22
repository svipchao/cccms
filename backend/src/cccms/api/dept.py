from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.dept import DeptNode, DeptSave
from cccms.services import dept as dept_service

router = make_router(prefix="/dept", tags=["dept"])


@router.get("/tree", response_model=ApiResponse[list[DeptNode]])
@permission("cccms:dept:tree", "部门树")
async def tree(
    trashed: int = 0,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dept_service.tree(session, user, trashed=bool(trashed)))


@router.get("/options", response_model=ApiResponse[list[DeptNode]])
@permission("cccms:dept:tree", "部门选项")
async def options(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dept_service.tree_all(session, user))


@router.post("/save", response_model=ApiResponse[DeptNode])
@permission("cccms:dept:save", "新增部门", methods=["POST"])
async def save(
    payload: DeptSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await dept_service.create_dept(session, user, payload))


@router.post("/update", response_model=ApiResponse[DeptNode])
@permission("cccms:dept:update", "修改部门", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    dept_id = as_int(payload.pop("id"))
    return ok(await dept_service.update_dept(session, user, dept_id, DeptSave.model_validate(payload)))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:dept:delete", "删除部门", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await dept_service.delete_dept(session, user, payload.id)
    return ok(None)
