from __future__ import annotations

import asyncio
import base64
import hashlib
import hmac
import secrets
import threading
import time
from dataclasses import dataclass
from typing import Any

import jwt
from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerifyMismatchError
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from cccms.config import get_settings
from cccms.core.codes import BizCode
from cccms.core.errors import Unauthorized, ValidationFailed

_hasher = PasswordHasher(time_cost=3, memory_cost=65536, parallelism=4, hash_len=32, salt_len=16)

WEAK_PASSWORDS = frozenset(
    {
        "123456",
        "12345678",
        "123456789",
        "1234567890",
        "111111",
        "000000",
        "888888",
        "666666",
        "abc123",
        "qwerty",
        "qwerty123",
        "password",
        "password1",
        "admin",
        "admin123",
        "admin888",
        "root",
        "root123",
        "cccms",
        "cccms123",
        "woaini",
        "woaini1314",
        "5201314",
        "a123456",
        "1qaz2wsx",
        "qazwsx",
        "iloveyou",
        "letmein",
        "welcome",
        "monkey",
        "dragon",
        "sunshine",
        "princess",
        "football",
        "baseball",
        "master",
        "shadow",
        "superman",
        "michael",
        "jennifer",
        "trustno1",
        "passw0rd",
        "p@ssw0rd",
        "changeme",
        "default",
        "test123",
        "test1234",
        "guest",
        "service",
        "system",
        "manager",
        "operator",
        "support",
        "company",
        "corporate",
        "department",
        "employee",
        "hr123456",
        "finance",
        "sales",
        "marketing",
        "office",
        "china",
        "beijing",
        "shanghai",
        "shenzhen",
        "guangzhou",
        "hangzhou",
        "chengdu",
        "wuhan",
        "nanjing",
        "tianjin",
        "chongqing",
        "123123",
        "654321",
        "abcdef",
        "abcabc",
        "a1b2c3",
        "1q2w3e",
        "zxcvbn",
        "asdfgh",
        "qwertyuiop",
        "112233",
        "121212",
        "131313",
        "147258",
        "159357",
        "258258",
        "369369",
        "741852",
        "852963",
        "963852",
        "000111",
        "111222",
        "222333",
        "333444",
    }
)


# Argon2 每次约 64MB 内存，限制同时计算数，避免并发登录把内存打满。
_HASH_SLOTS = threading.BoundedSemaphore(4)


def hash_password(password: str) -> str:
    with _HASH_SLOTS:
        return _hasher.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    try:
        with _HASH_SLOTS:
            return _hasher.verify(password_hash, password)
    except (VerifyMismatchError, InvalidHashError, ValueError):
        return False


async def hash_password_async(password: str) -> str:
    return await asyncio.to_thread(hash_password, password)


async def verify_password_async(password: str, password_hash: str) -> bool:
    return await asyncio.to_thread(verify_password, password, password_hash)


def needs_rehash(password_hash: str) -> bool:
    try:
        return _hasher.check_needs_rehash(password_hash)
    except (InvalidHashError, ValueError):
        return True


def _password_policy_error(message: str) -> ValidationFailed:
    return ValidationFailed(message, code=BizCode.PASSWORD_POLICY, message_key="error.password_policy")


def validate_password(password: str, *, username: str = "", nickname: str = "", email: str = "") -> None:
    if len(password) < 8:
        raise _password_policy_error("密码长度不能少于 8 位")
    if len(password) > 128:
        raise _password_policy_error("密码长度不能超过 128 位")
    if password.lower() in WEAK_PASSWORDS:
        raise _password_policy_error("密码过于简单，请更换")
    identity = [item.strip().lower() for item in (username, nickname, email) if item and item.strip()]
    lowered = password.lower()
    for item in identity:
        if lowered == item:
            raise _password_policy_error("密码不能与账号信息相同")
        if len(item) >= 4 and item in lowered:
            raise _password_policy_error("密码不能包含账号信息")
    categories = 0
    if any(ch.islower() for ch in password):
        categories += 1
    if any(ch.isupper() for ch in password):
        categories += 1
    if any(ch.isdigit() for ch in password):
        categories += 1
    if any(not ch.isalnum() for ch in password):
        categories += 1
    if categories < 2:
        raise _password_policy_error("密码需包含大写字母、小写字母、数字、符号中的至少两类")


def new_jti() -> str:
    return secrets.token_hex(16)


def new_opaque_token(length: int = 32) -> str:
    return secrets.token_urlsafe(length)


