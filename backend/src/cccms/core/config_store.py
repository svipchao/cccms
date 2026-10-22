from __future__ import annotations

from typing import Any

from sqlalchemy import or_, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.cache import Keys, delete, get_json, set_json
from cccms.core.delivery import deliverable_channels
from cccms.models.dict import Config

_TRUE = {"1", "true", "yes", "on"}


async def _all_values(session: AsyncSession, tenant_id: int | None) -> dict[str, str]:
    cache_key = Keys.config_all(tenant_id or 0)
    cached = await get_json(cache_key)
    if isinstance(cached, dict):
        return {str(key): str(value) for key, value in cached.items()}

    rows = (
        await session.scalars(
            select(Config).where(
                Config.status == 1,
                or_(Config.tenant_id == tenant_id, Config.tenant_id.is_(None)),
            )
        )
    ).all()
    values: dict[str, str] = {}
    # Global first, tenant override second.
    for row in sorted(rows, key=lambda item: 0 if item.tenant_id is None else 1):
        values[row.name] = row.value or ""
    await set_json(cache_key, values, ttl=300)
    return values


async def get_value(
    session: AsyncSession,
    name: str,
    default: str = "",
    *,
    tenant_id: int | None = None,
) -> str:
    values = await _all_values(session, tenant_id)
    return values.get(name, default)


async def get_bool(
    session: AsyncSession,
    name: str,
    default: bool = False,
    *,
    tenant_id: int | None = None,
) -> bool:
    raw = await get_value(session, name, "1" if default else "0", tenant_id=tenant_id)
    return raw.strip().lower() in _TRUE


async def get_int(
    session: AsyncSession,
    name: str,
    default: int = 0,
    *,
    tenant_id: int | None = None,
) -> int:
    raw = await get_value(session, name, str(default), tenant_id=tenant_id)
    try:
        return int(raw)
    except (TypeError, ValueError):
        return default


async def invalidate(tenant_id: int | None = None) -> None:
    if tenant_id is None:
        await delete(Keys.config_all(0))
    else:
        await delete(Keys.config_all(tenant_id))


async def ui_payload(session: AsyncSession, tenant_id: int | None) -> dict[str, Any]:
    values = await _all_values(session, tenant_id)

    def text(name: str, default: str = "") -> str:
        return values.get(name, default)

    def integer(name: str, default: int = 0) -> int:
        try:
            return int(values.get(name, str(default)))
        except ValueError:
            return default

    def flag(name: str, default: bool = False) -> bool:
        return values.get(name, "1" if default else "0").lower() in _TRUE

    return {
        "system": {
            "name": text("system.name", "CCCMS"),
            "logo": text("system.logo"),
            "icp": text("system.icp"),
            "copyright": text("system.copyright", "© 2026 CCCMS"),
            "maintenance": flag("system.maintenance"),
            "notice": text("system.maintenance_notice", "系统维护中，请稍后访问"),
        },
        "ui": {
            "theme_mode": text("ui.theme_mode", "light"),
            "theme_primary": text("ui.theme_primary", "#2b6cff"),
            "page_size": integer("ui.page_size", 15),
            "tags_view": flag("ui.tags_view", True),
            "container_width": integer("ui.container_width", 0),
        },
        "security": {
            "reset_channels": deliverable_channels(
                [
                    item
                    for item in ("email", "sms")
                    if text(f"security.reset_{item}_enabled", "0") == "1"
                ]
            ),
        },
        "watermark": {
            "enabled": flag("watermark.enabled"),
            "content": text("watermark.content", "{username}{newline}{time}"),
            "opacity": integer("watermark.opacity", 12),
            "font_size": integer("watermark.font_size", 14),
            "angle": integer("watermark.angle", -22),
            "color": text("watermark.color", "#8c8c8c"),
            "gap_x": integer("watermark.gap_x", 180),
            "gap_y": integer("watermark.gap_y", 140),
        },
    }
