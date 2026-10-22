from __future__ import annotations

from collections import defaultdict
from typing import Any

from sqlalchemy import ColumnElement, Select, delete, func, insert, or_, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import NotFound, ValidationFailed
from cccms.core.pagination import count_query
from cccms.core.rbac import has_permission
from cccms.core.time import now, parse
from cccms.models.notice import Notice, NoticeRead, NoticeTarget
from cccms.models.user import Dept, Role, User, UserDept, UserRole
from cccms.schemas.notice import (
    NoticeListOut,
    NoticeMyOut,
    NoticeOptionsOut,
    NoticeOut,
    NoticeReportOut,
    NoticeReportRow,
    NoticeSave,
    NoticeTargetIn,
)


def _published_clause() -> list[ColumnElement[bool]]:
    current = now()
    return [
        Notice.status == 1,
        or_(Notice.publish_at.is_(None), Notice.publish_at <= current),
        or_(Notice.expire_at.is_(None), Notice.expire_at > current),
    ]


def _visible_clause(actor: UserContext) -> ColumnElement[bool]:
    target_ids = select(NoticeTarget.notice_id).where(
        or_(
            (NoticeTarget.target_type == "user") & (NoticeTarget.target_id == actor.id),
            (NoticeTarget.target_type == "role") & NoticeTarget.target_id.in_(actor.role_ids),
            (NoticeTarget.target_type == "dept") & NoticeTarget.target_id.in_(actor.dept_subtree_ids),
        )
    )
    return or_(Notice.scope == 0, Notice.id.in_(target_ids))


TARGET_TYPE_BY_SCOPE = {1: "dept", 2: "role", 3: "user"}


def _targets(payload: NoticeSave) -> list[NoticeTargetIn]:
    if payload.targets:
        return payload.targets
    target_type = TARGET_TYPE_BY_SCOPE.get(payload.scope)
    if target_type is None:
        return []
    return [
        NoticeTargetIn(target_type=target_type, target_id=item) for item in dict.fromkeys(payload.target_ids)
    ]


def _list_out(
    row: Notice,
    *,
    create_by_name: str = "",
    target_count: int = 0,
    target_ids: list[int] | None = None,
) -> NoticeListOut:
    return NoticeListOut(
        id=row.id,
        type=row.type,
        level=row.level,
        title=row.title,
        summary=row.summary,
        content=row.content,
        status=row.status,
        scope=row.scope,
        pinned=row.pinned,
        target_ids=target_ids or [],
        publish_at=row.publish_at,
        expire_at=row.expire_at,
        create_by=row.create_by,
        create_by_name=create_by_name,
        read_count=row.read_count,
        target_count=target_count,
        create_time=row.create_time,
        update_time=row.update_time,
    )


