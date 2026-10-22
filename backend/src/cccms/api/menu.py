from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext, current_context
from cccms.core.envelope import ApiResponse, ok
from cccms.core.params import as_int
from cccms.core.permissions import make_router, no_auth, permission
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.menu import MenuNode, MenuSave
from cccms.services import menu as menu_service

router = make_router(prefix="/menu", tags=["menu"])


@router.get("/userTree", response_model=ApiResponse[list[MenuNode]])
@no_auth("用户菜单")
async def user_tree(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    ctx = current_context()
    assert ctx is not None and ctx.tenant is not None
    return ok(await menu_service.user_tree(session, user, ctx.tenant))


@router.get("/tree", response_model=ApiResponse[list[MenuNode]])
@permission("cccms:menu:tree", "菜单树")
async def tree(
    trashed: int = 0,
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await menu_service.full_tree(session, trashed=bool(trashed)))


@router.post("/save", response_model=ApiResponse[MenuNode])
@permission("cccms:menu:save", "新增菜单", methods=["POST"])
async def save(
    payload: MenuSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    row = await menu_service.create_menu(session, user, payload)
    return ok(
        MenuNode(
            id=row.id,
            parent_id=row.parent_id,
            type=row.type,
            title=row.title,
            path=row.path,
            component=row.component,
            icon=row.icon,
            sort=row.sort,
            node=row.node,
            status=row.status,
            keep_alive=row.keep_alive,
        )
    )


@router.post("/update", response_model=ApiResponse[MenuNode])
@permission("cccms:menu:update", "修改菜单", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    menu_id = as_int(payload.pop("id"))
    row = await menu_service.update_menu(session, user, menu_id, MenuSave.model_validate(payload))
    return ok(
        MenuNode(
            id=row.id,
            parent_id=row.parent_id,
            type=row.type,
            title=row.title,
            path=row.path,
            component=row.component,
            icon=row.icon,
            sort=row.sort,
            node=row.node,
            status=row.status,
            keep_alive=row.keep_alive,
        )
    )


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:menu:delete", "删除菜单", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await menu_service.delete_menu(session, user, payload.id)
    return ok(None)
