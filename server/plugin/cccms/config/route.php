<?php

use plugin\cccms\app\controller\AuthController;
use plugin\cccms\app\controller\ConfigController;
use plugin\cccms\app\controller\CrontabController;
use plugin\cccms\app\controller\DashboardController;
use plugin\cccms\app\controller\DataRuleController;
use plugin\cccms\app\controller\DataScopeTableController;
use plugin\cccms\app\controller\DeptController;
use plugin\cccms\app\controller\DictController;
use plugin\cccms\app\controller\ExportController;
use plugin\cccms\app\controller\FileController;
use plugin\cccms\app\controller\GeneratorController;
use plugin\cccms\app\controller\LogController;
use plugin\cccms\app\controller\MaintenanceController;
use plugin\cccms\app\controller\MenuController;
use plugin\cccms\app\controller\NoticeController;
use plugin\cccms\app\controller\ObservabilityController;
use plugin\cccms\app\controller\OnlineController;
use plugin\cccms\app\controller\PostController;
use plugin\cccms\app\controller\ProfileController;
use plugin\cccms\app\controller\RecycleController;
use plugin\cccms\app\controller\RoleController;
use plugin\cccms\app\controller\TenantController;
use plugin\cccms\app\controller\UpgradeController;
use plugin\cccms\app\controller\UserController;
use Webman\Route;

// 禁用默认路由（/controller/action 自动路由），只允许显式路由
Route::disableDefaultRoute();

// ---- 认证 ----
Route::get('/ping', [AuthController::class, 'ping']);