async def list_notices(
    session: AsyncSession,
    actor: UserContext,
    *,
    keyword: str | None = None,
    title: str | None = None,
    type: int | None = None,
    level: int | None = None,
    scope: int | None = None,
    status: int | None = None,
    trashed: int = 0,
    start: str | None = None,
    end: str | None = None,
    publish_start: str | None = None,
    publish_end: str | None = None,
    expire_start: str | None = None,
    expire_end: str | None = None,
    page: int = 1,
    limit: int = 15,
) -> tuple[int, list[NoticeListOut]]:
    statement = select(Notice).where(Notice.tenant_id == actor.tenant_id)
    statement = statement.where(Notice.delete_time.is_not(None) if trashed else Notice.delete_time.is_(None))
    if not has_permission(actor, "cccms:notice:update"):
        # 与 read_notice 同口径：只有 index 权限的角色看不到草稿、未发布、已过期
        # 以及投递给别人的定向公告，避免"列表看得到、点详情 404"。
        statement = statement.where(*_published_clause(), _visible_clause(actor))
    if keyword or title:
        value = f"%{(title or keyword or '').strip()}%"
        statement = statement.where(Notice.title.like(value) | Notice.summary.like(value))
    if type:
        statement = statement.where(Notice.type == type)
    if level is not None:
        statement = statement.where(Notice.level == level)
    if scope is not None:
        statement = statement.where(Notice.scope == scope)
    if status is not None:
        statement = statement.where(Notice.status == status)
    for column, start_value, end_value in (
        (Notice.create_time, start, end),
        (Notice.publish_at, publish_start, publish_end),
        (Notice.expire_at, expire_start, expire_end),
    ):
        start_at = parse(start_value)
        end_at = parse(end_value)
        if start_at is not None:
            statement = statement.where(column >= start_at)
        if end_at is not None:
            statement = statement.where(column <= end_at)
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(Notice.pinned.desc(), Notice.id.desc())
                .offset((page - 1) * limit)
                .limit(limit)
            )
        ).all()
    )
    if not rows:
        return total, []
    ids = [row.id for row in rows]
    creators = dict(
        (
            await session.execute(
                select(User.id, User.nickname).where(
                    User.id.in_({row.create_by for row in rows if row.create_by})
                )
            )
        ).all()
    )
    target_counts = dict(
        (
            await session.execute(
                select(NoticeTarget.notice_id, func.count(NoticeTarget.id))
                .where(NoticeTarget.notice_id.in_(ids))
                .group_by(NoticeTarget.notice_id)
            )
        ).all()
    )
    target_ids: dict[int, list[int]] = defaultdict(list)
    for notice_id, target_id in (
        await session.execute(
            select(NoticeTarget.notice_id, NoticeTarget.target_id).where(NoticeTarget.notice_id.in_(ids))
        )
    ).all():
        target_ids[int(notice_id)].append(int(target_id))
    return total, [
        _list_out(
            row,
            create_by_name=str(creators.get(row.create_by or 0, "")),
            target_count=int(target_counts.get(row.id, 0)),
            target_ids=target_ids.get(row.id, []),
        )
        for row in rows
    ]


async def _get_row(session: AsyncSession, actor: UserContext, notice_id: int) -> Notice:
    row = await session.scalar(
        select(Notice).where(
            Notice.id == notice_id,
            Notice.tenant_id == actor.tenant_id,
            Notice.delete_time.is_(None),
        )
    )
    if row is None:
        raise NotFound("通知公告不存在")
    return row


async def read_notice(session: AsyncSession, actor: UserContext, notice_id: int) -> NoticeOut:
    statement = select(Notice).where(
        Notice.id == notice_id,
        Notice.tenant_id == actor.tenant_id,
        Notice.delete_time.is_(None),
    )
    if not has_permission(actor, "cccms:notice:update"):
        # 只读角色不能通过详情接口看到草稿或别人的定向公告。
        statement = statement.where(*_published_clause(), _visible_clause(actor))
    row = await session.scalar(statement)
    if row is None:
        raise NotFound("通知公告不存在")
    targets = list(
        (await session.scalars(select(NoticeTarget).where(NoticeTarget.notice_id == notice_id))).all()
    )
    creator = await session.get(User, row.create_by) if row.create_by else None
    target_list = [NoticeTargetIn(target_type=item.target_type, target_id=item.target_id) for item in targets]
    base = _list_out(
        row,
        create_by_name=creator.nickname if creator else "",
        target_count=len(targets),
        target_ids=[item.target_id for item in targets],
    )
    return NoticeOut(
        **base.model_dump(),
        content=row.content,
        targets=target_list,
        target_ids=[item.target_id for item in targets],
    )


async def _replace_targets(session: AsyncSession, notice_id: int, targets: list[NoticeTargetIn]) -> None:
    await session.execute(delete(NoticeTarget).where(NoticeTarget.notice_id == notice_id))
    seen: set[tuple[str, int]] = set()
    for item in targets:
        key = (item.target_type, item.target_id)
        if key in seen:
            continue
        seen.add(key)
        session.add(NoticeTarget(notice_id=notice_id, target_type=item.target_type, target_id=item.target_id))


