<?php

declare(strict_types=1);

use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\TokenBlacklist;
use support\Redis;

/**
 * 在线会话（Redis）常驻用例。
 *
 * 重点覆盖三处「最容易写错、出错代价最高」的语义：
 *   1. `rotate()` 必须把会话迁到新 jti —— 否则管理员踢掉的是已废弃的旧令牌；
 *   2. 同终端重复登录必须退掉旧会话 —— 否则列表里出现两条同一设备的记录；
 *   3. `remove()` / `removeUser()` 之后归属查询必须查不到。
 *
 * 依赖 Redis；不可用时跳过（CI 设了 `CCCMS_TEST_REQUIRE_SERVICES=1` 会判失败）。
 */
return static function (): void {
    suite('在线会话（OnlineSession）');

    $redisReady = static function (): bool {
        try {
            Redis::get('cccms:test:alive');

            return true;
        } catch (Throwable) {
            return false;
        }
    };

    /** 用例专用 jti 前缀，避免与真实在线会话互相污染 */
    $prefix = 'itest-' . bin2hex(random_bytes(4)) . '-';
    $userId = 90001;

    /** 每个用例开始前清掉本前缀的残留（上一个用例失败时可能没清理干净） */
    $reset = static function () use ($redisReady, $prefix, $userId): bool {
        if (!$redisReady()) {
            return false;
        }
        try {
            foreach (Redis::keys('cccms:online:s:' . $prefix . '*') ?: [] as $key) {
                Redis::del($key);
            }
            OnlineSession::removeUser($userId);
        } catch (Throwable) {
            return false;
        }

        return true;
    };

    test('登记后可按 jti 查归属，并出现在该用户的会话列表', function () use ($reset, $prefix, $userId): void {
        if (!$reset()) {
            skipNeedsService('会话登记', 'Redis 不可用');

            return;
        }

        $jti = $prefix . 'a';
        OnlineSession::register($userId, 'itest_online', '测试', $jti, '203.0.113.9', 'UA-Test/1.0');

        same($userId, OnlineSession::ownerOf($jti), 'ownerOf 应返回登记的用户 id');
        ok(OnlineSession::expiryOf($jti) > time(), '会话应带未来的过期时间');

        $sessions = OnlineSession::listOfUser($userId);
        ok(count($sessions) >= 1, '该用户至少有一条会话');
        same($jti, (string)($sessions[0]['jti'] ?? ''), '列表首条应是刚登记的会话');

        OnlineSession::remove($jti);
        same(0, OnlineSession::ownerOf($jti), 'remove 后归属应为 0');
    });

    test('滑动续期：会话迁到新 jti，旧 jti 立即失效', function () use ($reset, $prefix, $userId): void {
        if (!$reset()) {
            skipNeedsService('会话续期迁移', 'Redis 不可用');

            return;
        }

        $old = $prefix . 'old';
        $new = $prefix . 'new';
        $exp = time() + 600;
        OnlineSession::register($userId, 'itest_online', '测试', $old, '203.0.113.9', 'UA-Test/1.0');

        ok(OnlineSession::rotate($old, $new, $exp), '旧会话存在时应迁移成功');
        same($userId, OnlineSession::ownerOf($new), '新 jti 应归属同一用户');
        same(0, OnlineSession::ownerOf($old), '旧 jti 必须被删除，否则踢人踢的是废弃令牌');

        ok(!OnlineSession::rotate($old, $prefix . 'x', $exp), '旧会话不存在时迁移失败（调用方不应下发新令牌）');
        ok(!OnlineSession::rotate('', $new, $exp), '空 jti 直接拒绝');
        ok(!OnlineSession::rotate($new, $new, $exp), '同 jti 迁移无意义，应拒绝');

        OnlineSession::remove($new);
    });

    test('同终端重复登录：旧会话被退掉并进黑名单', function () use ($reset, $prefix, $userId): void {
        if (!$reset()) {
            skipNeedsService('同终端去重', 'Redis 不可用');

            return;
        }

        $old = $prefix . 'dev1';
        $new = $prefix . 'dev2';
        OnlineSession::register($userId, 'itest_online', '测试', $old, '203.0.113.9', 'UA-Same/1.0');
        $oldExp = OnlineSession::expiryOf($old);

        OnlineSession::register($userId, 'itest_online', '测试', $new, '203.0.113.9', 'UA-Same/1.0');

        same(0, OnlineSession::ownerOf($old), '同终端旧会话应从在线列表消失');
        same($userId, OnlineSession::ownerOf($new), '新会话应正常登记');
        ok(TokenBlacklist::isRevoked($old), '旧令牌必须进黑名单 —— 只从列表消失但令牌仍可用是最坏结果');

        // 清理：撤销黑名单标记，避免影响其它用例
        Redis::del('cccms:token:bl:' . $old);
        OnlineSession::remove($new);
    });

    test('不同终端（UA 不同）互不影响', function () use ($reset, $prefix, $userId): void {
        if (!$reset()) {
            skipNeedsService('多终端并存', 'Redis 不可用');

            return;
        }

        $pc = $prefix . 'pc';
        $phone = $prefix . 'phone';
        OnlineSession::register($userId, 'itest_online', '测试', $pc, '203.0.113.9', 'UA-PC/1.0');
        OnlineSession::register($userId, 'itest_online', '测试', $phone, '203.0.113.9', 'UA-Phone/2.0');

        same($userId, OnlineSession::ownerOf($pc), 'PC 会话不应被清理');
        same($userId, OnlineSession::ownerOf($phone), '手机会话应正常登记');
        ok(count(OnlineSession::listOfUser($userId)) >= 2, '同一用户应有两条会话');

        OnlineSession::remove($pc);
        OnlineSession::remove($phone);
    });

    test('removeUser 清空该用户的全部会话', function () use ($reset, $prefix, $userId): void {
        if (!$reset()) {
            skipNeedsService('强制下线', 'Redis 不可用');

            return;
        }

        $a = $prefix . 'u1';
        $b = $prefix . 'u2';
        OnlineSession::register($userId, 'itest_online', '测试', $a, '203.0.113.9', 'UA-A/1.0');
        OnlineSession::register($userId, 'itest_online', '测试', $b, '203.0.113.9', 'UA-B/1.0');

        $removed = OnlineSession::removeUser($userId);
        ok($removed >= 2, '应返回清理的会话条数');
        same(0, OnlineSession::ownerOf($a), '会话 A 应被清理');
        same(0, OnlineSession::ownerOf($b), '会话 B 应被清理');
        same([], OnlineSession::listOfUser($userId), '列表应为空');
    });

    test('非法入参不写 Redis：空 jti / 非正用户 id', function () use ($reset): void {
        if (!$reset()) {
            skipNeedsService('入参守卫', 'Redis 不可用');

            return;
        }

        OnlineSession::register(0, 'x', 'x', 'itest-invalid-0', '1.1.1.1', 'UA');
        OnlineSession::register(1, 'x', 'x', '', '1.1.1.1', 'UA');

        same(0, OnlineSession::ownerOf('itest-invalid-0'), 'userId<=0 不应登记');
        same(0, OnlineSession::ownerOf(''), '空 jti 不应登记');
    });
};
