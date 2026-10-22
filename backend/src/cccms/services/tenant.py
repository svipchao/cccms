from __future__ import annotations

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext, current_context
from cccms.core.errors import Conflict, Forbidden, NotFound
from cccms.core.pagination import count_query
from cccms.core.sorting import apply_sort
from cccms.core.tenant import invalidate_domain_cache
from cccms.core.time import now, parse
from cccms.models.tenant import Tenant
from cccms.models.user import User
from cccms.schemas.tenant import TenantOut, TenantSave

# 允许排序的列：前端只给"到期时间"留了排序入口。
SORTABLE = {"id": Tenant.id, "expire_at": Tenant.expire_at}


def assert_platform_admin(actor: UserContext) -> None:
    """租户管理是平台级能力：必须是超管，且当前生效租户就是平台租户。"""
    if not actor.is_super_admin:
        raise Forbidden("仅超级管理员可管理租户")
    ctx = current_context()
    if ctx is None or ctx.tenant is None or not ctx.tenant.is_platform:
        raise Forbidden("请先切换到平台租户再管理租户")


def _to_out(row: Tenant, user_count: int = 0) -> TenantOut:
    return TenantOut(
        id=row.id,
        code=row.code,
        name=row.name,
        domain=row.domain,
        is_platform=row.is_platform,
        status=row.status,
        expire_at=row.expire_at,
        contact=row.contact,
        max_users=row.max_users,
        remark=row.remark,
        user_count=user_count,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def _counts(session: AsyncSession, tenant_ids: list[int]) -> dict[int, int]:
    if not tenant_ids:
        return {}
    rows = await session.execute(
        select(User.tenant_id, func.count(User.id))
        .where(User.tenant_id.in_(tenant_ids), User.delete_time.is_(None))
        .group_by(User.tenant_id)
    )
    return {int(tenant_id): int(total) for tenant_id, total in rows.all()}


async def list_tenants(
    session: AsyncSession,
    actor: UserContext,
    *,
    keyword: str | None = None,
    name: str | None = None,
    code: str | None = None,
    contact: str | None = None,
    phone: str | None = None,
    status: int | None = None,
    start: str | None = None,
    end: str | None = None,
    page: int = 1,
    limit: int = 15,
    sort: str | None = None,
    order: str = "asc",
) -> tuple[int, list[TenantOut]]:
    assert_platform_admin(actor)
    statement = select(Tenant).where(Tenant.delete_time.is_(None))
    if keyword:
        value = f"%{keyword.strip()}%"
        statement = statement.where(
            Tenant.name.like(value) | Tenant.code.like(value) | Tenant.domain.like(value)
        )
    if name:
        statement = statement.where(Tenant.name.like(f"%{name.strip()}%"))
    if code:
        statement = statement.where(Tenant.code.like(f"%{code.strip()}%"))
    if contact or phone:
        value = f"%{(contact or phone or '').strip()}%"
        statement = statement.where(Tenant.contact.like(value))
    if status is not None:
        statement = statement.where(Tenant.status == status)
    start_at = parse(start)
    end_at = parse(end)
    if start_at is not None:
        statement = statement.where(Tenant.create_time >= start_at)
    if end_at is not None:
        statement = statement.where(Tenant.create_time <= end_at)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                apply_sort(
                    statement,
                    SORTABLE,
                    id_column=Tenant.id,
                    fallback=(Tenant.id.asc(),),
                    sort=sort,
                    order=order,
                )
                .offset((page - 1) * limit)
                .limit(limit)
            )
        ).all()
    )
    counts = await _counts(session, [row.id for row in rows])
    return total, [_to_out(row, counts.get(row.id, 0)) for row in rows]


async def get_tenant_row(session: AsyncSession, actor: UserContext, tenant_id: int) -> Tenant:
    assert_platform_admin(actor)
    row = await session.scalar(select(Tenant).where(Tenant.id == tenant_id, Tenant.delete_time.is_(None)))
    if row is None:
        raise NotFound("租户不存在")
    return row


async def read_tenant(session: AsyncSession, actor: UserContext, tenant_id: int) -> TenantOut:
    row = await get_tenant_row(session, actor, tenant_id)
    counts = await _counts(session, [row.id])
    return _to_out(row, counts.get(row.id, 0))


async def _assert_code_free(session: AsyncSession, code: str, exclude_id: int = 0) -> None:
    exists = await session.scalar(
        select(Tenant.id).where(
            Tenant.code == code.strip().lower(),
            Tenant.delete_time.is_(None),
            Tenant.id != exclude_id,
        )
    )
    if exists:
        raise Conflict("租户编码已存在")


async def create_tenant(session: AsyncSession, actor: UserContext, payload: TenantSave) -> TenantOut:
    assert_platform_admin(actor)
    data = payload.model_dump()
    # 平台标记只允许由初始化脚本写入，接口一律忽略，避免造出第二个平台租户。
    data.pop("is_platform", None)
    data["code"] = str(data["code"]).strip().lower()
    data["expire_at"] = parse(payload.expire_at)
    await _assert_code_free(session, data["code"])
    if data["domain"]:
        domain = str(data["domain"]).strip().lower()
        exists = await session.scalar(
            select(Tenant.id).where(Tenant.domain == domain, Tenant.delete_time.is_(None))
        )
        if exists:
            raise Conflict("域名已被占用")
        data["domain"] = domain
    row = Tenant(**data)
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return _to_out(row)


async def update_tenant(
    session: AsyncSession, actor: UserContext, tenant_id: int, payload: TenantSave
) -> TenantOut:
    row = await get_tenant_row(session, actor, tenant_id)
    if row.is_platform:
        raise Conflict("平台租户不可修改")
    old_domain = row.domain
    data = payload.model_dump()
    data.pop("is_platform", None)
    data["code"] = str(data["code"]).strip().lower()
    data["expire_at"] = parse(payload.expire_at)
    await _assert_code_free(session, data["code"], tenant_id)
    if data["domain"]:
        domain = str(data["domain"]).strip().lower()
        exists = await session.scalar(
            select(Tenant.id).where(
                Tenant.domain == domain,
                Tenant.delete_time.is_(None),
                Tenant.id != tenant_id,
            )
        )
        if exists:
            raise Conflict("域名已被占用")
        data["domain"] = domain
    for key, value in data.items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    await invalidate_domain_cache(old_domain, row.domain)
    return _to_out(row)


async def delete_tenant(session: AsyncSession, actor: UserContext, tenant_id: int) -> None:
    row = await get_tenant_row(session, actor, tenant_id)
    if row.is_platform:
        raise Conflict("平台租户不可删除")
    used = await session.scalar(select(User.id).where(User.tenant_id == tenant_id).limit(1))
    if used:
        raise Conflict("租户下仍有账号（含回收站账号），无法删除")
    domain = row.domain
    row.delete_time = now()
    row.update_time = now()
    await session.commit()
    await invalidate_domain_cache(domain)
