"""Application configuration.

All runtime configuration is environment driven.  The settings object is cached
for the lifetime of the process; changing ``.env`` requires a restart.
"""

from __future__ import annotations

import json
import os
import secrets
import time
from collections.abc import Iterator
from contextlib import contextmanager, suppress
from functools import lru_cache
from pathlib import Path
from typing import Annotated, Literal
from urllib.parse import quote_plus

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict

# 列表字段在 .env 里按逗号分隔书写；必须显式关闭 pydantic-settings 的 JSON 预解码，
# 否则 "a,b" 会在进入字段校验器之前就抛 JSONDecodeError。
CsvList = Annotated[list[str], NoDecode]

BACKEND_DIR = Path(__file__).resolve().parent.parent.parent
PROJECT_DIR = BACKEND_DIR.parent
DEV_SECRETS_FILE = BACKEND_DIR / ".dev-secrets.env"

# 仅供本地开发使用；生产环境必须显式覆盖（见 Settings.validate_secrets）。
DEFAULT_ADMIN_PASSWORD = "Admin@123456"  # noqa: S105 - 默认哨兵值, 生产启动校验会拒绝

DEV_SECRET_KEYS = (
    "jwt_secret",
    "data_encrypt_key",
    "audit_signing_key",
    "secret_key",
)


def _read_dev_secrets() -> dict[str, str]:
    if not DEV_SECRETS_FILE.is_file():
        return {}
    values: dict[str, str] = {}
    for line in DEV_SECRETS_FILE.read_text(encoding="utf-8").splitlines():
        name, _, value = line.partition("=")
        name = name.strip()
        if name.startswith("CCCMS_"):
            values[name[6:].lower()] = value.strip()
    return values


@contextmanager
def _dev_secret_lock() -> Iterator[None]:
    """Serialize first-boot secret generation across API/scheduler/CLI."""
    lock = DEV_SECRETS_FILE.with_suffix(".lock")
    deadline = time.monotonic() + 10
    handle: int | None = None
    while handle is None:
        try:
            handle = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        except FileExistsError:
            if time.monotonic() >= deadline:
                break
            time.sleep(0.1)
    try:
        yield
    finally:
        if handle is not None:
            os.close(handle)
            lock.unlink(missing_ok=True)


