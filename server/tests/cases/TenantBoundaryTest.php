<?php

declare(strict_types=1);

use plugin\cccms\support\TenantContext;
use think\facade\Db;

/**
 * R-05：租户硬边界的常驻用例。
 *
 * 覆盖四条**可在 CLI 下确定性验证**的边界机制：
 *   1. 受控表名单（`TenantContext::TABLES`）与模型 `$tenantScope` 声明**严格一致**
 *      —— 名单漏登记会造成「直查那条路绕过租户隔离」，多登记则会让本不该隔离的表被过滤；
 *   2. `usable()` 的四类判定（平台 / 不存在 / 停用 / 过期），它决定「租户停用后下一次请求即 401」；
 *   3. 写入侧**剔除客户端传入的 `tenant_id`**（防越权把数据写进别的租户）；
 *   4. `username` 等**全局唯一键跨租户不可重名**（租户内查重会撞唯一索引）。
 *
 * > 说明：请求级「A 租户看不到 B 租户数据」的端到端验证需要伪造 HTTP 请求上下文
 * > （`TenantContext::currentTenantId()` 取自 `request()->user`），零依赖测试运行器
 * > 构造不出该上下文 —— 这条链路由 `AuthMatrixTest`（鉴权矩阵）+ `DataScopeIntegrationTest`
 * > （三档基线）+ `cccms:data-scope-check`（静态检查）共同兜住。
 */
return static function (): void {
    suite('租户边界（硬边界机制）');

    // 所有插件的模型目录：业务插件表（cms_* / member_*）同样登记在 TABLES 里，
    // 只扫主插件会让「名单」与「模型声明」两边口径不一致
    $modelDirs = glob(dirname(__DIR__, 2) . '/plugin/*/app/model', GLOB_ONLYDIR) ?: [];

    /** 清理用例数据（租户行 + 两个同名用户） */
    $cleanup = static function (): void {
        Db::name('user')->where('username', '__itest__tenant_dup')->delete();
        Db::name('user')->where('username', '__itest__tenant_ok')->delete();
        Db::name('tenant')->where('code', 'like', '__itest__%')->delete();
    };

    test('受控表名单与模型 $tenantScope 声明严格一致', function () use ($modelDirs): void {
        $declared = [];
        foreach ($modelDirs as $modelDir) {
            foreach (glob($modelDir . '/*.php') ?: [] as $file) {
                $src = (string)file_get_contents($file);
                if (!preg_match('/\$tenantScope\s*=\s*true\s*;/', $src)) {
                    continue;
                }
                // `$name` 会被拼上连接前缀；插件表必须用 `$table` 写完整表名，两种都要认
                if (preg_match('/protected\s+\$(?:name|table)\s*=\s*\'([a-z_]+)\'/', $src, $m)) {
                    $declared[] = $m[1];
                }
            }
        }
        sort($declared);

        $listed = TenantContext::TABLES;
        sort($listed);

        same(
            $listed,
            $declared,
            'TenantContext::TABLES 与模型 $tenantScope = true 的集合必须一致：'
            . '漏登记 → 直查绕过租户隔离；多登记 → 不参与隔离的表被误过滤'
        );
        same(
            count($listed),
            count(array_unique($listed)),
            'TABLES 名单不应有重复项'
        );
    });

    test('usable()：平台租户恒可用，不存在 / 停用 / 过期均不可用', function () use ($cleanup): void {
        try {
            ok(TenantContext::usable(TenantContext::PLATFORM_ID), '平台租户（0）恒可用');
            ok(TenantContext::usable(-1), '非法 id 按平台处理，不应抛错');

            ok(!TenantContext::usable(99999999), '不存在的租户不可用');

            $activeId = (int)Db::name('tenant')->insertGetId([
                'name' => '__itest__active', 'code' => '__itest__active', 'contact' => '', 'phone' => '',
                'status' => 1, 'expire_at' => null, 'remark' => 'integration test',
                'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
            ]);
            ok(TenantContext::usable($activeId), '启用且未过期的租户可用');

            Db::name('tenant')->where('id', $activeId)->update(['status' => 0]);
            ok(!TenantContext::usable($activeId), '停用的租户不可用');

            Db::name('tenant')->where('id', $activeId)->update([
                'status' => 1,
                'expire_at' => date('Y-m-d H:i:s', time() - 60),
            ]);
            ok(!TenantContext::usable($activeId), '已过期的租户不可用');

            Db::name('tenant')->where('id', $activeId)->update([
                'expire_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            ok(TenantContext::usable($activeId), '未到期的租户可用');
        } finally {
            $cleanup();
        }
    }, true);

    test('写入侧剔除客户端传入的 tenant_id（无用户上下文时不回填）', function (): void {
        // CLI 无用户上下文：tenant_id 被剔除且不回填，由列默认值 0（平台租户）兜底
        $stamped = TenantContext::stamp('user', ['username' => 'x', 'tenant_id' => 999]);
        ok(!array_key_exists('tenant_id', $stamped), 'stamp() 必须剔除客户端传入的 tenant_id');

        $data = ['username' => 'x', 'tenant_id' => 999];
        TenantContext::applyWriteRules($data, true);
        ok(!array_key_exists('tenant_id', $data), 'applyWriteRules() 必须剔除客户端传入的 tenant_id');

        // 不参与隔离的表：原样返回，不被误伤
        $plain = ['tenant_id' => 999, 'title' => '菜单'];
        same($plain, TenantContext::stamp('menu', $plain), '未参与隔离的表不该被改写');
        same($plain, TenantContext::stamp('config', $plain), '配置表不该被改写');

        ok(TenantContext::participates('user'), 'user 参与租户隔离');
        ok(!TenantContext::participates('menu'), 'menu 是平台级资源，不参与租户隔离');
        ok(!TenantContext::participates('log'), '日志是平台级资源，不参与租户隔离');
        ok(TenantContext::isPlatform(), 'CLI（无用户上下文）按平台租户处理');
    });

    test('username 是全局唯一键：跨租户重名同样被库拒绝', function () use ($cleanup): void {
        try {
            $cleanup();
            $now = date('Y-m-d H:i:s');

            Db::name('user')->insert([
                'tenant_id' => 1, 'username' => '__itest__tenant_dup', 'password' => 'x',
                'nickname' => '', 'status' => 1, 'create_time' => $now, 'update_time' => $now,
            ]);

            $threw = false;
            try {
                // 换一个租户插同名账号：uk_username 不含 tenant_id，必须冲突
                Db::name('user')->insert([
                    'tenant_id' => 2, 'username' => '__itest__tenant_dup', 'password' => 'x',
                    'nickname' => '', 'status' => 1, 'create_time' => $now, 'update_time' => $now,
                ]);
            } catch (Throwable) {
                $threw = true;
            }

            ok($threw, '跨租户同名账号应被唯一索引拒绝 —— 登录链路不解析租户，账号必须全局唯一');
        } finally {
            $cleanup();
        }
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
