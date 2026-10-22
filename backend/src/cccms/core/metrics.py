"""Prometheus 指标定义。

单独成模块，避免 ``middleware`` 与 ``api.observability`` 互相 import。
路由标签使用 **路由模板**（``/api/user/{user_id}``）而不是真实路径，
否则用户 ID 之类的路径参数会把时间序列基数打爆。
"""

from __future__ import annotations

import re

from prometheus_client import CollectorRegistry, Counter, Gauge, Histogram

REGISTRY = CollectorRegistry()

REQUESTS = Counter(
    "cccms_http_requests_total",
    "HTTP 请求数（按方法/路由模板/状态码）",
    ["method", "route", "status"],
    registry=REGISTRY,
)
LATENCY = Histogram(
    "cccms_http_request_duration_seconds",
    "HTTP 请求耗时（按方法/路由模板）",
    ["method", "route"],
    registry=REGISTRY,
    buckets=(0.01, 0.05, 0.1, 0.3, 0.5, 1.0, 3.0, 5.0, 10.0, 30.0),
)
IN_FLIGHT = Gauge(
    "cccms_http_requests_in_flight",
    "当前正在处理的 HTTP 请求数",
    registry=REGISTRY,
)
# 审计队列满而丢弃的行数：审计缺失本身必须可观测，否则哈希链少了几行没人知道。
AUDIT_DROPPED = Counter(
    "cccms_audit_dropped_total",
    "审计日志因队列已满而被丢弃的行数",
    registry=REGISTRY,
)

UNMATCHED_ROUTE = "unmatched"

# 数字主键、UUID、32 位以上哈希都收敛成 {id}，避免时间序列被路径参数撑爆。
_ID_SEGMENT = re.compile(
    r"/(?:\d+|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9a-fA-F]{32,})(?=/|$)"
)


def stable_path(path: str) -> str:
    # 附件路径是「日期/文件哈希/文件名」，逐段收敛仍会每个文件一个标签，整体折叠。
    if path.startswith("/storage/"):
        return "/storage/{path}"
    return _ID_SEGMENT.sub("/{id}", path) or "/"


def observe_request(*, method: str, route: str | None, status: int, duration: float) -> None:
    label = route or UNMATCHED_ROUTE
    REQUESTS.labels(method=method, route=label, status=str(status or 0)).inc()
    LATENCY.labels(method=method, route=label).observe(duration)
