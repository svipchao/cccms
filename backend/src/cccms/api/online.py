from __future__ import annotations

import time
from datetime import datetime

from fastapi import Depends

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.errors import NotFound, ValidationFailed
from cccms.core.pagination import PageLimit, PageNum
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.core.session import (
    online_sessions,
    remove_online,
    remove_user_online,
    revoke_token,
    revoke_user_tokens,
)
from cccms.core.time import DATETIME_FORMAT, LOCAL_TZ, parse
from cccms.schemas.base import IdRequest, StrictModel

router = make_router(prefix="/online", tags=["online"])


class KickRequest(StrictModel):
    jti: str


def _ua_fields(ua: str) -> tuple[str, str, str]:
    """Derive device / OS / browser from a User-Agent string."""
    value = (ua or "").lower()
    if "micromessenger" in value:
        browser = "微信"
    elif "edg/" in value or "edge/" in value:
        browser = "Edge"
    elif "opr/" in value or "opera" in value:
        browser = "Opera"
    elif "firefox" in value:
        browser = "Firefox"
    elif "chrome" in value or "crios" in value:
        browser = "Chrome"
    elif "safari" in value:
        browser = "Safari"
    elif "msie" in value or "trident" in value:
        browser = "IE"
    else:
        browser = "其他"

    if "ipad" in value or "tablet" in value:
        device = "平板"
    elif "mobile" in value or "android" in value or "iphone" in value:
        device = "移动端"
    elif "bot" in value or "spider" in value or "crawler" in value:
        device = "爬虫"
    elif value:
        device = "电脑"
    else:
        device = "未知"

    if "windows" in value:
        os_name = "Windows"
    elif "android" in value:
        os_name = "Android"
    elif "iphone" in value or "ipad" in value or "ios" in value:
        os_name = "iOS"
    elif "harmony" in value or "openharmony" in value:
        os_name = "HarmonyOS"
    elif "mac os" in value or "macintosh" in value:
        os_name = "macOS"
    elif "linux" in value:
        os_name = "Linux"
    else:
        os_name = ""
    return device, os_name, browser


def _as_int(value: object) -> int:
    """在线会话数据来自 Redis，类型不可信；非法值按 0 处理而不是抛错。"""
    try:
        return as_int(value)
    except ValidationFailed:
        return 0


def _ts_fmt(value: int) -> str:
    return datetime.fromtimestamp(value, LOCAL_TZ).strftime(DATETIME_FORMAT) if value else ""


def _ts_dt(value: int) -> datetime:
    return datetime.fromtimestamp(value, LOCAL_TZ).replace(tzinfo=None)


def _normalized(row: dict[str, object], current_jti: str) -> dict[str, object]:
    ua = str(row.get("ua") or "")
    device, os_name, browser = _ua_fields(ua)
    login_ts = _as_int(row.get("login_at"))
    last_ts = _as_int(row.get("last_at"))
    exp_ts = _as_int(row.get("exp"))
    return {
        "jti": row.get("jti"),
        "user_id": _as_int(row.get("user_id")),
        "username": row.get("username"),
        "nickname": row.get("nickname"),
        "ip": row.get("ip"),
        "ua": ua,
        "device": device,
        "os": os_name,
        "browser": browser,
        "login_at": _ts_fmt(login_ts),
        "last_at": _ts_fmt(last_ts),
        "expire_at": _ts_fmt(exp_ts),
        "current": row.get("jti") == current_jti,
    }


def _in_range(value: int, start: str | None, end: str | None) -> bool:
    if not value:
        return False
    current = _ts_dt(value)
    start_at = parse(start)
    end_at = parse(end)
    if start_at is not None and current < start_at:
        return False
    return not (end_at is not None and current > end_at)


@router.get("", response_model=ApiResponse[dict[str, object]])
@permission("cccms:online:index", "在线用户")
async def index(
    username: str | None = None,
    ip: str | None = None,
    keyword: str | None = None,
    login_start: str | None = None,
    login_end: str | None = None,
    active_start: str | None = None,
    active_end: str | None = None,
    expire_start: str | None = None,
    expire_end: str | None = None,
    sort: str | None = None,
    order: str | None = None,
    page: PageNum = 1,
    limit: PageLimit = 15,
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    rows = await online_sessions(user.tenant_id)
    if keyword:
        lowered = keyword.strip().lower()
        rows = [
            row
            for row in rows
            if lowered in str(row.get("username", "")).lower()
            or lowered in str(row.get("nickname", "")).lower()
            or lowered in str(row.get("ip", ""))
        ]
    if username:
        lowered = username.strip().lower()
        rows = [row for row in rows if lowered in str(row.get("username", "")).lower()]
    if ip:
        lowered = ip.strip().lower()
        rows = [row for row in rows if lowered in str(row.get("ip", ""))]
    if login_start or login_end:
        rows = [row for row in rows if _in_range(int(row.get("login_at", 0) or 0), login_start, login_end)]
    if active_start or active_end:
        rows = [row for row in rows if _in_range(int(row.get("last_at", 0) or 0), active_start, active_end)]
    if expire_start or expire_end:
        rows = [row for row in rows if _in_range(int(row.get("exp", 0) or 0), expire_start, expire_end)]

    sortable = {"login_at": "login_at", "last_at": "last_at"}
    key = sortable.get(sort or "", "last_at")
    reverse = (order or "desc").lower() != "asc"
    rows.sort(key=lambda item: _as_int(item.get(key)), reverse=reverse)

    page = max(1, page)
    limit = min(200, max(1, limit))
    total = len(rows)
    start = (page - 1) * limit
    page_rows = rows[start : start + limit]
    return ok(
        {
            "total": total,
            "list": [_normalized(row, user.jti) for row in page_rows],
        }
    )


@router.post("/kick", response_model=ApiResponse[None])
@permission("cccms:online:kick", "强制下线", methods=["POST"])
async def kick(payload: KickRequest, user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    sessions = await online_sessions(user.tenant_id)
    target = next((item for item in sessions if item.get("jti") == payload.jti), None)
    if target is None:
        raise NotFound("会话不存在")
    if _as_int(target.get("user_id")) == user.id:
        raise ValidationFailed("不能下线自己")
    exp = _as_int(target.get("exp"))
    await revoke_token(payload.jti, max(0, exp - int(time.time())))
    await remove_online(payload.jti)
    return ok(None)


@router.post("/kickUser", response_model=ApiResponse[dict[str, int]])
@permission("cccms:online:kick_user", "强制用户全部下线", methods=["POST"])
async def kick_user(payload: IdRequest, user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    if payload.id == user.id:
        raise ValidationFailed("不能下线自己")
    sessions = await online_sessions(user.tenant_id)
    if not any(_as_int(item.get("user_id")) == payload.id for item in sessions):
        raise NotFound("该用户在当前租户没有在线会话")
    await revoke_user_tokens(payload.id, 7200)
    return ok({"sessions": await remove_user_online(payload.id)})