async def create_notice(session: AsyncSession, actor: UserContext, payload: NoticeSave) -> NoticeOut:
    targets = _targets(payload)
    if payload.scope in (1, 2, 3) and not targets:
        raise ValidationFailed("定向发布至少选择一个投放对象")
    data = payload.model_dump(exclude={"targets", "target_ids"})
    data["publish_at"] = parse(payload.publish_at) or (now() if payload.status == 1 else None)
    data["expire_at"] = parse(payload.expire_at)
    row = Notice(tenant_id=actor.tenant_id, create_by=actor.id, **data)
    session.add(row)
    await session.flush()
    await _replace_targets(session, row.id, targets)
    await session.commit()
    return await read_notice(session, actor, row.id)


async def update_notice(
    session: AsyncSession, actor: UserContext, notice_id: int, payload: NoticeSave
) -> NoticeOut:
    row = await _get_row(session, actor, notice_id)
    targets = _targets(payload)
    if payload.scope in (1, 2, 3) and not targets:
        raise ValidationFailed("定向发布至少选择一个投放对象")
    data = payload.model_dump(exclude={"targets", "target_ids"})
    data["publish_at"] = parse(payload.publish_at) or row.publish_at
    if payload.status == 1 and data["publish_at"] is None:
        data["publish_at"] = now()
    # 未提交 expire_at 时保留原值，避免「只改标题」把过期时间清空。
    if "expire_at" in payload.model_fields_set:
        data["expire_at"] = parse(payload.expire_at)
    for key, value in data.items():
        setattr(row, key, value)
    row.update_time = now()
    await _replace_targets(session, notice_id, targets)
    await session.commit()
    return await read_notice(session, actor, notice_id)


async def delete_notice(session: AsyncSession, actor: UserContext, notice_id: int) -> None:
    row = await _get_row(session, actor, notice_id)
    row.delete_time = now()
    row.update_time = now()
    await session.execute(delete(NoticeRead).where(NoticeRead.notice_id == notice_id))
    await session.execute(delete(NoticeTarget).where(NoticeTarget.notice_id == notice_id))
    await session.commit()


async def options(session: AsyncSession, actor: UserContext) -> NoticeOptionsOut:
    depts = list(
        (
            await session.scalars(
                select(Dept)
                .where(Dept.tenant_id == actor.tenant_id, Dept.delete_time.is_(None), Dept.status == 1)
                .order_by(Dept.sort.asc(), Dept.id.asc())
            )
        ).all()
    )
    roles = list(
        (
            await session.scalars(
                select(Role)
                .where(Role.tenant_id == actor.tenant_id, Role.delete_time.is_(None), Role.status == 1)
                .order_by(Role.sort.asc(), Role.id.asc())
            )
        ).all()
    )
    users = list(
        (
            await session.scalars(
                select(User)
                .where(User.tenant_id == actor.tenant_id, User.delete_time.is_(None), User.status == 1)
                .order_by(User.id.asc())
                .limit(200)
            )
        ).all()
    )
    return NoticeOptionsOut(
        depts=[{"id": row.id, "name": row.name, "parent_id": row.parent_id} for row in depts],
        roles=[{"id": row.id, "name": row.name, "code": row.code} for row in roles],
        users=[{"id": row.id, "username": row.username, "nickname": row.nickname} for row in users],
    )


async def search_users(
    session: AsyncSession,
    actor: UserContext,
    keyword: str | None = None,
    ids: list[int] | None = None,
) -> list[dict[str, object]]:
    statement = select(User).where(
        User.tenant_id == actor.tenant_id,
        User.delete_time.is_(None),
    )
    if ids:
        statement = statement.where(User.id.in_(ids))
    else:
        statement = statement.where(User.status == 1)
        if keyword:
            value = f"%{keyword.strip()}%"
            statement = statement.where(User.username.like(value) | User.nickname.like(value))
    rows = (await session.scalars(statement.order_by(User.id.asc()).limit(50))).all()
    return [
        {"id": row.id, "username": row.username, "nickname": row.nickname, "status": row.status}
        for row in rows
    ]


