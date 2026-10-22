"""HTTP Range 解析与下载响应头。

``FileResponse`` 会自动处理 Range / ``Content-Length`` / ``ETag``；把附件下载改成
"由存储驱动给字节流"之后，这些必须自己做，否则断点续传、大 PDF 翻页、
音视频拖动都会退化（docs/16 §9.3 第 2 步）。

策略是**只支持单区间**：多区间、单位不是 bytes、语法错误的请求一律忽略并回整份
200 —— RFC 9110 允许这样做，比回 400 更宽容，也不会为没实现的写法制造新的失败面。
"""

from __future__ import annotations

from dataclasses import dataclass
from urllib.parse import quote


@dataclass(frozen=True)
class ByteRange:
    """闭区间 ``[start, end]``，两端都含。"""

    start: int
    end: int

    @property
    def length(self) -> int:
        return self.end - self.start + 1

    def header(self, total: int) -> str:
        return f"bytes {self.start}-{self.end}/{total}"


def resolve_range(header: str | None, size: int) -> tuple[ByteRange | None, bool]:
    """解析单区间 ``Range`` 头。

    返回 ``(区间, 是否可满足)``：

    * ``(None, True)``：按整份返回 200（没带 Range、写法不认识、或该忽略）；
    * ``(ByteRange, True)``：返回 206；
    * ``(None, False)``：起点越界或空文件被要求分段，调用方回 416。
    """
    if not header or size <= 0:
        # 空文件带 Range 属于不可满足（RFC 9110 §14.1.2）。
        return (None, True) if not header else (None, False)

    text = header.strip()
    if not text.lower().startswith("bytes="):
        return None, True
    spec = text[6:].strip()
    if "," in spec:
        # 多区间要 multipart/byteranges，成本远高于收益：整份返回。
        return None, True
    first, sep, last = spec.partition("-")
    if not sep:
        return None, True
    first, last = first.strip(), last.strip()

    try:
        if not first:
            # bytes=-N：最后 N 字节。
            suffix = int(last)
            if suffix <= 0:
                return None, True
            return ByteRange(max(0, size - suffix), size - 1), True
        start = int(first)
    except ValueError:
        return None, True
    if start >= size:
        return None, False
    if not last:
        return ByteRange(start, size - 1), True
    try:
        end = int(last)
    except ValueError:
        return None, True
    if end < start:
        return None, True
    return ByteRange(start, min(end, size - 1)), True


def attachment_header(filename: str) -> str:
    """构造 ``Content-Disposition``：ASCII 兜底 + RFC 5987 的 UTF-8 原文件名。

    与 ``FileResponse(filename=...)`` 的产出一致；顺手去掉换行与引号，
    避免用户上传的文件名污染响应头。
    """
    name = (filename or "download").replace("\r", "").replace("\n", "").replace('"', "")
    fallback = name.encode("ascii", "ignore").decode().strip() or "download"
    return f"attachment; filename=\"{fallback}\"; filename*=UTF-8''{quote(name)}"


__all__ = ["ByteRange", "attachment_header", "resolve_range"]
