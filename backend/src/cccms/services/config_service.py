from __future__ import annotations

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core import session as token_session
from cccms.core.config_store import get_value, invalidate, ui_payload
from cccms.core.context import UserContext, current_context
from cccms.core.errors import ValidationFailed
from cccms.core.security import get_cipher
from cccms.core.time import now
from cccms.models.dict import Config
from cccms.schemas.system import ConfigOut

GROUP_LABELS = {
    "system": "系统",
    "ui": "界面",
    "security": "安全",
    "upload": "上传",
    "log": "日志",
    "mail": "邮箱",
    "sms": "短信",
    "watermark": "水印",
}
SECRET_TYPES = {"password", "secret"}


def _to_out(row: Config) -> ConfigOut:
    has_value = bool(row.value)
    value = None if row.type in SECRET_TYPES else row.value
    return ConfigOut(
        id=row.id,
        group_key=row.group_key,
        group=row.group_key,
        group_label=GROUP_LABELS.get(row.group_key, row.group_key),
        name=row.name,
        title=row.title,
        type=row.type,
        value=value,
        has_value=has_value,
        options=row.options,
        sort=row.sort,
        status=row.status,
        remark=row.remark,
    )


async def list_configs(
    session: AsyncSession, actor: UserContext, group: str | None = None
) -> list[ConfigOut]:
    statement = select(Config).where(
        (Config.tenant_id == actor.tenant_id) | (Config.tenant_id.is_(None)),
        Config.status == 1,
    )
    if group:
        statement = statement.where(Config.group_key == group)
    rows = list((await session.scalars(statement.order_by(Config.sort.asc(), Config.id.asc()))).all())
    # tenant override wins
    merged: dict[str, Config] = {}
    for row in rows:
        if row.name not in merged or row.tenant_id is not None:
            merged[row.name] = row
    return [
        _to_out(row) for row in sorted(merged.values(), key=lambda item: (item.group_key, item.sort, item.id))
    ]


async def save_configs(session: AsyncSession, actor: UserContext, values: dict[str, str]) -> int:
    if not values:
        return 0
    rows = list(
        (
            await session.scalars(
                select(Config).where(
                    Config.name.in_(list(values.keys())),
                    (Config.tenant_id == actor.tenant_id) | (Config.tenant_id.is_(None)),
                )
            )
        ).all()
    )
    by_name: dict[str, Config] = {}
    for row in rows:
        if row.name not in by_name or row.tenant_id is not None:
            by_name[row.name] = row

    missing = sorted(name for name in values if name not in by_name)
    if missing:
        raise ValidationFailed(f"未知配置项：{', '.join(missing[:5])}；请先执行 seed 初始化默认配置")
    cipher = get_cipher()
    updated = 0
    maintenance_before = await get_value(session, "system.maintenance", "0", tenant_id=actor.tenant_id)
    ctx = current_context()
    platform_admin = bool(actor.is_super_admin and ctx and ctx.tenant and ctx.tenant.is_platform)
    for name, raw in values.items():
        row = by_name[name]
        if row.tenant_id is None and not platform_admin:
            # 平台级全局行是「所有租户的默认值」：只有平台超管可改，
            # 业务租户保存时自动生成自己的覆盖行。
            row = Config(
                tenant_id=actor.tenant_id,
                group_key=row.group_key,
                name=row.name,
                title=row.title,
                type=row.type,
                value="",
                options=row.options,
                sort=row.sort,
                status=row.status,
                remark=row.remark,
            )
            session.add(row)
            by_name[name] = row
        if row.type in SECRET_TYPES:
            # 显式提交空串表示清空密钥；未提交的字段不会出现在 values 里。
            row.value = "enc:" + cipher.encrypt(str(raw)) if raw else ""
        else:
            row.value = str(raw)
        row.update_time = now()
        updated += 1
    await session.commit()
    await invalidate(actor.tenant_id)
    await invalidate(None)
    maintenance_after = values.get("system.maintenance", maintenance_before)
    if str(maintenance_after) == "1" and maintenance_before != "1":
        ctx = current_context()
        # 会话水位是全局 key：只有平台维护才允许全站下线，
        # 租户级维护由 load_user 的租户配置检查逐请求拦截。
        if ctx is not None and ctx.tenant is not None and ctx.tenant.is_platform:
            await token_session.cut_session_floor(7200)
        if ctx is not None:
            ctx.extra["maintenance_changed"] = "1"
    return updated


async def ui(session: AsyncSession, tenant_id: int | None) -> dict[str, object]:
    return await ui_payload(session, tenant_id)
