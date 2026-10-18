<div align="center">

# CCCMS

**企业级中后台管理系统 · 快速开发框架**

插件化 · 注解驱动 · fail-closed · 数据权限深入模型层

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](server/LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4.svg)](https://www.php.net/)
[![Webman](https://img.shields.io/badge/Webman-2.x-22c55e.svg)](https://www.workerman.net/)
[![Vue](https://img.shields.io/badge/Vue-3.5-42b883.svg)](https://vuejs.org/)
[![Element Plus](https://img.shields.io/badge/Element%20Plus-2.x-409eff.svg)](https://element-plus.org/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-4-38bdf8.svg)](https://tailwindcss.com/)
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D%208.0-4479a1.svg)](https://www.mysql.com/)

</div>

---

## 简介

CCCMS 是一套基于 **Webman 2.x（PHP 常驻内存框架）+ Vue 3** 的中后台管理系统，可直接作为企业级项目的起点或二次开发底座。

后端采用 **插件化架构 + think-orm + JWT**，前端采用 **Vue 3 + Vite + TypeScript + Element Plus + Tailwind CSS 4**，内置角色权限（RBAC）、数据权限、菜单与按钮权限、软删除回收站、代码生成器、定时任务、多存储驱动等能力。

它不只是一个脚手架，更是一套「把安全默认值做对」的工程约定：**漏配的代价是「不可用」而不是「被越权」**。

- **fail-closed 鉴权**：控制器未声明权限注解直接拒绝，而不是静默放行。
- **唯一声明机制**：鉴权只有一种写法 —— PHP 8 原生属性，命名参数。
- **数据权限下沉到模型层**：行级范围由全局查询作用域自动注入，字段级出、入参分别由 `toArray()` 与 `ScopedQuery` 处理，不依赖开发者记忆。
- **插件化隔离**：`plugin/cccms` 只放框架级基础功能，业务功能新建插件，中间件与路由按插件作用域生效。
- **可用的校验命令**：`perm-scan` / `data-scope-check` / `data-rule-check` 把「容易漏掉的正确做法」变成 CI 能拦住的检查。
- **可观测与可运维**：请求链路 ID、操作日志 / 登录日志、在线用户与强制下线、定时任务重叠保护与失败重试。

> 本仓库为全新工程，不迁移、不兼容老 CCCMS（ThinkPHP 8 版本）数据。

---

## 功能特性

### 一期（P0）

| 模块 | 内容 |
|------|------|
| 认证 | 账号密码登录、登出、图形验证码、JWT 签发 / 校验、登录失败限流 |
| RBAC | 用户、角色（含继承）、菜单（节点）管理；用户 ↔ 角色、角色 ↔ 节点 |
| 组织 | 部门（无限级，用户可多部门）、岗位；用户 ↔ 部门、部门 ↔ 角色、用户 ↔ 岗位 |
| 数据权限 | 角色 `data_scope`（5 档）+ `sys_data_rule`（行级 / 字段级）；**模型层自动生效** |
| 鉴权 | 4 个注解 + 中间件链 + `perm-scan` 校验命令 |
| 响应 | 统一出口 `Result`，支持 `json / jsonp / xml / view` 四种编码 |
| 菜单 | `db/menu.php` 声明目录 / 菜单，按钮由注解生成；菜单管理界面 |
| 系统维护 | 顶栏「同步 / 清理缓存」，与 CLI 共用同一份实现 |
| 个人中心 | 静态路由 `/profile`，入口在右上角用户下拉 |
| 回收站 | 业务主数据软删除；各模块页面表格右上角切换查看已删数据 |
| 系统配置 | 键值配置 + 可视化表单（switch / select / input-number / textarea / radio / password） |
| 数据字典 | 类型 + 数据两级结构，共用通用分类 |
| 操作日志 | 记录请求参数、执行结果、耗时、IP、UA，敏感字段递归脱敏 |
| 附件管理 | 本地驱动 `public/storage`，URL 统一由 `url_prefix` 生成 |
| 基础防护 | 全局 XSS 过滤、CORS、统一异常响应 |

### 二期（P1）

| 模块 | 内容 |
|------|------|
| 定时任务 | 六段 Cron（秒 分 时 日 月 周）+ 可视化编辑、每秒调度进程、执行白名单、执行日志、立即执行 |
| 代码生成 | 按库表生成 model / logic / controller / 路由 / API / 页面，并登记菜单 |
| 多存储 | `StorageManager` 驱动抽象 + local / OSS / COS / 七牛 |
| 前台应用 | 独立 `plugin/index` 插件（公开路由 `/site/*`，与后台中间件隔离） |

### 三期（P2）· 能力补齐与工程化

| 模块 | 内容 |
|------|------|
| 登录日志 | 登录成功与失败都记录（账号、结果、原因、IP、UA），支持筛选 / 导出 / 清空 |
| 在线用户 | Redis 会话索引；按会话或按用户**强制下线**（同时作废已发出的令牌） |
| 通知公告 | 管理端发布 + 顶栏「我的消息」抽屉与未读角标 |
| 导入导出 | 通用 CSV（BOM、RFC 4180、**公式注入防护**）；用户导入导出、日志导出 |
| 定时任务增强 | 重叠保护（跳过 / 并发）、超时释放运行锁、失败非阻塞重试、任务分组 |
| 可观测性 | 请求链路 ID：响应头 `X-Trace-Id` + `sys_log.trace_id` + 失败响应体 |
| 会话与缓存 | 令牌失效名单（`jti` 黑名单 + 用户级分界线）、权限集合 Redis 缓存 + 版本号失效 |
| 前端体验 | 快捷导航（Ctrl / ⌘ + K）、面包屑可点击、列表**列宽拖拽记忆** |
| 工程化 | GitHub Actions（后端 / 前端）、零依赖测试运行器、ESLint + Prettier、Docker 多阶段与完整编排 |

---

## 技术栈

| 层 | 技术 |
|----|------|
| 后端 | PHP >= 8.1、Webman 2.x、think-orm 4.x、MySQL >= 8.0、Redis >= 5.0 |
| 前端 | Vue 3.5、Vite 5、TypeScript 5、Element Plus 2、Tailwind CSS 4、Pinia、Axios、ECharts |
| 鉴权 | JWT（HS256，accessToken），Redis 存验证码 / 限流 / 配置缓存 / 会话分界线 |
| 后端形态 | Webman 插件化（`plugin/*`） |
| 响应格式 | 统一出口 `Result`，支持 json / jsonp / xml / view，默认 json |
| 部署 | 原生启动 / Docker（`Dockerfile` + `docker-compose.yml`） |

---

## 目录结构

```
cccms/
├── .github/       # CI 工作流（后端 php.yml / 前端 frontend.yml）
├── docs/          # 完整项目文档（含更新日志与开发计划）
├── server/        # 后端（Webman）
│   ├── app/       # 主应用（仅入口与全局配置）
│   ├── config/    # 全局配置
│   ├── plugin/
│   │   ├── cccms/ # 框架级基础功能插件（核心）
│   │   └── index/ # 前台公开应用插件
│   ├── tests/     # 零依赖测试运行器与用例
│   └── .env.example
├── frontend/      # 前端（Vue 3 + Vite + TS + Element Plus）
│   ├── eslint.config.js
│   └── src/
│       ├── api/ components/ composables/ layouts/ pages/ router/ stores/ utils/
│       └── ...
└── README.md
```

详细目录职责与命名约定见 [docs/03-目录结构.md](docs/03-目录结构.md)。

---

## 快速开始

### 环境要求

- PHP >= 8.1（推荐 8.3），扩展 `pdo`、`pdo_mysql`、`pcntl`、`redis`；建议 `event`
- MySQL >= 8.0、Redis >= 5.0、Node.js >= 18

### 后端

```bash
cd server
composer install
cp .env.example .env           # 本地开发记得设 APP_DEBUG=true

# 建库并导入（自行建库）
#   CREATE DATABASE cccms DEFAULT CHARSET utf8mb4;
mysql -uroot -p cccms < plugin/cccms/db/schema.sql
mysql -uroot -p cccms < plugin/cccms/db/seed.sql

# 同步菜单与按钮节点
php webman cccms:menu-sync
php webman cccms:perm-scan
php webman cccms:db-upgrade     # 已有库补新增表/列/索引（新装环境不需要）

# 启动（默认 http://0.0.0.0:8787）
php windows.php                 # Windows
php start.php start             # Linux / macOS
```

### 前端

```bash
cd frontend
npm install
npm run dev      # http://localhost:5173
npm run build    # 产物 dist/
```

开发期 `/api` 由 Vite 代理到 `http://127.0.0.1:8787`，前端统一以 `/api` 前缀调用接口。

### 默认账号

| 账号 | 密码 |
|------|------|
| `admin` | `admin123` |

> 首次登录后请立即修改密码。

### Docker

`docker-compose.yml` 一键启动 **Webman + MySQL + Redis**，首次初始化自动导入表结构与种子数据：

```bash
cd server
cp .env.example .env           # 必须设置 JWT_SECRET / DATA_ENCRYPT_KEY / DB_PASSWORD
docker compose up -d --build   # 暴露 8787

docker compose exec webman php webman cccms:menu-sync
docker compose exec webman php webman cccms:perm-scan
```

完整的安装、配置、生产部署与升级说明见 [docs/02-安装部署.md](docs/02-安装部署.md)。

---

## 安全设计要点

| # | 约束 | 原因 |
|---|------|------|
| 1 | 数据权限字段名与操作符必须白名单 | 规则条件会进 SQL where，需封死注入面 |
| 2 | `public/storage` 禁止解析 PHP | 同域上传目录可执行脚本 = 任意代码执行 |
| 3 | Token 与用户状态强关联 | `CheckLogin` 每请求从 DB 重建用户上下文，禁用 / 删除用户立即 401 |
| 4 | `#[NoAuth]` 不下放给下拉 / 选项接口 | 避免任意登录用户拿全量数据 |
| 5 | 角色继承必须防环 + 限深 | `parent_id` 成环会让权限解析死循环；深度上限 5 |
| 6 | 登录失败限流 + 密码策略 | Redis 计数，连续失败锁窗口；初始密码强制修改 |

更多机制说明见 [docs/04-框架介绍.md](docs/04-框架介绍.md)、[docs/05-鉴权与权限.md](docs/05-鉴权与权限.md)、[docs/06-数据权限.md](docs/06-数据权限.md)。

---

## 文档

| 文档 | 说明 |
|------|------|
| [docs/README.md](docs/README.md) | 文档中心 · 导航与文档编写规范 |
| [docs/01-项目介绍.md](docs/01-项目介绍.md) | 定位、技术选型、功能总览、设计理念 |
| [docs/02-安装部署.md](docs/02-安装部署.md) | 环境要求、安装、初始化、Docker、生产部署、升级 |
| [docs/03-目录结构.md](docs/03-目录结构.md) | 前后端目录职责与命名约定 |
| [docs/04-框架介绍.md](docs/04-框架介绍.md) | 插件化架构、请求生命周期、分层、核心组件 |
| [docs/05-鉴权与权限.md](docs/05-鉴权与权限.md) | 注解、鉴权矩阵、RBAC、角色继承、前端 v-auth |
| [docs/06-数据权限.md](docs/06-数据权限.md) | 行级 / 字段级数据权限、受控表、规则体检 |
| [docs/07-功能模块.md](docs/07-功能模块.md) | 每个模块的功能、接口、页面、权限节点 |
| [docs/08-后端开发规范.md](docs/08-后端开发规范.md) | 分层规范、编码约定、新增接口 / 插件步骤 |
| [docs/09-前端开发规范.md](docs/09-前端开发规范.md) | 前端架构、路由、状态、主题、开发约定 |
| [docs/10-前端组件参考.md](docs/10-前端组件参考.md) | `ArtTable` / `useTable` 等组件与 Hooks API |
| [docs/11-常用命令与运维.md](docs/11-常用命令与运维.md) | CLI 命令、Redis 键、配置项、备份、安全加固、CI |
| [docs/12-常见问题与排错.md](docs/12-常见问题与排错.md) | 按现象检索的 FAQ |
| [docs/更新日志.md](docs/更新日志.md) | 已交付的版本变更 + 已决策不做的事项 |
| [docs/开发计划.md](docs/开发计划.md) | 待办清单（要做但尚未做的功能与质量项） |

---

## 常用命令

```bash
php webman cccms:perm-scan            # 扫描注解 → 校验 + 同步按钮节点
php webman cccms:perm-scan --check    # 只校验不写库（CI 用）
php webman cccms:menu-sync            # 同步 db/menu.php 的目录 / 菜单
php webman cccms:plugin-create shop   # 生成业务插件骨架并自动登记菜单（前台 / 后台交互式选择）
php webman cccms:db-upgrade           # 已有库补新增表 / 列 / 索引（幂等）
php webman cccms:update               # 从上游仓库同步框架更新（安全覆盖 + 自动备份）
php webman cccms:data-scope-check     # 校验数据权限接入
php webman cccms:data-rule-check      # 体检数据权限规则（有冲突时退出码 1）
composer test                         # 后端单元测试（库不可用时相关用例自动跳过）
```

前端：

```bash
cd frontend
npm run lint           # ESLint
npm run format:check   # Prettier 格式校验
npm run build          # vue-tsc --noEmit + vite build
```

---

## 贡献

欢迎提交 Issue 与 Pull Request。参与开发前请先阅读：

- [docs/08-后端开发规范.md](docs/08-后端开发规范.md)
- [docs/09-前端开发规范.md](docs/09-前端开发规范.md)
- [docs/README.md §三 文档编写规范](docs/README.md#三文档编写规范)

提交前请确保以下检查通过（也已在 CI 中自动执行）：

```bash
cd server
php webman cccms:perm-scan --check
php webman cccms:data-scope-check
php webman cccms:data-rule-check
composer test

cd ../frontend
npm run format:check && npm run lint && npm run build
```

---

## 开源协议

本项目基于 [MIT License](server/LICENSE) 开源。

## 致谢

- [Webman](https://www.workerman.net/) —— 高性能 PHP 常驻内存框架
- [think-orm](https://github.com/top-think/think-orm) —— 强大的 ORM
- [Element Plus](https://element-plus.org/) —— 优秀的 Vue 3 组件库
- [Vue.js](https://vuejs.org/) / [Vite](https://vitejs.dev/) / [Tailwind CSS](https://tailwindcss.com/) —— 现代前端工具链
