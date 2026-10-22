"""Range 解析与下载响应头。

改成流式之后，`FileResponse` 曾经白送的 Range / Content-Length 换成自己算，
这组用例是防止"下载功能悄悄退化"的唯一防线。
"""

from __future__ import annotations

import pytest

from cccms.core.http_range import ByteRange, attachment_header, resolve_range


@pytest.mark.parametrize(
    ("header", "size", "expected"),
    [
        (None, 10, (None, True)),
        ("", 10, (None, True)),
        # 整份里的各类写法
        ("bytes=0-3", 10, (ByteRange(0, 3), True)),
        ("bytes=5-", 10, (ByteRange(5, 9), True)),
        ("bytes=-4", 10, (ByteRange(6, 9), True)),
        ("bytes=0-1000", 10, (ByteRange(0, 9), True)),
        ("bytes=9-", 10, (ByteRange(9, 9), True)),
        ("bytes=-100", 10, (ByteRange(0, 9), True)),
        ("BYTES=0-1", 10, (ByteRange(0, 1), True)),
        # 越界：不可满足，回 416
        ("bytes=10-", 10, (None, False)),
        ("bytes=99-100", 10, (None, False)),
        ("bytes=0-0", 0, (None, False)),
        # 写法不认：忽略，回整份（RFC 9110 允许）
        ("bytes=abc", 10, (None, True)),
        ("bytes=", 10, (None, True)),
        ("bytes=-", 10, (None, True)),
        ("bytes=-0", 10, (None, True)),
        ("bytes=5-2", 10, (None, True)),
        ("bytes=0-1,3-4", 10, (None, True)),
        ("items=0-1", 10, (None, True)),
        ("bytes 0-1", 10, (None, True)),
    ],
)
def test_resolve_range(header: str | None, size: int, expected: tuple[object, bool]) -> None:
    assert resolve_range(header, size) == expected


def test_byte_range_length_and_header() -> None:
    span = ByteRange(2, 5)
    assert span.length == 4
    assert span.header(10) == "bytes 2-5/10"


def test_attachment_header_keeps_ascii_and_encodes_utf8() -> None:
    ascii_header = attachment_header("report.pdf")
    assert 'filename="report.pdf"' in ascii_header
    assert "filename*=UTF-8''report.pdf" in ascii_header

    chinese = attachment_header("报价单.png")
    assert chinese.startswith('attachment; filename="')
    # 非 ASCII 走 RFC 5987，浏览器才会显示原名而不是乱码。
    assert "filename*=UTF-8''%E6%8A%A5%E4%BB%B7%E5%8D%95.png" in chinese


def test_attachment_header_strips_header_injection() -> None:
    header = attachment_header('a"\r\nX-Evil: 1.txt')
    # 单行是硬要求：CRLF 逃逸等于把用户文件名变成响应头注入。
    assert "\r" not in header
    assert "\n" not in header
    plain = header.split('filename="')[1].split('";')[0]
    assert '"' not in plain


def test_attachment_header_falls_back_to_ascii_only() -> None:
    header = attachment_header("报价单.png")
    plain = header.split('filename="')[1].split('"')[0]
    # 兜底部分只允许 ASCII；非 ASCII 原名由 filename* 承担。
    assert plain.isascii()
    assert "filename*=UTF-8''" in header
