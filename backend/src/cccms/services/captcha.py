# ruff: noqa: S311 - 随机数仅用于 SVG 噪点绘制, 验证码本体由 secrets 生成
from __future__ import annotations

import base64
import random
import secrets

from redis.exceptions import RedisError

from cccms.core.cache import Keys, get_redis
from cccms.core.errors import ServiceUnavailable
from cccms.core.security import constant_time_equals

CHARS = "23456789ABCDEFGHJKLMNPQRSTUVWXYZ"
WIDTH = 120
HEIGHT = 40
TTL = 300


def _svg(code: str) -> str:
    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" viewBox="0 0 {WIDTH} {HEIGHT}">',
        f'<rect width="{WIDTH}" height="{HEIGHT}" rx="4" fill="#f2f5fa"/>',
    ]
    for _ in range(4):
        x1, y1 = random.randint(0, WIDTH), random.randint(0, HEIGHT)
        x2, y2 = random.randint(0, WIDTH), random.randint(0, HEIGHT)
        parts.append(
            f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" '
            f'stroke="#{random.randint(0x99, 0xcc):02x}{random.randint(0x99, 0xcc):02x}'
            f'{random.randint(0x99, 0xcc):02x}" stroke-width="1"/>'
        )
    for _ in range(22):
        cx, cy = random.randint(0, WIDTH), random.randint(0, HEIGHT)
        r = random.randint(1, 2)
        parts.append(f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="#9aa7b8" opacity="0.45"/>')
    for index, char in enumerate(code):
        x = 18 + index * 25
        y = 27 + random.randint(-2, 2)
        angle = random.randint(-24, 24)
        parts.append(
            f'<text x="{x}" y="{y}" font-family="Consolas, monospace" font-size="25" '
            f'font-weight="700" fill="#2b3a55" transform="rotate({angle} {x} {y})">{char}</text>'
        )
    parts.append("</svg>")
    return "".join(parts)


async def create() -> dict[str, str]:
    code = "".join(secrets.choice(CHARS) for _ in range(4))
    captcha_id = secrets.token_hex(16)
    try:
        await get_redis().set(Keys.captcha(captcha_id), code.lower(), ex=TTL)
    except RedisError as exc:
        raise ServiceUnavailable("验证码服务不可用") from exc
    image = "data:image/svg+xml;base64," + base64.b64encode(_svg(code).encode("utf-8")).decode("ascii")
    return {"captcha_id": captcha_id, "image": image}


async def verify(captcha_id: str | None, answer: str | None) -> bool:
    if not captcha_id or not answer:
        return False
    key = Keys.captcha(captcha_id)
    try:
        client = get_redis()
        expected = await client.get(key)
        await client.delete(key)
    except RedisError:
        return False
    if not expected:
        return False
    return constant_time_equals(str(expected), answer.strip().lower())
