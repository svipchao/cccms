"""S3 驱动：用 moto 在内存里跑，不需要 Docker / MinIO。

CI 与本地都没有对象存储，但驱动的行为不能靠人工点：Range 读取、隔离区搬迁、
半截对象清理、预签名签发，这几条都是"错了才会在线上发现"的地方。
"""

from __future__ import annotations

from typing import Any

import boto3
import pytest
from moto import mock_aws

from cccms.core.errors import NotFound
from cccms.services.storage import s3 as s3_module
from cccms.services.storage.s3 import S3StorageDriver

BUCKET = "cccms-test"
PREFIX = "files"


@pytest.fixture
def driver() -> Any:
    # moto 的状态是进程内全局的：每个用例一个干净的桶，并清掉客户端缓存，
    # 否则上一个用例的 client 会带着旧端点与旧桶进来。
    s3_module._CLIENTS.clear()
    with mock_aws():
        boto3.client("s3", region_name="us-east-1").create_bucket(Bucket=BUCKET)
        yield S3StorageDriver(
            bucket=BUCKET,
            region="us-east-1",
            access_key="test-key",
            secret_key="test-secret",
            prefix=PREFIX,
        )
    s3_module._CLIENTS.clear()


def _keys() -> list[str]:
    client = boto3.client("s3", region_name="us-east-1")
    listing = client.list_objects_v2(Bucket=BUCKET)
    return [item["Key"] for item in listing.get("Contents", [])]


async def _collect(stream: Any) -> bytes:
    return b"".join([chunk async for chunk in stream])


async def test_save_open_size_exists(driver: S3StorageDriver) -> None:
    async def _body() -> Any:
        yield b"hello "
        yield b"world"

    written = await driver.save("2026/10/22/a.txt", _body())

    assert written == 11
    assert driver.exists("2026/10/22/a.txt") is True
    assert driver.size("2026/10/22/a.txt") == 11
    assert await _collect(driver.open("2026/10/22/a.txt")) == b"hello world"
    # 前缀是桶内的键空间，不是站内 URL 的一部分。
    assert _keys() == [f"{PREFIX}/2026/10/22/a.txt"]


async def test_open_supports_range(driver: S3StorageDriver) -> None:
    async def _body() -> Any:
        yield b"0123456789"

    await driver.save("a.bin", _body())
    assert await _collect(driver.open("a.bin", offset=3, length=4)) == b"3456"
    assert await _collect(driver.open("a.bin", offset=8, length=100)) == b"89"
    assert await _collect(driver.open("a.bin", offset=4)) == b"456789"


async def test_save_failure_leaves_no_object(driver: S3StorageDriver) -> None:
    async def _body() -> Any:
        yield b"partial"
        raise RuntimeError("client went away")

    with pytest.raises(RuntimeError):
        await driver.save("bad.txt", _body())
    assert driver.exists("bad.txt") is False
    assert _keys() == []


async def test_delete_is_idempotent(driver: S3StorageDriver) -> None:
    async def _body() -> Any:
        yield b"x"

    await driver.save("a.txt", _body())
    await driver.delete("a.txt")
    await driver.delete("a.txt")
    assert driver.exists("a.txt") is False


async def test_quarantine_restore_purge(driver: S3StorageDriver) -> None:
    async def _body() -> Any:
        yield b"payload"

    await driver.save("2026/10/22/a.txt", _body())
    await driver.quarantine("2026/10/22/a.txt")

    assert driver.exists("2026/10/22/a.txt") is False
    # 隔离副本必须落到独立前缀下，才能用桶生命周期规则兜底清理。
    assert _keys() == [f"{PREFIX}/_quarantine/2026/10/22/a.txt"]

    await driver.restore("2026/10/22/a.txt")
    assert driver.exists("2026/10/22/a.txt") is True
    assert await _collect(driver.open("2026/10/22/a.txt")) == b"payload"

    await driver.quarantine("2026/10/22/a.txt")
    await driver.purge("2026/10/22/a.txt")
    assert _keys() == []


async def test_quarantine_missing_object_is_noop(driver: S3StorageDriver) -> None:
    # 幂等：重复删除、已还原过，都不该把请求打成 500。
    await driver.quarantine("never.txt")
    await driver.restore("never.txt")
    await driver.purge("never.txt")
    assert _keys() == []


async def test_missing_object_reports_not_found(driver: S3StorageDriver) -> None:
    assert driver.exists("gone.txt") is False
    with pytest.raises(NotFound):
        driver.size("gone.txt")
    with pytest.raises(NotFound):
        await _collect(driver.open("gone.txt"))


def test_url_and_presign(driver: S3StorageDriver) -> None:
    assert driver.url("2026/10/22/a.png") == "/storage/2026/10/22/a.png"

    signed = driver.presign_url("2026/10/22/a.png", 300)
    assert signed is not None
    assert "X-Amz-Signature=" in signed
    assert "X-Amz-Expires=300" in signed
    assert f"{PREFIX}/2026/10/22/a.png" in signed
