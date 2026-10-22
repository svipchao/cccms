from __future__ import annotations

from fastapi import Depends

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.permissions import make_router, permission
from cccms.schemas.upgrade import (
    UpgradeCheckOut,
    UpgradeResultOut,
    UpgradeStatusOut,
    UpgradeTagOut,
)
from cccms.services import upgrade as upgrade_service

router = make_router(prefix="/upgrade", tags=["upgrade"])


@router.get("", response_model=ApiResponse[UpgradeStatusOut])
@permission("cccms:upgrade:index", "查看升级状态", methods=["GET"])
async def index(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await upgrade_service.status())


@router.get("/check", response_model=ApiResponse[UpgradeCheckOut])
@permission("cccms:upgrade:check", "检查更新", methods=["GET"])
async def check(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await upgrade_service.check())


@router.get("/tags", response_model=ApiResponse[list[UpgradeTagOut]])
@permission("cccms:upgrade:tags", "查看版本标签", methods=["GET"])
async def tags(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await upgrade_service.tags())


@router.post("/init", response_model=ApiResponse[UpgradeResultOut])
@permission("cccms:upgrade:init", "初始化升级仓库", methods=["POST"])
async def init(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await upgrade_service.init())


@router.post("/run", response_model=ApiResponse[UpgradeResultOut])
@permission("cccms:upgrade:run", "执行在线升级", methods=["POST"])
async def run(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await upgrade_service.run())