// ---- 可观测性（均 #[NoLogin]，靠 security.metrics_allow_ips 白名单收敛，非白名单返回 404）----
// /ping 是「存活探针」（不碰外部依赖），/healthz 是「就绪探针」（依赖故障 503）
Route::get('/healthz', [ObservabilityController::class, 'healthz']);
// /metrics 输出 Prometheus 文本格式，供抓取端拉取
Route::get('/metrics', [ObservabilityController::class, 'metrics']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/captcha', [AuthController::class, 'captcha']);
// 找回密码（匿名）：发送验证码 / 用验证码重置
Route::post('/auth/password/sendCode', [AuthController::class, 'sendResetCode']);
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);
Route::get('/auth/me', [AuthController::class, 'me']);
// 租户切换：候选列表（超管，非超管返回空）与切换（重签带 tid 的令牌）
Route::get('/auth/tenants', [AuthController::class, 'tenants']);
Route::post('/auth/switchTenant', [AuthController::class, 'switchTenant']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

// ---- 个人中心（登录即可；前端是静态路由，不进左侧菜单） ----
Route::get('/profile', [ProfileController::class, 'index']);
Route::post('/profile/update', [ProfileController::class, 'update']);
Route::post('/profile/password', [ProfileController::class, 'password']);
// 我的登录设备（自助查看 / 注销自己的会话）
Route::get('/profile/sessions', [ProfileController::class, 'sessions']);
Route::post('/profile/sessions/revoke', [ProfileController::class, 'revokeSession']);

// ---- 工作台 ----
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

// ---- 用户 ----
Route::get('/user', [UserController::class, 'index']);
Route::get('/user/read', [UserController::class, 'read']);
Route::post('/user/save', [UserController::class, 'save']);
Route::post('/user/update', [UserController::class, 'update']);
Route::post('/user/delete', [UserController::class, 'delete']);
// 批量操作（越权 / 受保护的行自动跳过并在响应里回报）
Route::post('/user/batchStatus', [UserController::class, 'batchStatus']);
Route::post('/user/batchDelete', [UserController::class, 'batchDelete']);
Route::post('/user/batchAssign', [UserController::class, 'batchAssign']);
Route::post('/user/resetPassword', [UserController::class, 'resetPassword']);

// ---- 角色 ----
Route::get('/role', [RoleController::class, 'index']);
Route::get('/role/tree', [RoleController::class, 'tree']);
Route::get('/role/read', [RoleController::class, 'read']);
Route::post('/role/save', [RoleController::class, 'save']);
Route::post('/role/update', [RoleController::class, 'update']);
Route::post('/role/delete', [RoleController::class, 'delete']);
// 复制角色（含档位与节点授权；status=0 时即「另存为模板」）
Route::post('/role/copy', [RoleController::class, 'copy']);

// ---- 菜单 ----
Route::get('/menu/tree', [MenuController::class, 'tree']);
Route::get('/menu/userTree', [MenuController::class, 'userTree']);
Route::post('/menu/save', [MenuController::class, 'save']);
Route::post('/menu/update', [MenuController::class, 'update']);
Route::post('/menu/delete', [MenuController::class, 'delete']);

// ---- 部门 ----
Route::get('/dept/tree', [DeptController::class, 'tree']);
Route::post('/dept/save', [DeptController::class, 'save']);
Route::post('/dept/update', [DeptController::class, 'update']);
Route::post('/dept/delete', [DeptController::class, 'delete']);

// ---- 岗位 ----
Route::get('/post', [PostController::class, 'index']);
Route::post('/post/save', [PostController::class, 'save']);
Route::post('/post/update', [PostController::class, 'update']);
Route::post('/post/delete', [PostController::class, 'delete']);
// 批量操作（下挂用户的岗位、越权行自动跳过并在响应里回报）
Route::post('/post/batchStatus', [PostController::class, 'batchStatus']);
Route::post('/post/batchDelete', [PostController::class, 'batchDelete']);

// ---- 字典 ----
Route::get('/dict', [DictController::class, 'index']);
Route::post('/dict/save', [DictController::class, 'save']);
Route::post('/dict/update', [DictController::class, 'update']);
Route::post('/dict/delete', [DictController::class, 'delete']);
Route::get('/dict/data', [DictController::class, 'data']);
Route::post('/dict/saveData', [DictController::class, 'saveData']);
Route::post('/dict/updateData', [DictController::class, 'updateData']);
Route::post('/dict/deleteData', [DictController::class, 'deleteData']);
// 批量操作（不可见 / 已删除的 id 自动跳过并在响应里回报）
Route::post('/dict/batchStatus', [DictController::class, 'batchStatus']);
Route::post('/dict/batchDelete', [DictController::class, 'batchDelete']);
Route::post('/dict/batchStatusData', [DictController::class, 'batchStatusData']);
Route::post('/dict/batchDeleteData', [DictController::class, 'batchDeleteData']);
// 字典分类
Route::get('/dict/category', [DictController::class, 'category']);
Route::post('/dict/category/save', [DictController::class, 'categorySave']);
Route::post('/dict/category/update', [DictController::class, 'categoryUpdate']);
Route::post('/dict/category/delete', [DictController::class, 'categoryDelete']);

// ---- 配置 ----
Route::get('/config', [ConfigController::class, 'index']);
// 前端初始化 / 登录页需要的公开配置（品牌信息 + UI 默认值）
Route::get('/config/ui', [ConfigController::class, 'ui']);
Route::post('/config/save', [ConfigController::class, 'save']);

// ---- 系统维护（顶栏「同步 / 清理缓存」，等价于 menu-sync + perm-scan + 清缓存） ----
Route::post('/system/refresh', [MaintenanceController::class, 'refresh']);

// ---- 数据权限规则 ----
Route::get('/data_rule', [DataRuleController::class, 'index']);
Route::get('/data_rule/options', [DataRuleController::class, 'options']);
Route::get('/data_rule/users', [DataRuleController::class, 'users']);
Route::post('/data_rule/save', [DataRuleController::class, 'save']);
Route::post('/data_rule/update', [DataRuleController::class, 'update']);
Route::post('/data_rule/delete', [DataRuleController::class, 'delete']);
// 受控表（哪些表可以配数据权限）
Route::get('/data_rule/table', [DataScopeTableController::class, 'index']);
Route::post('/data_rule/table/save', [DataScopeTableController::class, 'save']);
Route::post('/data_rule/table/update', [DataScopeTableController::class, 'update']);
Route::post('/data_rule/table/delete', [DataScopeTableController::class, 'delete']);

// ---- 租户（平台级：只有**处于平台租户**的超管能操作，见 TenantLogic::assertPlatformAdmin）----
// 切换租户的接口在 auth 段（/auth/tenants、/auth/switchTenant），因为它是登录会话的一部分
Route::get('/tenant', [TenantController::class, 'index']);
Route::get('/tenant/read', [TenantController::class, 'read']);
Route::post('/tenant/save', [TenantController::class, 'save']);
Route::post('/tenant/update', [TenantController::class, 'update']);
Route::post('/tenant/delete', [TenantController::class, 'delete']);

// ---- 回收站（列表走各模块自己的接口 + trashed=1，这里只有写操作）----
Route::post('/recycle/restore', [RecycleController::class, 'restore']);
Route::post('/recycle/delete', [RecycleController::class, 'delete']);

// ---- 日志 ----
Route::get('/log', [LogController::class, 'index']);
// 列表已裁掉 params / result / ua，详情按 id 取全文（登录即可，数据范围兜底）
Route::get('/log/detail', [LogController::class, 'detail']);
Route::get('/log/export', [LogController::class, 'export']);
// 导出任务中心（P2-7）：本人的异步导出任务列表 + 归档文件下载
Route::get('/export/task/list', [ExportController::class, 'list']);
Route::get('/export/task/download', [ExportController::class, 'download']);

// 请求级：按 trace_id 查单条（报错定位）
Route::get('/log/trace', [LogController::class, 'trace']);
// 操作级：按 action_id 查一次操作的全部请求（链路视图）
Route::get('/log/action', [LogController::class, 'action']);
// 会话级：按 session_id 查一次页面会话的轨迹（审计，游标分页）
Route::get('/log/session', [LogController::class, 'session']);
// 登录安全分析（失败趋势 / TOP 用户名 / TOP IP / 异地登录）
Route::get('/log/login/analysis', [LogController::class, 'loginAnalysis']);
Route::post('/log/delete', [LogController::class, 'delete']);

// ---- 在线用户（Redis 会话索引 + 强制下线） ----
Route::get('/online', [OnlineController::class, 'index']);
Route::post('/online/kick', [OnlineController::class, 'kick']);
Route::post('/online/kickUser', [OnlineController::class, 'kickUser']);

// ---- 通知公告 ----
// 管理侧
Route::get('/notice', [NoticeController::class, 'index']);
Route::get('/notice/read', [NoticeController::class, 'read']);
Route::post('/notice/save', [NoticeController::class, 'save']);
Route::post('/notice/update', [NoticeController::class, 'update']);
Route::post('/notice/delete', [NoticeController::class, 'delete']);
// 定向投放候选（部门 / 角色 / 用户搜索）与已读回执报表
Route::get('/notice/options', [NoticeController::class, 'options']);
Route::get('/notice/users', [NoticeController::class, 'users']);
Route::get('/notice/report', [NoticeController::class, 'report']);
// 阅读侧（登录即可，只看自己的）
Route::get('/notice/my', [NoticeController::class, 'my']);
Route::get('/notice/unread', [NoticeController::class, 'unread']);
Route::post('/notice/markRead', [NoticeController::class, 'markRead']);
Route::post('/notice/markAllRead', [NoticeController::class, 'markAllRead']);

// ---- 附件 ----
Route::get('/file', [FileController::class, 'index']);
Route::post('/file/upload', [FileController::class, 'upload']);
// 分片上传（P2-9）：init（可秒传）→ chunk（可续传）→ complete（合并入库）
Route::post('/file/upload/init', [FileController::class, 'uploadInit']);
Route::post('/file/upload/chunk', [FileController::class, 'uploadChunk']);
Route::post('/file/upload/complete', [FileController::class, 'uploadComplete']);
Route::post('/file/delete', [FileController::class, 'delete']);
Route::post('/file/move', [FileController::class, 'move']);
// 附件公开状态（单个）：私有附件直链失效，只能登录后经 /file/download 读取
Route::post('/file/public', [FileController::class, 'setPublic']);
Route::get('/file/download', [FileController::class, 'download']);
// 附件分类
Route::get('/file/category', [FileController::class, 'category']);
Route::post('/file/category/save', [FileController::class, 'categorySave']);
Route::post('/file/category/update', [FileController::class, 'categoryUpdate']);
Route::post('/file/category/delete', [FileController::class, 'categoryDelete']);

// ---- 定时任务 ----
Route::get('/crontab', [CrontabController::class, 'index']);
Route::get('/crontab/targets', [CrontabController::class, 'targets']);
Route::get('/crontab/logs', [CrontabController::class, 'logs']);
Route::post('/crontab/save', [CrontabController::class, 'save']);
Route::post('/crontab/update', [CrontabController::class, 'update']);
Route::post('/crontab/delete', [CrontabController::class, 'delete']);
Route::post('/crontab/run', [CrontabController::class, 'run']);

// ---- 代码生成器 ----
// 可用性探针：页面据此渲染「未启用」提示，避免点进去才吃 403 弹窗
Route::get('/generator/status', [GeneratorController::class, 'status']);
Route::get('/generator/tables', [GeneratorController::class, 'tables']);
Route::get('/generator/columns', [GeneratorController::class, 'columns']);
Route::post('/generator/preview', [GeneratorController::class, 'preview']);
Route::post('/generator/generate', [GeneratorController::class, 'generate']);

// ---- 自动升级（从上游镜像同步框架代码） ----
Route::get('/upgrade', [UpgradeController::class, 'index']);
Route::get('/upgrade/check', [UpgradeController::class, 'check']);
Route::get('/upgrade/tags', [UpgradeController::class, 'tags']);
Route::post('/upgrade/init', [UpgradeController::class, 'init']);
Route::post('/upgrade/run', [UpgradeController::class, 'run']);

// ---- 代码生成器产出的模块路由（config/route/*.php） ----
foreach (glob(__DIR__ . '/route/*.php') ?: [] as $__routeFile) {
    require $__routeFile;
}
