"""S3 兼容对象存储驱动（AWS / MinIO / OSS 与 COS 的 S3 端点）。

两条设计选择，和 ``services/mail.py`` 同一个理由：

* **用同步的 boto3 + ``asyncio.to_thread``**，不引入 ``aioboto3``/``aiobotocore``。
  客户端对象在模块级缓存（建连与凭据解析比一次 PUT 还贵），每次请求只做 IO。
* **桶内键空间自己管**：``CCCMS_S3_PREFIX`` 是可选前缀，软删除的副本放
  ``_quarantine/`` 前缀下，配合桶生命周期规则清理（本地驱动是移到静态目录之外）。

已知限制：超过 5GB 的对象需要分片复制，``quarantine``/``restore`` 走的是单次
``copy_object``，超限会失败——真要支持得改成 multipart copy。
"""

from __future__ import annotations

import asyncio
import tempfile
from collections.abc import AsyncIterator
from typing import Any

import boto3
from botocore.config import Config
from botocore.exceptions import BotoCoreError, ClientError

from cccms.config import Settings
from cccms.core.errors import NotFound, ServiceUnavailable

READ_CHUNK = 1024 * 1024
# 8MB 以内留在内存，超过自动落到临时文件：单文件上限 2GB，不能整个读进内存。
SPOOL_MEMORY = 8 * 1024 * 1024
QUARANTINE_PREFIX = "_quarantine"
_MISSING_CODES = {"404", "NoSuchKey", "NotFound"}

# 客户端按连接参数缓存：boto3.client() 每次都会做签名器/凭据链初始化。
_CLIENTS: dict[tuple[str, str, str, str], Any] = {}


def _client(
    *, endpoint: str, region: str, access_key: str, secret_key: str
) -> Any:
    cache_key = (endpoint, region, access_key, secret_key)
    cached = _CLIENTS.get(cache_key)
    if cached is None:
        cached = boto3.client(
            "s3",
            endpoint_url=endpoint or None,
            region_name=region,
            aws_access_key_id=access_key,
            aws_secret_access_key=secret_key,
            config=Config(
                signature_version="s3v4",
                # MinIO / 自建端点通常没有通配 DNS，必须 path-style。
                s3={"addressing_style": "path"},
                retries={"max_attempts": 2, "mode": "standard"},
                connect_timeout=5,
                read_timeout=30,
            ),
        )
        _CLIENTS[cache_key] = cached
    return cached


def _is_missing(exc: ClientError) -> bool:
    return str(exc.response.get("Error", {}).get("Code", "")) in _MISSING_CODES


