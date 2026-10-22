from __future__ import annotations

from dataclasses import asdict

from sqlalchemy import select

from cccms.core.cache import Keys, delete, get_json, set_json
from cccms.core.context import TenantContext
from cccms.core.time import fmt, now, parse
from cccms.db.session import session_scope
from cccms.models.tenant import Tenant


def _to_context(tenant: Tenant) -> TenantContext:
    return TenantContext(
        id=tenant.id,
        code=tenant.code,
        name=tenant.name,
        is_platform=bool(tenant.is_platform),
        domain=tenant.domain,
        expire_at=fmt(tenant.expire_at),
    )


def _expired(value: str | None) -> bool:
    parsed = parse(value)
    return parsed is not None and parsed < now()


def normalize_host(host: str | None) -> str:
    if not host:
        return ""
    value = host.strip().lower()
    if value.startswith("["):
        return value.split("]")[0].lstrip("[")
    return value.split(":")[0]


async def tenant_from_host(host: str | None) -> TenantContext | None:
    name = normalize_host(host)
    if not name:
        return None
    cached = await get_json(f"{Keys.TENANT_DOMAIN}{name}")
    # 缓存命中也要检查到期时间，否则域名绑定会绕过租户到期。
    if cached and not _expired(cached.get("expire_at")):
        return TenantContext(**cached)
    async with session_scope() as session:
        tenant = await session.scalar(
            select(Tenant).where(
                Tenant.domain == name,
                Tenant.delete_time.is_(None),
                Tenant.status == 1,
            )
        )
        if tenant is None or _expired(fmt(tenant.expire_at)):
            return None
        context = _to_context(tenant)
    await set_json(f"{Keys.TENANT_DOMAIN}{name}", asdict(context), ttl=300)
    return context


async def invalidate_domain_cache(*domains: str | None) -> None:
    keys = {f"{Keys.TENANT_DOMAIN}{normalize_host(domain)}" for domain in domains if domain}
    await delete(*keys)


async def get_tenant_by_code(code: str) -> TenantContext | None:
    async with session_scope() as session:
        tenant = await session.scalar(
            select(Tenant).where(
                Tenant.code == code.strip().lower(),
                Tenant.delete_time.is_(None),
                Tenant.status == 1,
            )
        )
        if tenant is None:
            return None
        if tenant.expire_at is not None and tenant.expire_at < now():
            return None
        return _to_context(tenant)


async def get_tenant(tenant_id: int) -> TenantContext | None:
    async with session_scope() as session:
        tenant = await session.scalar(
            select(Tenant).where(
                Tenant.id == tenant_id,
                Tenant.delete_time.is_(None),
                Tenant.status == 1,
            )
        )
        if tenant is None:
            return None
        if tenant.expire_at is not None and tenant.expire_at < now():
            return None
        return _to_context(tenant)


async def public_tenants() -> list[dict[str, str | int]]:
    async with session_scope() as session:
        rows = (
            await session.scalars(
                select(Tenant)
                .where(
                    Tenant.delete_time.is_(None),
                    Tenant.status == 1,
                )
                .order_by(Tenant.id.asc())
            )
        ).all()
        return [
            {"id": item.id, "code": item.code, "name": item.name}
            for item in rows
            if item.expire_at is None or item.expire_at >= now()
        ]
