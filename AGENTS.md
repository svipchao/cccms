# AGENTS.md

给在本仓库工作的 AI 与人类协作者。**本文与代码冲突时以代码为准，并顺手把本文改对**——上一版 AGENTS.md 正是因为描述了不存在的 PHP 目录而整体作废。

先读这三份再动手：

| 文件 | 用途 |
|------|------|
| `docs/08-后端开发规范.md` | 分层、路由声明、事务、查询的权威约定 |
| `docs/09-前端开发规范.md` | 请求解包、路由、i18n、类型约定 |
| `docs/03-目录结构.md` | 每个目录放什么 |

历史依据：`docs/项目体检与竞品分析-2026-10-22.md`（体检报告）、`docs/修复记录-2026-10-22.md`（每轮改了什么、为什么、怎么回滚）、`docs/更新日志.md`（已交付版本）。

## 1. 技术栈与目录

- `backend/`：Python 3.12 + FastAPI + SQLAlchemy 2.0 **async** + MySQL 8 + Redis，`uv` 管理依赖，Alembic 迁移在 `backend/migrations/`。
- `frontend/`：Vue 3 + TypeScript + Vite + Element Plus + Pinia，测试用 Vitest。
- 后端分层固定：`api/`（HTTP 边界，只做参数校验与调服务）→ `services/`（业务与事务）→ `models/`（SQLAlchemy）＋ `schemas/`（Pydantic）。`core/` 放横切能力（鉴权、数据权限、限流、审计、排序、分页）。

## 2. 常用命令

```bash
# 后端（在 backend/ 下）
cp .env.example .env                    # Windows: copy .env.example .env
uv sync
uv run python -m cccms.cli init-db      # = alembic upgrade head
uv run python -m cccms.cli seed         # 租户/角色/管理员/字典
uv run python -m cccms.cli check        # 路由权限声明校验（不必连库）
uv run ruff check src tests
uv run mypy src
uv run pytest -q

# 前端（在 frontend/ 下）
npm ci
npm run dev
npm run lint
npm run test                            # = vitest run
npm run i18n:check
npx vue-tsc --noEmit
```

CLI 只有这几个子命令：`init-db` / `migrate` / `seed` / `audit-verify` / `check`。提交前按 `docs/08` 第九节跑一遍（**不含** `ruff format`，见第 7 节）。

## 3. 硬约定（违反会被启动期校验或测试挡住）

1. **路由三选一**：`@permission(slug, title, methods=[...])`（登录 + 权限节点）、`@no_auth(title)`（登录即可，不校验节点）、`@no_login(title)`（公开）。写操作显式给 `methods`，否则方法不匹配会落到函数里再 500。漏写声明 `cli check` 直接失败。
2. **列表必须**：`apply_scope()` → `count_query()` 计数 → `offset/limit` 分页；`limit` 用 `PageLimit`（≤200），不要手写 `le=999`。
3. **排序必须走白名单**：`core/sorting.apply_sort(statement, SORTABLE, id_column=..., fallback=(...), sort=..., order=...)`。禁止 `getattr(Model, params.sort)`；Query 模型要继承 `schemas/sorting.SortQuery` 才收得到 `sort`/`order`（`StrictModel` 会静默丢弃未声明字段）。前端声明了 `sortable: 'custom'` 的列，后端必须有对应白名单条目。
4. **错误用 `core/errors`**：`ValidationFailed` / `NotFound` / `Forbidden` / `Conflict` / `ServiceUnavailable`，不要裸 `raise HTTPException`。业务码在 `core/codes.py`，与 HTTP 状态码解耦。
5. **响应走信封**：`ok(payload)` / `page_result(total, rows)`，不要自己拼 `{"code": 200, ...}`。
6. **一个业务动作一次 `commit()`**；`IntegrityError` 转 `Conflict`，不要把数据库异常透给前端。
7. **版本号只有一处**：`backend/src/cccms/__init__.py` 的 `__version__`；`pyproject.toml` 动态读取，前端 `package.json` 由 `tests/test_version.py` 断言。
8. **改行为就改文档**：`docs/` 对应章节 + `docs/更新日志.md`，不要只改代码。

