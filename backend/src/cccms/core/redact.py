from __future__ import annotations

from typing import Any

SENSITIVE_KEYS = {
    "password",
    "old_password",
    "new_password",
    "password_confirm",
    "token",
    "access_token",
    "refresh_token",
    "secret",
    "authorization",
    "cookie",
    "csrf",
    "mfa_secret",
    "code",
    "captcha",
}
# 配置项常以 mail.smtp_password / oss.access_secret 这类复合键提交。
SENSITIVE_MARKERS = ("password", "secret", "token", "_key", "credential")
MAX_DEPTH = 3
MAX_TEXT = 8000


def redact(value: Any, depth: int = 0) -> Any:
    if depth > MAX_DEPTH:
        return "***"
    if isinstance(value, dict):
        result: dict[str, Any] = {}
        for key, item in value.items():
            lowered = str(key).lower()
            if lowered in SENSITIVE_KEYS or any(marker in lowered for marker in SENSITIVE_MARKERS):
                result[str(key)] = "******"
            else:
                result[str(key)] = redact(item, depth + 1)
        return result
    if isinstance(value, list):
        return [redact(item, depth + 1) for item in value[:50]]
    if isinstance(value, str):
        return value[:MAX_TEXT]
    return value


def truncate(text: str | None, limit: int = MAX_TEXT) -> str | None:
    if text is None:
        return None
    return text[:limit]