async def _recipient_conditions(session: AsyncSession, notice: Notice) -> list[Any]:
    """把“收件人集合”下推成 SQL 谓词。

    原实现先取全部收件人 id 再在 Python 里做差集与切片：全租户公告在万人规模下
    每次翻页都要把上万个 id 和全部已读记录搬进内存。
    """
    base: list[Any] = [User.tenant_id == notice.tenant_id, User.delete_time.is_(None)]
    if notice.scope == 0:
        return [*base, User.status == 1]

    targets = list(
        (await session.scalars(select(NoticeTarget).where(NoticeTarget.notice_id == notice.id))).all()
    )
    dept_ids = [item.target_id for item in targets if item.target_type == "dept"]
    role_ids = [item.target_id for item in targets if item.target_type == "role"]
    user_ids = {item.target_id for item in targets if item.target_type == "user"}

    alternatives: list[Any] = []
    if user_ids:
        alternatives.append(User.id.in_(user_ids))
    if dept_ids:
        alternatives.append(
            User.id.in_(
                select(UserDept.user_id).where(
                    UserDept.tenant_id == notice.tenant_id,
                    UserDept.dept_id.in_(dept_ids),
                )
            )
        )
    if role_ids:
        alternatives.append(
            User.id.in_(
                select(UserRole.user_id).where(
                    UserRole.tenant_id == notice.tenant_id,
                    UserRole.role_id.in_(role_ids),
                )
            )
        )
    if not alternatives:
        return [*base, User.id.is_(None)]
    return [*base, or_(*alternatives)]


async def report(
    session: AsyncSession,
    actor: UserContext,
    notice_id: int,
    *,
    view: str = "read",
    page: int = 1,
    limit: int = 15,
) -> NoticeReportOut:
    notice = await _get_row(session, actor, notice_id)
    conditions = await _recipient_conditions(session, notice)
    offset = (max(1, page) - 1) * max(1, limit)

    total = int(await session.scalar(count_query(select(User.id).where(*conditions), User.id)) or 0)
    read_total = int(
        await session.scalar(
            select(func.count())
            .select_from(NoticeRead)
            .join(User, User.id == NoticeRead.user_id)
            .where(NoticeRead.notice_id == notice_id, *conditions)
        )
        or 0
    )

    rows: list[NoticeReportRow] = []
    if view == "unread":
        read_exists = (
            select(NoticeRead.user_id)
            .where(NoticeRead.notice_id == notice_id, NoticeRead.user_id == User.id)
            .exists()
        )
        unread_rows = (
            await session.execute(
                select(User.id, User.username, User.nickname)
                .where(*conditions, ~read_exists)
                .order_by(User.id.asc())
                .offset(offset)
                .limit(max(1, limit))
            )
        ).all()
        rows = [
            NoticeReportRow(user_id=row.id, username=row.username, nickname=row.nickname, read_time=None)
            for row in unread_rows
        ]
    else:
        read_rows = (
            await session.execute(
                select(NoticeRead.user_id, NoticeRead.read_time, User.username, User.nickname)
                .join(User, User.id == NoticeRead.user_id)
                .where(NoticeRead.notice_id == notice_id, *conditions)
                .order_by(NoticeRead.read_time.desc(), NoticeRead.user_id.asc())
                .offset(offset)
                .limit(max(1, limit))
            )
        ).all()
        rows = [
            NoticeReportRow(
                user_id=row.user_id,
                username=row.username,
                nickname=row.nickname,
                read_time=row.read_time,
            )
            for row in read_rows
        ]

    return NoticeReportOut(
        notice={"id": notice.id, "title": notice.title, "scope": notice.scope},
        total=total,
        read=read_total,
        unread=max(0, total - read_total),
        list=rows,
    )


def _my_statement(actor: UserContext) -> Select[Notice]:
    return select(Notice).where(
        Notice.tenant_id == actor.tenant_id,
        Notice.delete_time.is_(None),
        *_published_clause(),
        _visible_clause(actor),
    )