## 4. 安全红线

- **不提交密钥**：`backend/.env`、`backend/.dev-secrets.env`、`backend/.env.prod` 已在 `.gitignore` / `.dockerignore`；模板是 `.env.example` 与 `.env.prod.example`（密钥留空，漏填即启动失败）。
- **prod 硬门槛不要放宽**：`debug` 必须 false、4 个密钥非空且够长、`COOKIE_SECURE=true`、`CORS_ORIGINS` 不含 `*`、管理员口令非默认、**检出 `.dev-secrets.env` 即拒绝启动**。报错就去改配置，不要改校验。
- **附件可见性只由 `apply_scope` 决定**：`/storage/<path>` 与 `GET /api/file/{id}/download` 都是"登录 + 数据范围"，不可见与不存在同码 404，避免用 id 探测别人附件。
- **代码生成器仅 dev/test**：`api/generator.py` 四个端点都调了 `generator_service.ensure_available()`，新增端点也必须调。
- **域名→租户解析失败必须 fail-closed**：`RequestContextMiddleware` 在解析异常时返回 503（`BizCode.UNAVAILABLE`），不要退回"无租户上下文"继续处理——那会让后续查询失去 `tenant_id` 约束。`/ping`、`/healthz`、`/metrics` 豁免，避免 Redis 抖动把全部实例摘掉。
- **不吞异常**：`except` 要么记日志（带可定位的 id）、要么转成 `core/errors` 的业务异常；禁止空 `except` 和 `except: pass`。
- **不加没有依据的保护**：固定超时、条数上限、重试次数这类参数，要有真实故障或平台限制作依据，否则只是把问题藏起来。

## 5. 测试约定

- `pytest` 已配 `asyncio_mode = auto`，异步用例直接 `async def test_...`。
- `tests/conftest.py` 会清空 `Settings` 的 `env_file`：**单测不读工作区 `.env`**。需要真实配置的集成测试请显式 `Settings(_env_file=...)`。
- 契约测试不依赖 MySQL/Redis：用 `app.dependency_overrides` 伪造 `load_user` / `get_session`，用 stub session 捕获 SQL（见 `tests/test_http_contract.py`、`tests/test_sort_wiring.py`）。
- 不要为了让测试通过去改断言；测试本身错了，先说明再改。

## 6. 前端约定

- 请求只走 `src/api/*`，统一解包与错误提示；不要页面里直接 `fetch`。
- 文案两端同时补：`src/locales/zh-CN/*.ts` 与 `src/locales/en-US/*.ts` 的 key 集合必须完全一致，`npm run i18n:check` 不一致直接失败（硬编码中文只提示，不判失败）。
- 列表页用 `composables/useTable.ts`：它会把表头排序转成 `sort`/`order` 查询参数，配合后端白名单生效。加 `sortable: 'custom'` 前先确认后端有白名单。
- 类型检查是 `vue-tsc --noEmit`，不是构建时才暴露。

## 7. 已知未修（别顺手踩）

- **`ruff format` 未启用**：`uv run ruff format --check src` 目前有 25 个文件待格式化。不要为了"过检查"整文件格式化——那是无工单重构，会淹没真实 diff。
- **数据权限只覆盖 5 个模型**（`user` / `log` / `file` / `dept` / `crontab` 注册了 `__data_scope__`）。新模型要显式注册，否则列表不受数据范围约束。
- **对象存储未实现**：`CCCMS_STORAGE_DRIVER` 只接受 `local`，其它取值启动即失败。
- 其余待办见 `docs/开发计划.md` 与 `docs/修复记录-2026-10-22.md` 的"未执行项"。

## 8. 工作方式

- 改动前先读真实调用链（谁调用、输出被谁消费），只改与任务相关的位置。
- 一次一个可验证的小步：改 → 跑命令 → 贴真实输出 → 再继续。没跑过命令不算"已验证"。
- 报告/文档里的结论先复核再采信；发现报告判断有误，先说清证据再决定改不改。
