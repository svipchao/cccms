"""口令历史：拒绝「改回旧口令」（S-02）。

只留哈希，不留明文。三个改密入口（自助改密、管理员重置、找回密码）都要在
写入新哈希**之前**调用 :func:`ensure_not_reused`，在写入之后调用 :func:`remember`。

两者都不 commit：改密本身只应有一次事务提交，调用方负责。
"""

from __future__ import annotations

from collections.abc import Sequence

from sqlalchemy import delete, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.config import get_settings
from cccms.core.codes import BizCode
from cccms.core.errors import ValidationFailed
from cccms.core.security import verify_password_async
from cccms.models.user import PasswordHistory


def history_limit() -> int:
    """当前生效的历史份数；``<= 0`` 表示关闭。"""
    return max(0, int(get_settings().password_history))


async def _matches_any(password: str, hashes: Sequence[str]) -> bool:
    for item in hashes:
        if item and await verify_password_async(password, item):
            return True
    return False


async def ensure_not_reused(
    session: AsyncSession, *, tenant_id: int, user_id: int, password: str
) -> None:
    """新口令不得与最近 N 份历史相同。

    「与当前口令相同」由调用方先单独拦一次（提示更准确）；这里查的是更早的几份。
    """
    limit = history_limit()
    if limit <= 0:
        return
    result = await session.execute(
        select(PasswordHistory.password_hash)
        .where(PasswordHistory.tenant_id == tenant_id, PasswordHistory.user_id == user_id)
        .order_by(PasswordHistory.id.desc())
        .limit(limit)
    )
    if await _matches_any(password, list(result.scalars().all())):
        raise ValidationFailed(
            f"新密码不能与最近 {limit} 次使用过的密码相同",
            code=BizCode.PASSWORD_POLICY,
            message_key="error.password_policy",
        )


async def remember(
    session: AsyncSession, *, tenant_id: int, user_id: int, password_hash: str
) -> None:
    """记下被替换掉的旧哈希，并裁掉超出保留份数的老行。"""
    limit = history_limit()
    if limit <= 0 or not password_hash:
        return
    session.add(
        PasswordHistory(tenant_id=tenant_id, user_id=user_id, password_hash=password_hash)
    )
    await session.flush()
    stale = (
        (
            await session.execute(
                select(PasswordHistory.id)
                .where(PasswordHistory.tenant_id == tenant_id, PasswordHistory.user_id == user_id)
                .order_by(PasswordHistory.id.desc())
                .offset(limit)
            )
        )
        .scalars()
        .all()
    )
    if stale:
        await session.execute(
            delete(PasswordHistory).where(
                PasswordHistory.tenant_id == tenant_id, PasswordHistory.id.in_(list(stale))
            )
        )
