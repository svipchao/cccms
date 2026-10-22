from __future__ import annotations

from datetime import datetime
from zoneinfo import ZoneInfo

LOCAL_TZ = ZoneInfo("Asia/Shanghai")
DATETIME_FORMAT = "%Y-%m-%d %H:%M:%S"


def now() -> datetime:
    """Naive local time.

    The whole product (DB, API, UI filters) uses Asia/Shanghai naive datetimes.
    Mixing UTC and local time is the easiest way to introduce an 8-hour bug.
    """
    return datetime.now(LOCAL_TZ).replace(tzinfo=None)


def today_start() -> datetime:
    current = now()
    return current.replace(hour=0, minute=0, second=0, microsecond=0)


def fmt(value: datetime | None) -> str | None:
    if value is None:
        return None
    return value.strftime(DATETIME_FORMAT)


def parse(value: str | datetime | None) -> datetime | None:
    if value is None or isinstance(value, datetime):
        return value
    text = value.strip()
    if not text:
        return None
    for pattern in (DATETIME_FORMAT, "%Y-%m-%d", "%Y-%m-%dT%H:%M:%S"):
        try:
            # 产品统一使用 Asia/Shanghai naive 时间(见 now()), 不引入时区偏移。
            return datetime.strptime(text, pattern)  # noqa: DTZ007
        except ValueError:
            continue
    return None


def timestamp() -> int:
    return int(now().timestamp())
