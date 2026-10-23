<?php

declare(strict_types=1);

use plugin\cccms\app\logic\DashboardLogic;
use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\support\UserContext;

return static function (): void {
    suite('工作台统计（合成单条 SQL）');

    test('计数与逐项查询一致（超管视角）', function (): void {
        $user   = new UserContext(id: 1, username: 'tester', superAdmin: true, tenantId: 0, homeTenantId: 0);
        $counts = DashboardLogic::stats($user)['counts'];

        same((int)User::where('status', 1)->count(), $counts['user'] ?? -1, '用户数应与逐项查询一致');
        same((int)Role::where('status', 1)->count(), $counts['role'] ?? -1, '角色数应与逐项查询一致');
        same((int)DataRule::count(), $counts['data_rule'] ?? -1, '规则数应与逐项查询一致');

        foreach (['user', 'role', 'dept', 'post', 'menu', 'file', 'log', 'today_log', 'dict', 'crontab', 'data_rule', 'tenant'] as $key) {
            ok(isset($counts[$key]), "超管必须拿到 {$key} 计数");
            ok(is_int($counts[$key]), "{$key} 必须是整数（前端据此直接比较）");
            ok($counts[$key] >= 0, "{$key} 不能是负数");
        }
    }, true);

    test('无权限节点时不下发对应计数', function (): void {
        $user   = new UserContext(id: 2, username: 'limited', superAdmin: false, permissions: [], tenantId: 0, homeTenantId: 0);
        $counts = DashboardLogic::stats($user)['counts'];

        ok(!isset($counts['user']), '没有 cccms:user:index 时不应下发用户数');
        ok(!isset($counts['tenant']), '没有 cccms:tenant:index 时不应下发租户数');
        // 未读消息对「登录即可」，与权限节点无关
        ok(isset($counts['notice_unread']), '未读消息数应始终下发');
    }, true);
};
