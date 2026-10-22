from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path

from fastapi import Request

LANG_DIR = Path(__file__).resolve().parent.parent / "lang"
DEFAULT_LOCALE = "zh-CN"
SUPPORTED_LOCALES = ("zh-CN", "en-US")


@lru_cache(maxsize=32)
def _load(locale: str) -> dict[str, str]:
    directory = LANG_DIR / locale
    result: dict[str, str] = {}
    if not directory.exists():
        return result
    for path in directory.glob("*.json"):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        if isinstance(data, dict):
            result.update({str(key): str(value) for key, value in data.items()})
    return result


def locale_from_request(request: Request | None = None) -> str:
    if request is not None:
        query_lang = request.query_params.get("lang")
        if query_lang in SUPPORTED_LOCALES:
            return query_lang
        header = request.headers.get("accept-language", "")
        for item in header.split(","):
            code = item.split(";")[0].strip()
            if code in SUPPORTED_LOCALES:
                return code
            base = code.split("-")[0].lower()
            for supported in SUPPORTED_LOCALES:
                if supported.lower().startswith(base):
                    return supported
    return DEFAULT_LOCALE


def t(key: str, *, locale: str | None = None, **kwargs: object) -> str:
    target = locale or DEFAULT_LOCALE
    text = _load(target).get(key) or _load(DEFAULT_LOCALE).get(key) or key
    try:
        return text.format(**kwargs)
    except (KeyError, IndexError, ValueError):
        return text


def has(key: str, locale: str | None = None) -> bool:
    target = locale or DEFAULT_LOCALE
    return key in _load(target) or key in _load(DEFAULT_LOCALE)
