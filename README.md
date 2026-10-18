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
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D%205.6-4479a1.svg)](https://www.mysql.com/)

</div>

---

## 简介

CCCMS 是基于 **Webman 2.x（PHP 常驻内存框架）+ Vue 3** 的中后台管理系统，可直接作为企业级项目的起点或二次开发底座。

后端为插件化架构（`plugin/cccms` 为框架级基础功能，业务功能一律新建插件），前端为 Vue 3 + Vite + TypeScript + Element Plus + Tailwind CSS 4，内置 RBAC、数据权限、多租户、菜单与按钮权限、回收站、代码生成器、定时任务、多存储驱动等能力。

它不是「功能够多」的脚手架，而是**把安全默认值做对**的工程约定 —— 漏配的代价是「不可用」而不是「被越权」：

- **fail-closed 鉴权**：控制器未声明权限注解直接拒绝，而不是静默放行。
- **唯一声明机制**：鉴权只有一种写法 —— PHP 8 原生属性，命名参数。
- **数据权限下沉到模型层**：行级范围由全局查询作用域注入，字段级出参 / 入参由 `toArray()` 与 `ScopedQuery` 处理，不依赖开发者记忆。
- **可校验的约定**：`perm-scan` / `data-scope-check` / `data-rule-check` / `write-guard-check` 把「容易漏掉的正确做法」变成 CI 能拦住的检查。

> 本仓库为全新工程，不迁移、不兼容老 CCCMS（ThinkPHP 8 版本）数据。

---

## 能力一览

细节见 [功能模块](docs/07-功能模块.md)；逐版本的变更见 [更新日志](docs/更新日志.md)。

| 领域 | 内容 |
|------|------|
| 认证与权限 | 账号密码登录 + 图形验证码、JWT（默认 2h，滑动续期）、4 个权限注解、RBAC（角色继承）、按钮级 `v-auth` 与路由级守卫、登录失败账号 + IP 双维度限流、密码策略、密码找回（邮箱 / 短信）、我的登录设备 |
| 数据权限 | 角色 5 档范围 + `sys_data_rule` 行级 / 字段级规则、受控表登记、模型层自动生效、规则体检 |
| 多租户 | 单库共享表 + `tenant_id`，租户为最外层硬边界，超管显式切换 |
| 组织 | 部门（无限级、多部门）、岗位及关联授权 |
| 系统能力 | 菜单 / 权限节点自动生成、键值配置、数据字典、软删除回收站、附件（四驱动 + 分片上传 / 秒传）、定时任务（六段 Cron、重叠保护、失败重试）、通知公告（定向投放、已读回执）、代码生成器、插件脚手架 |
| 日志与可观测 | 操作日志与登录日志同表、三层链路 ID（请求 / 操作 / 会话）、审计链防篡改校验、异步导出任务中心、`/healthz` 与 `/metrics` |
| 前端体验 | 动态路由、i18n（中英）、主题与设计 token、列头筛选 + 搜索卡片 + 筛选方案、大表格虚拟滚动、快捷导航与收藏、全局水印、富文本编辑器 |
| 工程化 | GitHub Actions、后端集成测试 + 前端单元测试、PHPStan、php-cs-fixer、Prettier / ESLint、依赖审计、Docker 多阶段构建 |

---

## 技术栈

| 层 | 技术 |
|----|------|
| 后端 | PHP >= 8.1、Webman 2.x、think-orm 4.x、MySQL >= 5.6（推荐 8.0）、Redis >= 5.0 |
| 前端 | Vue 3.5、Vite 5、TypeScript 5、Element Plus 2、Tailwind CSS 4、Pinia、ECharts 6、RemixIcon |
| 鉴权 | JWT（HS256）+ Redis（验证码 / 限流 / 配置缓存 / 会话分界线） |
| 部署 | 原生启动 / Docker（`Dockerfile` + `docker-compose.yml`） |

---

## 目录结构

```
cccms/
├── docs/          # 项目文档中心（导航见 docs/README.md）
├── server/        # 后端（Webman）
│   ├── app/       # 主应用（入口、中间件、进程）
│   ├── config/    # 全局配置
│   └── plugin/
│       ├── cccms/ # 框架级基础功能插件（核心）
│       └── index/ # 前台公开应用插件
├── frontend/      # 前端（Vue 3 + Vite + TS + Element Plus）
└── .github/       # CI 工作流（php.yml / frontend.yml）
```

完整目录职责与命名约定见 [03-目录结构](docs/03-目录结构.md)。

---

## 快速开始

### 环境要求

- PHP >= 8.1（推荐 8.3），扩展 `pdo`、`pdo_mysql`、`pcntl`；建议 `redis`、`event`
- MySQL >= 5.6（推荐 8.0）、Redis >= 5.0、Node.js >= 18

### 后端

```bash
cd server
composer install
cp .env.example .env           # 本地开发记得设 APP_DEBUG=true

# 建库后导入（库需自行创建，字符集 utf8mb4）
mysql -uroot -p cccms < plugin/cccms/db/schema.sql
mysql -uroot -p cccms < plugin/cccms/db/seed.sql

# 同步菜单与按钮节点
php webman cccms:menu-sync
php webman cccms:perm-scan

# 启动（默认 http://0.0.0.0:8787）
php start.php start            # Linux / macOS
php windows.php                # Windows（无定时任务进程）
```

### 前端

```bash
cd frontend
npm install
npm run dev      # http://localhost:5173，/api 由 Vite 代理到 8787
npm run build    # 产物 dist/
```

### 默认账号

| 账号 | 密码 |
|------|------|
| `admin` | `admin123` |

> 首次登录后请立即修改密码。

### Docker

`docker-compose.yml` 一键启动 **Webman + MySQL + Redis**，首次启动自动导入表结构与种子数据：

```bash
cd server
cp .env.example .env           # 必须设置 JWT_SECRET / DATA_ENCRYPT_KEY / DB_PASSWORD
docker compose up -d --build   # 暴露 8787

docker compose exec webman php webman cccms:menu-sync
docker compose exec webman php webman cccms:perm-scan
```

完整的安装、配置、生产部署与升级说明见 [02-安装部署](docs/02-安装部署.md)。

---

## 安全设计要点

| # | 约束 | 原因 |
|---|------|------|
| 1 | 数据权限字段名与操作符必须白名单 | 规则条件会进 SQL where，需封死注入面 |
| 2 | `public/storage` 禁止解析 PHP | 同域上传目录可执行脚本 = 任意代码执行 |
| 3 | 上传按扩展名 + 魔数双重校验，危险类型硬拒绝 | 防止存储型 XSS（`svg` / `html` 等） |
| 4 | 令牌短 TTL（2h）+ 滑动续期 + `jti` 黑名单 | 缩短 XSS 一旦发生后的可用窗口 |
| 5 | Token 与用户状态强关联 | 每请求从 DB 重建用户上下文，禁用 / 删除用户立即 401 |
| 6 | 登录失败按账号 + IP 双维度限流，密码策略强制 | 避免撞库、且防止攻击者用错误密码锁死他人账号 |
| 7 | 角色继承防环 + 限深（≤ 5 层） | `parent_id` 成环会让权限解析死循环 |

更多机制见 [框架介绍](docs/04-框架介绍.md)、[鉴权与权限](docs/05-鉴权与权限.md)、[数据权限](docs/06-数据权限.md)。

---

## 文档

| 文档 | 说明 |
|------|------|
| [docs/README.md](docs/README.md) | 文档地图 · 编写规范（各文件讲什么 / 不写什么） |
| [01-项目介绍](docs/01-项目介绍.md) | 定位、技术选型、功能总览、设计理念 |
| [02-安装部署](docs/02-安装部署.md) | 环境要求、安装、初始化、Docker、生产部署、升级 |
| [03-目录结构](docs/03-目录结构.md) | 前后端目录职责与命名约定 |
| [04-框架介绍](docs/04-框架介绍.md) | 插件化架构、请求生命周期、分层、核心组件 |
| [05-鉴权与权限](docs/05-鉴权与权限.md) | 注解、鉴权矩阵、RBAC、角色继承、前端 v-auth |
| [06-数据权限](docs/06-数据权限.md) | 数据档位、行级 / 字段级规则、受控表、多租户 |
| [07-功能模块](docs/07-功能模块.md) | 每个模块的功能、接口、页面、权限节点 |
| [08-后端开发规范](docs/08-后端开发规范.md) | 分层规范、编码约定、新增接口 / 插件步骤 |
| [09-前端开发规范](docs/09-前端开发规范.md) | 前端架构、路由、状态、主题、测试、开发约定 |
| [10-前端组件参考](docs/10-前端组件参考.md) | `ArtTable` / `useTable` 等组件与 Hooks API |
| [11-常用命令与运维](docs/11-常用命令与运维.md) | CLI 命令、Redis 键、配置项、备份、监控、安全加固 |
| [12-常见问题与排错](docs/12-常见问题与排错.md) | 按现象检索的 FAQ |
| [13-数据模型](docs/13-数据模型.md) | 27 张表的分组、字段语义、索引与软删除约定 |
| [更新日志](docs/更新日志.md) | 已交付的版本变更 + 已决策不做的事项 |
| [开发计划](docs/开发计划.md) | 待办清单（要做但尚未做的功能与质量项） |

---

## 常用命令

```bash
php webman cccms:perm-scan            # 扫描注解 → 校验 + 同步按钮节点（--check 只校验）
php webman cccms:menu-sync            # 同步 db/menu.php 的目录 / 菜单
php webman cccms:db-upgrade           # 已有库补新增表 / 列 / 索引（幂等）
php webman cccms:plugin-create shop   # 生成业务插件骨架并自动登记菜单
php webman cccms:update               # 从上游仓库同步框架更新（安全覆盖 + 自动备份）
php webman cccms:data-scope-check     # 校验数据权限接入
php webman cccms:data-rule-check      # 体检数据权限规则（有冲突时退出码 1）
php webman cccms:write-guard-check    # 校验写入口是否声明字段白名单
composer test                         # 后端测试（库不可用时相关用例自动跳过）
```

前端：

```bash
cd frontend
npm run lint           # ESLint
npm run format:check   # Prettier 格式校验
npm run test           # Vitest
npm run build          # vue-tsc --noEmit + vite build
```

---

## 贡献

欢迎提交 Issue 与 Pull Request。参与开发前请先阅读 [后端开发规范](docs/08-后端开发规范.md)、[前端开发规范](docs/09-前端开发规范.md) 与 [文档编写规范](docs/README.md#二编写规范)。

提交前确保以下检查通过（CI 同样执行）：

```bash
cd server
php webman cccms:perm-scan --check
php webman cccms:data-scope-check
php webman cccms:data-rule-check
php webman cccms:write-guard-check
composer analyse && composer cs:check
composer test

cd ../frontend
npm run format:check && npm run lint && npm run i18n:check && npm run test:coverage && npm run build
```

---

## 开源协议

本项目基于 [MIT License](server/LICENSE) 开源。

## 致谢

- [Webman](https://www.workerman.net/) —— 高性能 PHP 常驻内存框架
- [think-orm](https://github.com/top-think/think-orm) —— 强大的 ORM
- [Element Plus](https://element-plus.org/) —— 优秀的 Vue 3 组件库
- [Vue.js](https://vuejs.org/) / [Vite](https://vitejs.dev/) / [Tailwind CSS](https://tailwindcss.com/) —— 现代前端工具链
