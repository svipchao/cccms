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
from cccms.schemas.base import IdRequest
from cccms.schemas.tenant import TenantOut, TenantQuery, TenantSave
from cccms.services import tenant as tenant_service

router = make_router(prefix="/tenant", tags=["tenant"])


@router.get("", response_model=ApiResponse[PageResult[TenantOut]])
@permission("cccms:tenant:index", "租户列表")
async def index(
    params: Annotated[TenantQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await tenant_service.list_tenants(
        session,
        user,
        keyword=params.keyword,
        name=params.name,
        code=params.code,
        contact=params.contact,
        phone=params.phone,
        status=params.status,
        start=params.start,
        end=params.end,
        page=params.page,
        limit=params.limit,
    )
    return ok(page_result(total, rows))


@router.get("/read", response_model=ApiResponse[TenantOut])
@permission("cccms:tenant:read", "租户详情")
async def read(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await tenant_service.read_tenant(session, user, id))


@router.post("/save", response_model=ApiResponse[TenantOut])
@permission("cccms:tenant:save", "新增租户", methods=["POST"])
async def save(
    payload: TenantSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await tenant_service.create_tenant(session, user, payload))


@router.post("/update", response_model=ApiResponse[TenantOut])
@permission("cccms:tenant:update", "修改租户", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    tenant_id = as_int(payload.pop("id"))
    return ok(
        await tenant_service.update_tenant(session, user, tenant_id, TenantSave.model_validate(payload))
    )


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:tenant:delete", "删除租户", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await tenant_service.delete_tenant(session, user, payload.id)
    return ok(None)
