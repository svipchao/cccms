from __future__ import annotations

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.permissions import make_router, no_auth
from cccms.db.session import get_session
from cccms.schemas.auth import (
    PasswordChangeRequest,
    ProfileUpdateRequest,
    SessionRevokeRequest,
    UserProfile,
)
from cccms.services import auth as auth_service

router = make_router(prefix="/profile", tags=["profile"])


@router.get("", response_model=ApiResponse[UserProfile])
@no_auth("个人资料")
async def index(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await auth_service.me(session, user))


@router.post("/update", response_model=ApiResponse[UserProfile])
@no_auth("修改个人资料")
async def update(
    payload: ProfileUpdateRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await auth_service.update_profile(session, user, payload))


@router.post("/password", response_model=ApiResponse[None])
@no_auth("修改密码")
async def password(
    payload: PasswordChangeRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await auth_service.change_password(session, user, payload)
    return ok(None)


@router.get("/sessions", response_model=ApiResponse[list[dict[str, object]]])
@no_auth("我的登录设备")
async def sessions(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    return ok(await auth_service.list_sessions(user))


@router.post("/sessions/revoke", response_model=ApiResponse[None])
@no_auth("注销我的会话")
async def revoke_session(
    payload: SessionRevokeRequest,
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    await auth_service.revoke_session(user, payload.jti)
    return ok(None)
