# CCCMS 后端（Webman）

后台服务的代码主体：Webman 进程 + `plugin/cccms` 插件（框架级基础功能）+ `plugin/index`（前台公开站点）。
完整的环境要求、安装与部署步骤见 [02-安装部署](../docs/02-安装部署.md)，目录职责见 [03-目录结构](../docs/03-目录结构.md)。

## 常用命令

在 `server/` 目录下执行：

| 目的 | 命令 |
|------|------|
| 静态分析 | `composer analyse` |
| 代码风格检查 / 修复 | `composer cs:check` / `composer cs:fix` |
| 单元与集成测试 | `composer test`（库不可用时相关用例自动跳过） |
| 启动开发服务 | `php webman start`（Linux/macOS）或 `windows.bat` |
| 初始化数据库 | `php webman cccms:db-upgrade` |
| 同步菜单 / 权限节点 | `php webman cccms:menu-sync` + `php webman cccms:perm-scan` |

命令全量与说明见 [11-常用命令与运维](../docs/11-常用命令与运维.md)。

## 关键约定

- 业务代码一律放 `plugin/<插件>/`，`app/` 只保留框架骨架。
- 控制器只做参数校验与响应组装，DB 操作收口在 `logic/`。
- 新增接口必须声明鉴权注解；新增写接口必须在 `FilterInput` 声明字段白名单。
- 数据权限读取由模型层注入，写侧越权判定显式写在 Logic 里（见 `docs/08`）。