def _write_dev_secrets(values: dict[str, str]) -> None:
    content = "\n".join(f"CCCMS_{name.upper()}={value}" for name, value in values.items()) + "\n"
    temp = DEV_SECRETS_FILE.with_suffix(".tmp")
    temp.write_text(content, encoding="utf-8")
    with suppress(OSError):
        temp.chmod(0o600)
    temp.replace(DEV_SECRETS_FILE)


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="CCCMS_",
        # .env 优先于自动生成的开发密钥文件，显式配置永远覆盖生成值。
        env_file=(DEV_SECRETS_FILE, BACKEND_DIR / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    env: Literal["dev", "test", "prod"] = "dev"
    debug: bool = False
    secret_key: str = ""
    jwt_secret: str = ""
    data_encrypt_key: str = ""
    audit_signing_key: str = ""

    # Database -------------------------------------------------------------
    db_host: str = "127.0.0.1"
    db_port: int = 3306
    db_user: str = "root"
    db_password: str = ""
    db_name: str = "cccms"
    db_pool_size: int = 10
    db_max_overflow: int = 20
    # 池满后最多排队 5 秒：宁可快速失败，也不要把请求挂 30 秒再 500。
    db_pool_timeout: float = 5.0
    db_pool_recycle: int = 1800
    db_echo: bool = False
    db_charset: str = "utf8mb4"

    # Redis ----------------------------------------------------------------
    redis_url: str = "redis://127.0.0.1:6379/0"

    # Cookies / tokens -----------------------------------------------------
    cookie_secure: bool = False
    cookie_domain: str | None = None
    cookie_samesite: Literal["lax", "strict", "none"] = "lax"
    token_cookie_name: str = "cccms_token"  # noqa: S105 - cookie 名称, 非凭据
    csrf_cookie_name: str = "cccms_csrf"
    csrf_header_name: str = "X-CSRF-Token"
    token_ttl: int = 7200
    refresh_threshold: int = 2400
    # 未绑定域名且未显式选择租户时，登录落到该租户（默认平台租户）
    login_default_tenant: str = "platform"

    # CORS -----------------------------------------------------------------
    cors_origins: CsvList = Field(default_factory=list)

    # Proxy ----------------------------------------------------------------
    trusted_proxies: CsvList = Field(default_factory=list)

    # Uploads --------------------------------------------------------------
    storage_driver: Literal["local", "oss", "cos", "qiniu", "s3"] = "local"
    storage_local_root: str = str(PROJECT_DIR / "shared" / "uploads")
    storage_url_prefix: str = "/storage"
    # 导出文件常含日志/业务数据，必须落在静态目录之外，只能经鉴权接口下载。
    export_local_root: str = str(PROJECT_DIR / "shared" / "exports")
    upload_max_mb: int = 10
    upload_chunk_max_mb: int = 2048
    upload_chunk_size: int = 4 * 1024 * 1024
    upload_ext_allow: str = (
        "jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,md,zip,rar,7z,mp3,mp4,webm"
    )

    # Observability --------------------------------------------------------
    metrics_allow_ips: CsvList = Field(default_factory=lambda: ["127.0.0.1", "::1"])
    health_disk_min_percent: float = 5.0
    health_memory_max_percent: float = 90.0
    slow_log_threshold_ms: int = 3000
    log_record_read: bool = False
    log_keep_days: int = 30
    # 导出是重任务：并发过高会把数据库与磁盘拖垮，单用户也不允许无限排队。
    export_max_concurrency: int = 2
    export_max_active_per_user: int = 2
    # 请求级硬超时与请求体上限：没有它们，慢客户端/巨量 JSON 可以吃满 worker。
    request_timeout_seconds: float = 30.0
    request_max_mb: int = 8

    # Rate limits (per minute) ---------------------------------------------
    ratelimit_api_per_min: int = 60
    ratelimit_heavy_per_min: int = 5
    ratelimit_anon_per_min: int = 20
    ratelimit_anon_write_per_min: int = 5

    # Scheduler ------------------------------------------------------------
    scheduler_poll_interval: int = 10
    cron_lock_ttl: int = 3600

    # Online upgrade (local development only) -------------------------------
    upgrade_enable: bool = False
    upgrade_branch: str = "main"
    upgrade_timeout: int = 60

    # Seed -----------------------------------------------------------------
    admin_username: str = "admin"
    admin_password: str = DEFAULT_ADMIN_PASSWORD
    admin_nickname: str = "Administrator"
    admin_email: str = ""

    @field_validator("cors_origins", "trusted_proxies", "metrics_allow_ips", mode="before")
    @classmethod
    def _split_csv(cls, value: object) -> object:
        if isinstance(value, str):
            text = value.strip()
            # 兼容 JSON 数组写法，其余按逗号分隔处理。
            if text.startswith("["):
                with suppress(ValueError):
                    parsed = json.loads(text)
                    if isinstance(parsed, list):
                        return [str(item).strip() for item in parsed if str(item).strip()]
            return [item.strip() for item in text.split(",") if item.strip()]
        return value

    @field_validator("cookie_domain", mode="before")
    @classmethod
    def _empty_to_none(cls, value: object) -> object:
        if isinstance(value, str) and not value.strip():
            return None
        return value

    @property
    def database_url(self) -> str:
        password = quote_plus(self.db_password)
        return (
            f"mysql+asyncmy://{self.db_user}:{password}@{self.db_host}:{self.db_port}/"
            f"{self.db_name}?charset={self.db_charset}"
        )

    @property
    def is_prod(self) -> bool:
        return self.env == "prod"

    @property
    def upload_ext_set(self) -> set[str]:
        return {item.strip().lower() for item in self.upload_ext_allow.split(",") if item.strip()}

    @property
    def storage_root(self) -> Path:
        path = Path(self.storage_local_root)
        if not path.is_absolute():
            path = (BACKEND_DIR / path).resolve()
        return path

    @property
    def export_root(self) -> Path:
        path = Path(self.export_local_root)
        if not path.is_absolute():
            path = (BACKEND_DIR / path).resolve()
        return path

    def validate_secrets(self) -> list[str]:
        problems: list[str] = []
        if not self.jwt_secret or len(self.jwt_secret.encode()) < 32:
            problems.append("CCCMS_JWT_SECRET must be at least 32 bytes")
        if not self.data_encrypt_key:
            problems.append("CCCMS_DATA_ENCRYPT_KEY is required")
        if not self.audit_signing_key:
            problems.append("CCCMS_AUDIT_SIGNING_KEY is required")
        if self.is_prod and not self.cookie_secure:
            problems.append("CCCMS_COOKIE_SECURE must be true in production")
        if self.is_prod:
            problems.extend(self._validate_prod_hardening())
        return problems

    def validate_storage(self) -> list[str]:
        """附件后端目前只有 local 实现，其它取值必须显式失败而不是静默降级。"""
        if self.storage_driver != "local":
            return [
                f"CCCMS_STORAGE_DRIVER={self.storage_driver} 尚未实现；"
                "当前仅支持 local，请勿在配置里声明未接入的存储后端"
            ]
        return []

    def _validate_prod_hardening(self) -> list[str]:
        """生产环境的额外硬门槛：默认凭据、调试开关与 CORS 通配是接管前奏。"""
        problems: list[str] = []
        if DEV_SECRETS_FILE.is_file():
            # env_file 里第一位就是它，且不区分环境：镜像/主机上残留就等于用开发密钥跑生产。
            problems.append(
                f"生产环境检测到开发密钥文件 {DEV_SECRETS_FILE.name}："
                "它会被 env_file 自动读入并顶替正式密钥，请先从镜像与主机移除"
            )
        if self.debug:
            problems.append("CCCMS_DEBUG must be false in production")
        if not self.secret_key or len(self.secret_key.encode()) < 32:
            problems.append("CCCMS_SECRET_KEY must be at least 32 bytes in production")
        if not self.cors_origins:
            problems.append("CCCMS_CORS_ORIGINS must list trusted origins in production")
        elif "*" in self.cors_origins:
            problems.append("CCCMS_CORS_ORIGINS must not contain '*' when credentials are allowed")
        if not self.admin_password or self.admin_password == DEFAULT_ADMIN_PASSWORD:
            problems.append("CCCMS_ADMIN_PASSWORD must be set to a non-default value in production")
        elif len(self.admin_password) < 12:
            problems.append("CCCMS_ADMIN_PASSWORD must be at least 12 characters in production")
        return problems

    def ensure_local_secrets(self) -> None:
        """Generate dev-only secrets and persist them for every process.

        API workers, the scheduler and the CLI all need the same JWT/data/audit
        keys.  Random per-process values would invalidate tokens across workers,
        make encrypted config unreadable after a restart and break audit seals.
        """
        if self.env != "dev":
            return
        with _dev_secret_lock():
            stored = _read_dev_secrets()
            values: dict[str, str] = {}
            for name in DEV_SECRET_KEYS:
                current = str(getattr(self, name) or "")
                if not current:
                    current = stored.get(name, "")
                if not current:
                    size = 32 if name == "data_encrypt_key" else 48
                    current = secrets.token_urlsafe(size)
                values[name] = current
            _write_dev_secrets(values)
            for name, value in values.items():
                setattr(self, name, value)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    settings = Settings()
    settings.ensure_local_secrets()
    return settings
