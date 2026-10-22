<div align="center">

# CCCMS

**企业级中后台管理系统**

FastAPI · SQLAlchemy 2.0 · MySQL 8 · Redis · Vue 3 · Element Plus

[![Python](https://img.shields.io/badge/Python-3.12+-3776ab.svg)](https://www.python.org/)
[![FastAPI](https://img.shields.io/badge/FastAPI-0.115+-009688.svg)](https://fastapi.tiangolo.com/)
[![SQLAlchemy](https://img.shields.io/badge/SQLAlchemy-2.0-d71f00.svg)](https://www.sqlalchemy.org/)
[![Vue](https://img.shields.io/badge/Vue-3.5-42b883.svg)](https://vuejs.org/)
[![Element Plus](https://img.shields.io/badge/Element%20Plus-2.x-409eff.svg)](https://element-plus.org/)
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D%208.0-4479a1.svg)](https://www.mysql.com/)

</div>

---

## 简介

CCCMS 是一套可直接作为企业级项目起点的中后台系统：

- `backend/`：FastAPI + SQLAlchemy 2.0（async）+ MySQL 8 + Redis，分层为 `api / services / schemas / models / core`。
- `frontend/`：Vue 3 + Vite + TypeScript + Element Plus + Tailwind CSS 4，动态路由、按钮级权限、i18n、主题与设计 token。
- `shared/`：运行期附件与导出文件目录。
- `docs/`：架构、权限、数据模型、前端规范等设计文档。

设计原则不是「功能够多」，而是**把安全默认值做对**：

- **fail-closed 鉴权**：路由必须声明 `@permission` / `@no_auth` / `@no_login`，漏写直接启动失败。
- **唯一声明机制**：权限只有一种写法，装饰器统一收口。
- **租户是硬边界**：所有业务查询先按 `tenant_id` 切分；平台级接口额外校验平台租户。
- **数据权限下沉**：角色档位 + 行级/字段级规则在服务层统一 `apply_scope()`，不依赖开发者记忆。
- **可校验**：`python -m cccms.cli check` 校验路由声明，`ruff` 守住静态质量。

---

## 能力一览

| 领域 | 内容 |
|------|------|
| 认证与权限 | 账号密码登录 + 图形验证码、JWT（默认 2h，滑动续期）、Bearer / Cookie 双通道、RBAC（角色继承）、按钮级 `v-auth`、登录失败限流、密码策略、密码找回、我的登录设备 |
| 数据权限 | 角色 5 档范围 + 行级/字段级规则、受控表登记、服务层统一 `apply_scope()` |
| 多租户 | 单库共享表 + `tenant_id`，域名/手动选择租户，超管显式切换 |
| 组织 | 无限级部门、多部门、岗位与关联授权 |
| 系统能力 | 菜单与权限节点、键值配置、数据字典、软删除回收站、附件（**当前仅本地磁盘** + 分片上传/秒传）、定时任务（六段 Cron、重叠保护、失败重试）、通知公告（定向投放、已读回执）、代码生成器、在线升级（仅本地开发） |
| 日志与可观测 | 操作日志与登录日志同表、`trace_id / action_id / session_id` 三层链路、审计哈希链、后台导出任务、`/healthz`（DB/磁盘必需、Redis 仅降级）、`/metrics`（含调度器心跳年龄） |
| 前端体验 | 动态路由、i18n（中英）、主题 token、筛选方案、列设置、快捷导航与收藏、全局水印、富文本编辑器 |

---

## 技术栈

| 层 | 技术 |
|----|------|
| 后端 | Python 3.12+、FastAPI、SQLAlchemy 2.0（async）、asyncmy、Pydantic v2、Redis、croniter、prometheus-client |
| 前端 | Vue 3.5、Vite 5、TypeScript 5、Element Plus 2、Tailwind CSS 4、Pinia、Vue Router、ECharts 6、RemixIcon |
| 存储 | MySQL 8（utf8mb4）、Redis 6+、本地磁盘（对象存储尚未实现，见 `docs/开发计划.md` F-01） |
| 工程化 | uv、Ruff、mypy、pytest、ESLint、Prettier、Vitest、GitHub Actions（`.github/workflows/ci.yml`） |

---

## 快速开始

### 1. 准备依赖

- Python 3.12+、[uv](https://docs.astral.sh/uv/)
- Node.js 20.19+、npm 10+
- MySQL 8、Redis 6+

```bash
mysql -uroot -p -e "CREATE DATABASE cccms DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

### 2. 启动后端

```bash
cd backend
uv sync                     # 只安装依赖，不会启动 API 或调度器
cp .env.example .env        # Windows: copy .env.example .env
# 修改 CCCMS_DB_PASSWORD / CCCMS_REDIS_URL 等

uv run python -m cccms.cli init-db     # = alembic upgrade head
uv run python -m cccms.cli seed

# 方式 A：API + 调度器一起启动（本地开发推荐）
uv run python -m cccms.dev

# 方式 B：分开启动，便于单独看日志
uv run uvicorn cccms.main:app --reload --port 8000
uv run python -m cccms.scheduler
```

首次 seed 会创建：

- 平台租户 `platform`
- 角色 `super_admin`
- 管理员 `admin / Admin@123456`（由 `CCCMS_ADMIN_*` 控制，登录后请立即修改）
- 菜单树与按钮权限节点（按后端路由声明自动生成，可重复执行 seed 同步）

接口文档：<http://127.0.0.1:8000/docs>（生产环境默认关闭）。

### 3. 启动前端

```bash
cd frontend
npm ci
npm run dev
```

访问 <http://127.0.0.1:5173>。Vite 已把 `/api` 与 `/storage` 代理到 `http://127.0.0.1:8000`（保留 `/api` 前缀）。

### 4. 定时任务调度器

调度器是**独立进程**，`uv sync` 不会启动它：

```bash
cd backend
uv run python -m cccms.scheduler
```

本地开发也可以用 `uv run python -m cccms.dev` 同时启动 API 与调度器；生产环境仍建议分开部署，便于单独重启与扩容。

调度器扫描 `sys_crontab`，用 Redis 锁避免多实例重复执行；失败按配置写入 `sys_crontab_retry` 重试。

---

## 目录结构

见 [docs/03-目录结构](docs/03-目录结构.md)（后端分层、前端分层、运行期目录各自讲一遍，此处不再重复）。

---

## 接口约定

| 项 | 约定 |
|----|------|
| 前缀 | 业务接口 `/api`（旧前缀，保留一个发布周期）与 `/api/v1`（新集成）；探针 `/ping`、`/healthz`、`/metrics` 在根路径 |
| 附件 | `/storage/**` 需登录且通过 `apply_scope` 校验，不再匿名直出；纯 Bearer 客户端或"另存为"用 `GET /api/file/{id}/download` |
| 响应 | `{ "code": 0, "message": "ok", "data": ..., "trace_id": "...", "action_id": "...", "session_id": "..." }`，非 0 即失败 |
| 分页 | `{ "total": 100, "list": [...] }`，请求参数 `page` / `limit` |
| 认证 | 优先 `Authorization: Bearer <token>`，同时兼容 HttpOnly Cookie |
| 续期 | 令牌剩余有效期不足 1/3 时，响应头返回 `X-Refresh-Token`，前端静默替换 |
| CSRF | 仅 Cookie 会话的写请求校验 `X-CSRF-Token`；Bearer 与匿名登录接口豁免 |
| 时间 | `YYYY-MM-DD HH:MM:SS`（本地时区） |
| 链路 | 前端自动生成 `X-Action-Id` / `X-Session-Id`，后端生成 `trace_id` |

权限声明示例：

```python
@router.get("", response_model=ApiResponse[PageResult[UserOut]])
@permission("cccms:user:index", "用户列表")
async def index(...):
    ...
```

漏写装饰器的路由会触发 `GuardedRoute` 启动校验。

---

## 配置

所有配置通过环境变量（前缀 `CCCMS_`）或 `backend/.env` 注入，完整示例见 [`backend/.env.example`](backend/.env.example)。

关键项：

| 变量 | 说明 |
|------|------|
| `CCCMS_ENV` | `dev` / `test` / `prod` |
| `CCCMS_DB_*` | MySQL 连接 |
| `CCCMS_REDIS_URL` | Redis（会话、缓存、限流、调度锁） |
| `CCCMS_JWT_SECRET` | JWT 密钥，至少 32 字节；生产必填 |
| `CCCMS_DATA_ENCRYPT_KEY` | 敏感字段加密密钥，生产必填 |
| `CCCMS_AUDIT_SIGNING_KEY` | 审计链签名密钥，生产必填 |
| `CCCMS_STORAGE_LOCAL_ROOT` | 本地附件根目录，默认 `shared/uploads`（**不**做静态挂载，读取一律走 `/storage/<path>` 鉴权直出） |
| `CCCMS_EXPORT_LOCAL_ROOT` | 导出文件根目录，默认 `shared/exports`（必须位于静态目录之外） |
| `CCCMS_UPGRADE_ENABLE` | 在线升级开关，默认 `false`，仅 `dev` 生效 |

生产环境启动时会校验密钥与 `CCCMS_COOKIE_SECURE`，不满足直接拒绝启动；如果镜像或主机上还残留开发期的 `.dev-secrets.env`，同样拒绝启动（它会被 `env_file` 静默读入并顶替正式密钥）。

### 容器部署

`docker-compose.yml` **只**读取 `backend/.env.prod`（生产语义），不会读取开发用的 `backend/.env`：

```bash
cp backend/.env.prod.example backend/.env.prod   # 填入 4 个密钥、CORS、管理员口令、数据库口令
docker compose up -d
```

- `.env.prod` 与 `.dev-secrets.env` 都已在 `.gitignore` / `.dockerignore` 中，不会入库也不会进镜像。
- 模板里密钥留空是故意的：忘了填，API 与调度器会带明确报错退出，而不是带着一半默认值起来。
- 本机开发仍然用 `backend/.env` + `uv run python -m cccms.dev`，两者互不影响。

---

## 常用命令

```bash
# 后端
uv run python -m cccms.cli init-db      # 首次建表（alembic upgrade head）
uv run python -m cccms.cli migrate      # 已有库应用增量迁移
uv run python -m cccms.cli seed         # 初始化租户/角色/管理员
uv run python -m cccms.cli check        # 路由权限声明校验
uv run ruff check src
uv run mypy src
uv run pytest

# 前端
npm run dev
npm run build
npm run lint
npm run test
npm run i18n:check
```

---

## 设计要点

- 权限声明使用 Python 装饰器，启动时校验，漏写即失败。
- 数据权限由服务层显式 `apply_scope()` 生效，避免隐式查询副作用。
- 导出任务先落库再后台生成（进度可轮询），文件只能经鉴权接口下载；后台任务复用请求内构建好的查询与脱敏快照，不会因缺上下文而越权。
- 定时任务由 `cccms.scheduler` 驱动，Redis 锁保证多实例不重复执行。
- 在线升级仅保留本地开发场景，默认关闭；生产建议按发布流程替换目录。

更多设计细节见 [`docs/`](docs/)：

- [项目介绍](docs/01-项目介绍.md)
- [安装部署](docs/02-安装部署.md)
- [目录结构](docs/03-目录结构.md)
- [鉴权与权限](docs/05-鉴权与权限.md)
- [数据权限](docs/06-数据权限.md)
- [功能模块](docs/07-功能模块.md)
- [数据模型](docs/13-数据模型.md)

---

## License

MIT
