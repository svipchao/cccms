# syntax=docker/dockerfile:1
FROM python:3.12-slim AS builder

ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/app/.venv
COPY --from=ghcr.io/astral-sh/uv:0.9 /uv /usr/local/bin/uv

WORKDIR /app
# 先只装依赖，让这一层在源码变化时仍可命中缓存
COPY pyproject.toml uv.lock ./
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev --no-install-project

COPY . .
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev


FROM python:3.12-slim AS runtime

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PATH="/app/.venv/bin:$PATH"
RUN useradd --create-home --uid 10001 app

WORKDIR /app
COPY --from=builder --chown=app:app /app /app
# 运行期目录：附件与导出（生产应挂载卷）
RUN mkdir -p /app/shared/uploads /app/shared/exports && chown -R app:app /app/shared

USER app
EXPOSE 8000

# 探针走 /healthz；Redis 故障时返回 degraded 但仍 200，不会误摘实例
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["python", "-c", "import json,urllib.request;d=json.load(urllib.request.urlopen('http://127.0.0.1:8000/healthz',timeout=3));assert d.get('code')==0"]

CMD ["uvicorn", "cccms.main:app", "--host", "0.0.0.0", "--port", "8000"]