def create_access_token(
    *,
    user_id: int,
    tenant_id: int,
    home_tenant_id: int | None = None,
    jti: str | None = None,
    ttl: int | None = None,
    iat: int | None = None,
    extra: dict[str, Any] | None = None,
) -> tuple[str, dict[str, Any]]:
    settings = get_settings()
    issued_at = int(time.time()) if iat is None else int(iat)
    lifetime = settings.token_ttl if ttl is None else int(ttl)
    payload: dict[str, Any] = {
        "iss": "cccms",
        "sub": str(user_id),
        "tid": int(tenant_id),
        "hid": int(home_tenant_id if home_tenant_id is not None else tenant_id),
        "iat": issued_at,
        "nbf": issued_at,
        "exp": issued_at + lifetime,
        "jti": jti or new_jti(),
    }
    if extra:
        payload.update(extra)
    token = jwt.encode(payload, settings.jwt_secret, algorithm="HS256")
    return token, payload


@dataclass(frozen=True, slots=True)
class TokenPayload:
    user_id: int
    tenant_id: int
    home_tenant_id: int
    jti: str
    iat: int
    exp: int

    @property
    def remaining(self) -> int:
        return max(0, self.exp - int(time.time()))


def decode_access_token(token: str) -> TokenPayload:
    settings = get_settings()
    try:
        payload = jwt.decode(
            token,
            settings.jwt_secret,
            algorithms=["HS256"],
            issuer="cccms",
            options={"require": ["exp", "iat", "nbf", "sub", "jti"]},
        )
    except jwt.ExpiredSignatureError as exc:
        raise Unauthorized(
            "登录凭证已过期", code=BizCode.TOKEN_EXPIRED, message_key="error.token_expired"
        ) from exc
    except jwt.ImmatureSignatureError as exc:
        raise Unauthorized("登录凭证尚未生效") from exc
    except jwt.InvalidTokenError as exc:
        raise Unauthorized("登录凭证无效") from exc

    try:
        user_id = int(str(payload["sub"]))
        tenant_id = int(payload.get("tid", 0))
        home_tenant_id = int(payload.get("hid", tenant_id))
        jti = str(payload["jti"])
        iat = int(payload["iat"])
        exp = int(payload["exp"])
    except (KeyError, TypeError, ValueError) as exc:
        raise Unauthorized("登录凭证无效") from exc
    return TokenPayload(
        user_id=user_id,
        tenant_id=tenant_id,
        home_tenant_id=home_tenant_id,
        jti=jti,
        iat=iat,
        exp=exp,
    )


def should_renew(payload: TokenPayload, ttl: int | None = None) -> bool:
    settings = get_settings()
    lifetime = settings.token_ttl if ttl is None else ttl
    threshold = max(1, (lifetime + 2) // 3)
    return 0 < payload.remaining < threshold


class FieldCipher:
    """AES-256-GCM compatible with the existing frontend crypto helper.

    Wire format: base64(iv[12] || tag[16] || ciphertext).
    Key: sha256(CCCMS_DATA_ENCRYPT_KEY) -> 32 raw bytes.
    """

    def __init__(self, raw_key: str | None = None) -> None:
        settings = get_settings()
        self._raw = raw_key if raw_key is not None else settings.data_encrypt_key
        self._key = hashlib.sha256(self._raw.encode("utf-8")).digest()
        self._aes = AESGCM(self._key)

    @property
    def configured(self) -> bool:
        return bool(self._raw)

    def encrypt(self, plaintext: str) -> str:
        iv = secrets.token_bytes(12)
        sealed = self._aes.encrypt(iv, plaintext.encode("utf-8"), None)
        ciphertext, tag = sealed[:-16], sealed[-16:]
        return base64.b64encode(iv + tag + ciphertext).decode("ascii")

    def decrypt(self, encoded: str) -> str:
        raw = base64.b64decode(encoded, validate=True)
        if len(raw) < 28:
            raise ValueError("ciphertext too short")
        iv, tag, ciphertext = raw[:12], raw[12:28], raw[28:]
        return self._aes.decrypt(iv, ciphertext + tag, None).decode("utf-8")


_cipher: FieldCipher | None = None


def get_cipher() -> FieldCipher:
    global _cipher
    if _cipher is None:
        _cipher = FieldCipher()
    return _cipher


def constant_time_equals(left: str, right: str) -> bool:
    return hmac.compare_digest(left.encode("utf-8"), right.encode("utf-8"))
