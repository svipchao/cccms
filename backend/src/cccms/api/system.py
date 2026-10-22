from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.cache import Keys, bump
from cccms.core.config_store import invalidate
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.seeds import seed_menus
from cccms.services import tenant as tenant_service

router = make_router(prefix="/system", tags=["system"])


@router.post("/refresh", response_model=ApiResponse[dict[str, object]])
@permission("cccms:config:refresh", "刷新系统缓存", methods=["POST"])
async def refresh(
    payload: dict[str, object] | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    scope = str((payload or {}).get("scope") or "all")
    if scope in {"all", "menu", "perm"}:
        # 菜单是平台共享表，重建会覆盖所有租户可见的节点。
        tenant_service.assert_platform_admin(user)
    config_version = await bump(Keys.CONFIG_VERSION)
    dict_version = await bump(Keys.DICT_VERSION)
    auth_version = await bump(Keys.AUTH_VERSION)
    await invalidate(user.tenant_id)
    await invalidate(0)

    menu_result: dict[str, int] = {"created": 0, "updated": 0, "removed": 0}
    perm_result: dict[str, int] = {"created": 0, "skipped": 0}
    if scope in {"all", "menu", "perm"}:
        menu_count, button_count = await seed_menus(session)
        menu_result = {"created": 0, "updated": menu_count, "removed": 0}
        perm_result = {"created": 0, "skipped": button_count}
        await session.commit()

    return ok(
        {
            "scope": scope,
            "config": config_version,
            "dict": dict_version,
            "auth": auth_version,
            "menu": menu_result,
            "perm": perm_result,
            "cache": {"config": True, "dict": True, "auth": True},
        }
    )
