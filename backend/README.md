# CCCMS Python Backend

FastAPI + SQLAlchemy 2.0 async + MySQL 8.0 + Redis 7.

## Quick start (Windows)

```powershell
cd backend
uv sync
copy .env.example .env
# edit .env: CCCMS_DB_PASSWORD / CCCMS_JWT_SECRET / CCCMS_DATA_ENCRYPT_KEY / CCCMS_AUDIT_SIGNING_KEY

# create database
mysql -uroot -p -e "CREATE DATABASE cccms DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# first install: create tables from SQLAlchemy metadata, then seed the admin
uv run python -m cccms.cli init-db
uv run python -m cccms.cli seed
uv run uvicorn cccms.main:app --reload --port 8000
```

API docs are disabled in production. In dev: <http://127.0.0.1:8000/docs>.

## Processes

| Process | Command | Notes |
|---|---|---|
| web | `uv run uvicorn cccms.main:app --host 0.0.0.0 --port 8000` | multiple workers on Windows via `--workers N` |
| scheduler | `uv run python -m cccms.scheduler` | polls `sys_crontab`; Redis lock makes replicas safe |

Windows production uses a service wrapper (NSSM/WinSW); upgrade is stop -> deploy new
release directory -> start. SIGHUP / symlink switching is intentionally not used.

## Safety defaults

- Every route must declare `@permission`, `@no_auth` or `@no_login`; otherwise startup fails.
- Tenant is a hard boundary; data scope is a soft range inside a tenant.
- `python -m cccms.cli check` is the CI gate for route declarations.
- Audit log is append-only and sealed in batches by the worker; verification is a CLI command.
