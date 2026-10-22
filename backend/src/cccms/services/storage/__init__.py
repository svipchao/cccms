"""存储驱动注册表。

当前只注册 ``local``。``CCCMS_STORAGE_DRIVER`` 取其它值会在**启动校验**阶段被拒绝
（``Settings.validate_storage()``，取值清单是 ``config.IMPLEMENTED_STORAGE_DRIVERS``），
不会等到用户传第一个附件才 500。S3 的落地顺序见 docs/16 §九。

注册表与 ``config.IMPLEMENTED_STORAGE_DRIVERS`` 必须一致，`tests/test_storage.py` 锁住这条。
"""

from __future__ import annotations

from collections.abc import Callable

from cccms.config import Settings, get_settings
from cccms.core.errors import ServiceUnavailable

from .base import StorageDriver
from .local import LocalStorageDriver, safe_join
from .s3 import S3StorageDriver

_BUILDERS: dict[str, Callable[[Settings], StorageDriver]] = {
    "local": LocalStorageDriver.from_settings,
    "s3": S3StorageDriver.from_settings,
}


def implemented_drivers() -> tuple[str, ...]:
    return tuple(sorted(_BUILDERS))


def get_driver(settings: Settings | None = None) -> StorageDriver:
    """按配置取当前驱动；未实现的取值直接抛错，不静默退回 local。"""
    cfg = settings or get_settings()
    name = cfg.storage_driver.strip().lower()
    builder = _BUILDERS.get(name)
    if builder is None:
        raise ServiceUnavailable(f"存储驱动未实现：{name}", message_key=None)
    return builder(cfg)


def local_driver(settings: Settings | None = None) -> LocalStorageDriver:
    """本地专属能力（隔离区、分片暂存）的入口。

    非 local 驱动下抛错而不是退回本地路径：那会把附件写到"当前节点"，多副本时别人读不到。
    """
    driver = get_driver(settings)
    if not isinstance(driver, LocalStorageDriver):
        raise ServiceUnavailable("当前存储驱动不是 local，路径型操作不可用", message_key=None)
    return driver


__all__ = [
    "LocalStorageDriver",
    "S3StorageDriver",
    "StorageDriver",
    "get_driver",
    "implemented_drivers",
    "local_driver",
    "safe_join",
]
