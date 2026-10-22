from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext, current_context
from cccms.core.envelope import ApiResponse, ok
from cccms.core.i18n import DEFAULT_LOCALE, SUPPORTED_LOCALES
from cccms.core.permissions import make_router, no_login, permission
from cccms.db.session import get_session
from cccms.schemas.system import ConfigOut
from cccms.services import config_service

router = make_router(prefix="/config", tags=["config"])


@router.get("/ui", response_model=ApiResponse[dict[str, object]])
@no_login("前台初始化配置")
async def ui(session: AsyncSession = Depends(get_session)) -> dict[str, object]:
    ctx = current_context()
    tenant_id = ctx.tenant.id if ctx and ctx.tenant else None
    payload = await config_service.ui(session, tenant_id)
    payload["locale"] = DEFAULT_LOCALE
    payload["locales"] = list(SUPPORTED_LOCALES)
    return ok(payload)


@router.get("", response_model=ApiResponse[list[ConfigOut]])
@permission("cccms:config:index", "配置列表")
async def index(
    group: str | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await config_service.list_configs(session, user, group))


@router.post("/save", response_model=ApiResponse[dict[str, int]])
@permission("cccms:config:save", "保存配置", methods=["POST"])
async def save(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    # 兼容两种提交：平铺 {key: value}（前端只提交变更项）或 {"values": {...}}
    raw = payload.get("values")
    source = raw if isinstance(raw, dict) else payload
    values = {
        str(key): ("1" if value is True else "0" if value is False else str(value))
        for key, value in source.items()
        if value is not None
    }
    updated = await config_service.save_configs(session, user, values)
    return ok({"updated": updated})