class S3StorageDriver:
    name = "s3"

    def __init__(
        self,
        *,
        bucket: str,
        endpoint: str = "",
        region: str = "us-east-1",
        access_key: str = "",
        secret_key: str = "",
        prefix: str = "",
        url_prefix: str = "/storage",
    ) -> None:
        self._bucket = bucket
        self._prefix = prefix.strip("/")
        self._url_prefix = url_prefix.rstrip("/") or "/storage"
        self._client = _client(
            endpoint=endpoint, region=region, access_key=access_key, secret_key=secret_key
        )

    @classmethod
    def from_settings(cls, settings: Settings) -> S3StorageDriver:
        return cls(
            bucket=settings.s3_bucket,
            endpoint=settings.s3_endpoint,
            region=settings.s3_region,
            access_key=settings.s3_access_key,
            secret_key=settings.s3_secret_key,
            prefix=settings.s3_prefix,
            url_prefix=settings.storage_url_prefix,
        )

    # --- 键空间 -----------------------------------------------------------
    def _key(self, relative: str) -> str:
        return f"{self._prefix}/{relative}" if self._prefix else relative

    def _quarantine_key(self, relative: str) -> str:
        prefix = f"{self._prefix}/" if self._prefix else ""
        return f"{prefix}{QUARANTINE_PREFIX}/{relative}"

    # --- 通用契约 ---------------------------------------------------------
    async def save(self, relative: str, chunks: AsyncIterator[bytes]) -> int:
        total = 0
        with tempfile.SpooledTemporaryFile(max_size=SPOOL_MEMORY) as spool:
            try:
                async for chunk in chunks:
                    spool.write(chunk)
                    total += len(chunk)
                spool.seek(0)
                await asyncio.to_thread(self._upload, spool, relative)
            except Exception:
                # 和 local 驱动一致：不允许留下半截对象。
                await self.delete(relative)
                raise
        return total

    def _upload(self, fileobj: Any, relative: str) -> None:
        try:
            self._client.upload_fileobj(fileobj, self._bucket, self._key(relative))
        except (BotoCoreError, ClientError) as exc:
            raise ServiceUnavailable("对象存储写入失败", message_key=None) from exc

    async def open(
        self, relative: str, *, offset: int = 0, length: int | None = None
    ) -> AsyncIterator[bytes]:
        body = await asyncio.to_thread(self._get_object, relative, offset, length)
        try:
            while True:
                data: bytes = await asyncio.to_thread(body.read, READ_CHUNK)
                if not data:
                    return
                yield data
        finally:
            await asyncio.to_thread(body.close)

    def _get_object(self, relative: str, offset: int, length: int | None) -> Any:
        params: dict[str, Any] = {"Bucket": self._bucket, "Key": self._key(relative)}
        if offset or length is not None:
            end = "" if length is None else str(offset + length - 1)
            params["Range"] = f"bytes={offset}-{end}"
        try:
            return self._client.get_object(**params)["Body"]
        except ClientError as exc:
            if _is_missing(exc):
                raise NotFound("附件不存在") from exc
            raise ServiceUnavailable("对象存储读取失败", message_key=None) from exc
        except BotoCoreError as exc:
            raise ServiceUnavailable("对象存储读取失败", message_key=None) from exc

    async def delete(self, relative: str) -> None:
        await asyncio.to_thread(self._delete_keys, [self._key(relative)])

    async def quarantine(self, relative: str) -> None:
        source, target = self._key(relative), self._quarantine_key(relative)
        await asyncio.to_thread(self._move, source, target)

    async def restore(self, relative: str) -> None:
        source, target = self._quarantine_key(relative), self._key(relative)
        await asyncio.to_thread(self._move, source, target)

    async def purge(self, relative: str) -> None:
        await asyncio.to_thread(
            self._delete_keys, [self._key(relative), self._quarantine_key(relative)]
        )

    def _move(self, source: str, target: str) -> None:
        try:
            self._client.copy_object(
                Bucket=self._bucket,
                CopySource={"Bucket": self._bucket, "Key": source},
                Key=target,
            )
        except ClientError as exc:
            if _is_missing(exc):
                # 源不在（重复删除、已还原过）视为成功，与 local 驱动的静默返回一致。
                return
            raise ServiceUnavailable("对象存储移动失败", message_key=None) from exc
        except BotoCoreError as exc:
            raise ServiceUnavailable("对象存储移动失败", message_key=None) from exc
        self._delete_keys([source])

    def _delete_keys(self, keys: list[str]) -> None:
        try:
            for key in keys:
                self._client.delete_object(Bucket=self._bucket, Key=key)
        except (BotoCoreError, ClientError) as exc:
            raise ServiceUnavailable("对象存储删除失败", message_key=None) from exc

    def exists(self, relative: str) -> bool:
        try:
            self._client.head_object(Bucket=self._bucket, Key=self._key(relative))
        except ClientError as exc:
            if _is_missing(exc):
                return False
            raise ServiceUnavailable("对象存储不可用", message_key=None) from exc
        except BotoCoreError as exc:
            raise ServiceUnavailable("对象存储不可用", message_key=None) from exc
        return True

    def size(self, relative: str) -> int:
        try:
            head = self._client.head_object(Bucket=self._bucket, Key=self._key(relative))
        except ClientError as exc:
            if _is_missing(exc):
                raise NotFound("附件不存在") from exc
            raise ServiceUnavailable("对象存储不可用", message_key=None) from exc
        except BotoCoreError as exc:
            raise ServiceUnavailable("对象存储不可用", message_key=None) from exc
        return int(head["ContentLength"])

    def url(self, relative: str) -> str:
        # 站内地址：先过 /storage 的鉴权，再 302 到预签名 URL。
        return f"{self._url_prefix}/{relative}"

    def presign_url(self, relative: str, ttl: int) -> str | None:
        try:
            signed: str = self._client.generate_presigned_url(
                "get_object",
                Params={"Bucket": self._bucket, "Key": self._key(relative)},
                ExpiresIn=ttl,
            )
        except (BotoCoreError, ClientError) as exc:
            raise ServiceUnavailable("对象存储签名失败", message_key=None) from exc
        return signed


__all__ = ["QUARANTINE_PREFIX", "READ_CHUNK", "S3StorageDriver"]