async def my_notices(
    session: AsyncSession,
    actor: UserContext,
    *,
    page: int = 1,
    limit: int = 15,
    only_unread: bool = False,
) -> tuple[int, list[NoticeMyOut]]:
    statement = _my_statement(actor)
    if only_unread:
        statement = statement.where(~_read_exists(actor))
    total = int(await session.scalar(count_query(statement)) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by(Notice.pinned.desc(), Notice.publish_at.desc(), Notice.id.desc())
                .offset((page - 1) * limit)
                .limit(limit)
            )
        ).all()
    )
    ids = [row.id for row in rows]
    read_ids = (
        set(
            (
                await session.scalars(
                    select(NoticeRead.notice_id).where(
                        NoticeRead.user_id == actor.id,
                        NoticeRead.notice_id.in_(ids),
                    )
                )
            ).all()
        )
        if ids
        else set()
    )
    return total, [
        NoticeMyOut(
            id=row.id,
            type=row.type,
            level=row.level,
            title=row.title,
            summary=row.summary,
            content=row.content,
            pinned=row.pinned,
            publish_at=row.publish_at,
            expire_at=row.expire_at,
            is_read=row.id in read_ids,
            create_time=row.create_time,
        )
        for row in rows
    ]


def _read_exists(actor: UserContext) -> ColumnElement[bool]:
    """未读判定用 ``NOT EXISTS``：``NOT IN (子查询)`` 在 MySQL 上会物化整张已读表。"""
    return (
        select(NoticeRead.notice_id)
        .where(NoticeRead.notice_id == Notice.id, NoticeRead.user_id == actor.id)
        .correlate(Notice)
        .exists()
    )


async def unread_count(session: AsyncSession, actor: UserContext) -> int:
    statement = _my_statement(actor).where(~_read_exists(actor))
    return int(await session.scalar(count_query(statement, Notice.id)) or 0)


async def mark_read(session: AsyncSession, actor: UserContext, notice_id: int) -> bool:
    exists = await session.scalar(
        select(Notice.id).where(
            Notice.id == notice_id,
            Notice.tenant_id == actor.tenant_id,
            Notice.delete_time.is_(None),
            *_published_clause(),
            _visible_clause(actor),
        )
    )
    if exists is None:
        raise NotFound("通知公告不存在或已过期")
    already = await session.scalar(
        select(NoticeRead.id).where(NoticeRead.notice_id == notice_id, NoticeRead.user_id == actor.id)
    )
    if already:
        return False
    try:
        async with session.begin_nested():
            session.add(NoticeRead(notice_id=notice_id, user_id=actor.id, read_time=now()))
            await session.flush()
    except IntegrityError:
        # 并发重复标记：唯一键兜底，按「已读」返回。
        return False
    # 原子自增：读-改-写在并发标记已读时会丢计数。
    await session.execute(
        update(Notice).where(Notice.id == notice_id).values(read_count=Notice.read_count + 1)
    )
    await session.commit()
    return True


async def mark_all_read(session: AsyncSession, actor: UserContext) -> int:
    """一次点击把“我能看到且未读”的公告全部标记已读。

    原来会 SELECT 全部公告（含正文 TEXT）、逐行 INSERT 并读改写 read_count；
    万人租户一次请求就是上万条语句。这里改成“取 id → 批量 INSERT → 一条 UPDATE”。
    """
    read_ids = select(NoticeRead.notice_id).where(NoticeRead.user_id == actor.id)
    notice_ids = list(
        (
            await session.scalars(
                _my_statement(actor)
                .with_only_columns(Notice.id)
                .where(Notice.id.not_in(read_ids))
            )
        ).all()
    )
    if not notice_ids:
        return 0
    timestamp = now()
    await session.execute(
        insert(NoticeRead),
        [
            {"notice_id": notice_id, "user_id": actor.id, "read_time": timestamp}
            for notice_id in notice_ids
        ],
    )
    await session.execute(
        update(Notice)
        .where(Notice.id.in_(notice_ids))
        .values(read_count=Notice.read_count + 1)
    )
    await session.commit()
    return len(notice_ids)
