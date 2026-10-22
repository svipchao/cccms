from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import MAX_PAGE_LIMIT, PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.role import RoleCopyRequest, RoleNodeView, RoleOut, RoleSave, RoleUpdate
from cccms.services import role as role_service

router = make_router(prefix="/role", tags=["role"])


@router.get("", response_model=ApiResponse[PageResult[RoleOut]])
@permission("cccms:role:index", "角色列表")
async def index(
    page: int = 1,
    limit: int = 15,
    keyword: str | None = None,
    name: str | None = None,
    status: int | None = None,
    data_scope: int | None = None,
    trashed: int = 0,
    node_id: int | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    page = max(1, page)
    limit = min(MAX_PAGE_LIMIT, max(1, limit))
    total, rows = await role_service.list_roles(
        session,
        user,
        name or keyword,
        status=status,
        data_scope=data_scope,
        trashed=trashed,
        node_id=node_id,
        page=page,
        limit=limit,
    )
    return ok(page_result(total, rows))


@router.get("/tree", response_model=ApiResponse[list[dict[str, object]]])
@permission("cccms:role:tree", "角色树")
async def tree(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await role_service.role_tree(session, user))


@router.get("/read", response_model=ApiResponse[RoleOut])
@permission("cccms:role:read", "角色详情")
async def read(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    row = await role_service.get_role(session, user, id)
    view = await role_service.node_view(session, user, id)
    return ok(
        RoleOut(
            id=row.id,
            tenant_id=row.tenant_id,
            code=row.code,
            name=row.name,
            parent_id=row.parent_id,
            data_scope=row.data_scope,
            is_system=row.is_system,
            status=row.status,
            sort=row.sort,
            remark=row.remark,
            create_time=row.create_time,
            update_time=row.update_time,
            own=view.own,
            inherited=view.inherited,
        )
    )


@router.post("/save", response_model=ApiResponse[RoleOut])
@permission("cccms:role:save", "新增角色", methods=["POST"])
async def save(
    payload: RoleSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    row = await role_service.create_role(session, user, payload)
    if payload.nodes is not None:
        await role_service.save_nodes(session, user, row.id, payload.nodes)
    return ok(row)


@router.post("/update", response_model=ApiResponse[RoleOut])
@permission("cccms:role:update", "修改角色", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    role_id = as_int(payload.pop("id"))
    nodes = payload.pop("nodes", None)
    row = await role_service.update_role(session, user, role_id, RoleUpdate.model_validate(payload))
    if nodes is not None:
        if not isinstance(nodes, list):
            nodes = []
        await role_service.save_nodes(session, user, role_id, [str(item) for item in nodes])
    return ok(row)


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:role:delete", "删除角色", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await role_service.delete_role(session, user, payload.id)
    return ok(None)


@router.post("/copy", response_model=ApiResponse[RoleOut])
@permission("cccms:role:copy", "复制角色", methods=["POST"])
async def copy(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    role_id = as_int(payload.pop("id"))
    return ok(await role_service.copy_role(session, user, role_id, RoleCopyRequest.model_validate(payload)))


@router.get("/nodes", response_model=ApiResponse[RoleNodeView])
@permission("cccms:role:read", "角色授权")
async def nodes(
    id: int,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await role_service.node_view(session, user, id))


@router.post("/nodes", response_model=ApiResponse[None])
@permission("cccms:role:update", "保存角色授权", methods=["POST"])
async def save_nodes(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    role_id = as_int(payload.get("id", 0))
    nodes = payload.get("nodes") or []
    if not isinstance(nodes, list):
        nodes = []
    await role_service.save_nodes(session, user, role_id, [str(item) for item in nodes])
    return ok(None)
