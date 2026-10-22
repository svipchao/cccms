from __future__ import annotations

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound
from cccms.core.pagination import count_query
from cccms.core.time import now
from cccms.models.user import Post, UserPost
from cccms.schemas.dept import PostOut, PostSave


def _to_out(row: Post) -> PostOut:
    return PostOut(
        id=row.id,
        code=row.code,
        name=row.name,
        sort=row.sort,
        status=row.status,
        remark=row.remark,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def list_posts(
    session: AsyncSession,
    actor: UserContext,
    *,
    keyword: str | None = None,
    status: int | None = None,
    trashed: int = 0,
    page: int = 1,
    limit: int = 15,
) -> tuple[int, list[PostOut]]:
    statement = select(Post).where(Post.tenant_id == actor.tenant_id)
    statement = statement.where(
        Post.delete_time.is_not(None) if trashed else Post.delete_time.is_(None)
    )
    if keyword:
        value = f"%{keyword.strip()}%"
        statement = statement.where(Post.name.like(value) | Post.code.like(value))
    if status is not None:
        statement = statement.where(Post.status == status)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(Post.sort.asc(), Post.id.asc()).offset((page - 1) * limit).limit(limit)
            )
        ).all()
    )
    return total, [_to_out(row) for row in rows]


async def get_post(session: AsyncSession, actor: UserContext, post_id: int) -> Post:
    row = await session.scalar(
        select(Post).where(
            Post.id == post_id,
            Post.tenant_id == actor.tenant_id,
            Post.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("岗位不存在")
    return row


async def create_post(session: AsyncSession, actor: UserContext, payload: PostSave) -> PostOut:
    exists = await session.scalar(
        select(Post.id).where(
            Post.tenant_id == actor.tenant_id,
            Post.code == payload.code,
            Post.delete_time.is_(None),
        )
    )
    if exists:
        raise Conflict("岗位编码已存在")
    row = Post(tenant_id=actor.tenant_id, **payload.model_dump())
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return _to_out(row)


async def update_post(session: AsyncSession, actor: UserContext, post_id: int, payload: PostSave) -> PostOut:
    row = await get_post(session, actor, post_id)
    if payload.code != row.code:
        exists = await session.scalar(
            select(Post.id).where(
                Post.tenant_id == actor.tenant_id,
                Post.code == payload.code,
                Post.id != post_id,
                Post.delete_time.is_(None),
            )
        )
        if exists:
            raise Conflict("岗位编码已存在")
    for key, value in payload.model_dump().items():
        setattr(row, key, value)
    row.update_time = now()
    await session.commit()
    return _to_out(row)


async def delete_post(session: AsyncSession, actor: UserContext, post_id: int) -> None:
    row = await get_post(session, actor, post_id)
    used = await session.scalar(select(UserPost.id).where(UserPost.post_id == post_id).limit(1))
    if used:
        raise Conflict("岗位下仍有用户")
    row.delete_time = now()
    row.update_time = now()
    await session.commit()


async def batch_status(session: AsyncSession, actor: UserContext, ids: list[int], status: int) -> tuple[int, list[int]]:
    affected = 0
    skipped: list[int] = []
    for post_id in dict.fromkeys(ids):
        row = await session.scalar(
            select(Post).where(Post.id == post_id, Post.tenant_id == actor.tenant_id, Post.delete_time.is_(None))
        )
        if row is None:
            skipped.append(post_id)
            continue
        row.status = status
        row.update_time = now()
        affected += 1
    await session.commit()
    return affected, skipped


async def batch_delete(session: AsyncSession, actor: UserContext, ids: list[int]) -> tuple[int, list[int]]:
    affected = 0
    skipped: list[int] = []
    for post_id in dict.fromkeys(ids):
        row = await session.scalar(
            select(Post).where(Post.id == post_id, Post.tenant_id == actor.tenant_id, Post.delete_time.is_(None))
        )
        if row is None:
            skipped.append(post_id)
            continue
        used = await session.scalar(select(UserPost.id).where(UserPost.post_id == post_id).limit(1))
        if used:
            skipped.append(post_id)
            continue
        row.delete_time = now()
        row.update_time = now()
        affected += 1
    await session.commit()
    return affected, skipped
