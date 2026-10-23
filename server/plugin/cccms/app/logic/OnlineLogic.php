<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\I18n;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\Realtime;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\UserContext;

/**
 * 在线用户 / 强制下线。
 *
 * 数据来自 Redis 里的会话索引（见 `OnlineSession`），不查数据库。
 *
 * 「下线」要在两处同时生效，缺一不可：
 *   1. `OnlineSession::remove*()` —— 让它从在线列表消失；
 *   2. `TokenBlacklist` —— 让**已经发出去的令牌**立刻失效；
 * 只做第 1 步的话，对方手里的 token 依然能用，属于「假下线」。
 */
final class OnlineLogic
{
    public static function paginate(array $params): array
    {
        return OnlineSession::paginate(
            [
                'username' => (string)($params['username'] ?? ''),
                'ip'       => (string)($params['ip'] ?? ''),
                // 三组时间范围（登录 / 最后活跃 / 过期），各自独立
                'login_start'  => (string)($params['login_start'] ?? ''),
                'login_end'    => (string)($params['login_end'] ?? ''),
                'active_start' => (string)($params['active_start'] ?? ''),
                'active_end'   => (string)($params['active_end'] ?? ''),
                'expire_start' => (string)($params['expire_start'] ?? ''),
                'expire_end'   => (string)($params['expire_end'] ?? ''),
            ],
            max(1, (int)($params['page'] ?? 1)),
            max(1, (int)($params['limit'] ?? 15)),
            is_string($params['sort'] ?? null) ? trim($params['sort']) : '',
            strtolower(is_string($params['order'] ?? null) ? trim($params['order']) : ''),
        );
    }

    /** 在线用户数（去重：同一账号多端登录只算 1 个；工作台卡片用） */
    public static function count(): int
    {
        return OnlineSession::countUsers();
    }

    /**
     * 强制下线单个会话。
     *
     * @param string $jti      目标会话（不能是自己，否则操作者会被立刻踢出去）
     * @param string $selfJti  当前请求的会话 ID
     */
    public static function kick(string $jti, string $selfJti): void
    {
        if ($jti === '') {
            throw new ApiException(I18n::t('online.session_required'), 422);
        }
        if ($selfJti !== '' && $jti === $selfJti) {
            throw new ApiException(I18n::t('online.cannot_kick_self_session'), 422);
        }

        // 精确作废：用会话里登记的 exp 计算黑名单 TTL，不留多余垃圾
        $ownerId = OnlineSession::ownerOf($jti);
        TokenBlacklist::revoke($jti, OnlineSession::expiryOf($jti));
        OnlineSession::remove($jti);

        // 实时催一下：不带 jti 的话，同一账号在别的设备上的会话也会被前端误登出
        if ($ownerId > 0) {
            Realtime::publish($ownerId, 'logout', ['reason' => 'kicked', 'jti' => $jti]);
        }
    }

    /**
     * 强制某个用户的所有会话下线。
     *
     * @return int 影响到的在线会话数
     */
    public static function kickUser(int $userId, UserContext $operator): int
    {
        if ($userId <= 0) {
            throw new ApiException(I18n::t('online.user_required'), 422);
        }
        if ($userId === $operator->id) {
            throw new ApiException(I18n::t('online.cannot_kick_self'), 422);
        }

        // 用户级时间分界线：该用户此前签发的令牌全部作废（比逐条 jti 更彻底）。
        // TTL 精确到「最晚过期令牌的剩余时长」，让分界线键到期即消失，不残留。
        $maxExp = OnlineSession::maxExpiryOfUser($userId);
        TokenBlacklist::revokeUser($userId, 0, $maxExp > 0 ? $maxExp - time() : 0);

        $removed = OnlineSession::removeUser($userId);

        // 该用户所有设备一起下线，所以不带 jti：前端收到就清本地登录态
        Realtime::publish($userId, 'logout', ['reason' => 'kicked_all']);

        return $removed;
    }

    /** 清理已过期会话的索引残留（可挂到定时任务） */
    public static function prune(): int
    {
        return OnlineSession::prune();
    }
}
