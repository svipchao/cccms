<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\User;
use support\Log;
use support\Redis;
use Throwable;

/**
 * 密码找回（邮箱 / 短信）核心逻辑。
 *
 * 安全设计要点：
 *   1. **不泄露账号是否存在**：账号命中与否都返回同一句「若账号存在，验证码已发送」；
 *      发送间隔 / 每日上限按「账号字符串」计数，且都在账号查找**之前**执行，
 *      否则「第二次立刻被限流」本身就成了账号存在性的探针。
 *   2. **验证码只存 hash**：Redis 里保存 bcrypt 摘要（不是明文），键 `cccms:pwd_reset:code:{channel}:{userId}`，
 *      TTL 由 `security.reset_code_ttl` 控制；比对走 `password_verify`，成功后**一次性消费**。
 *   3. **校验尝试次数上限**：`security.reset_max_attempts`，达到上限即作废该验证码，防暴力猜 6 位码。
 *   4. **多渠道开关**：`security.reset_channel`（off/email/sms/both）决定允许的渠道，
 *      渠道自身再由 `mail.enabled` / `sms.enabled` 控制可用性；渠道不可用会**明确报错**
 *      （这条与账号无关，不构成信息泄露）。
 *   5. **重置成功作废该用户全部旧令牌**：用户级时间分界线（`TokenBlacklist::revokeUser`）+
 *      从在线列表移除（`OnlineSession::removeUser`），与「强制下线」同一套写法。
 *   6. 新口令复用 `PasswordPolicy`，并拒绝与旧口令相同。
 *
 * Redis 不可用时：发送链路 fail-closed（明确报「服务不可用」，对所有账号一致），
 * 校验链路一律视为验证码无效 —— 不因缓存故障放行。
 */
final class PasswordReset
{
    /** 支持的渠道 */
    public const CHANNELS = ['email', 'sms'];

    private const KEY_PREFIX = 'cccms:pwd_reset:';

    /**
     * 发送验证码。
     *
     * 命中账号且通道可用时真正发送；否则静默返回（响应统一，不区分账号是否存在）。
     *
     * @param string $account 用户名 / 邮箱 / 手机号
     * @param string $channel email | sms
     * @param string $ip      请求来源 IP（IP 维度限流用）
     */
    public static function sendCode(string $account, string $channel, string $ip = ''): void
    {
        self::assertChannel($channel);

        $account = trim($account);
        if ($account === '') {
            throw new ApiException(I18n::t('auth.reset_account_required'), 422);
        }

        // 未登录接口不受中间件限流（RateLimit 只在有用户上下文时计数），这里按 IP 补一层
        if ($ip !== '') {
            $verdict = RateLimiter::hit('pwd_reset_send:' . $ip, false);
            if (!$verdict['allowed']) {
                throw new ApiException(
                    I18n::t('common.too_many_requests', ['seconds' => $verdict['retry_after']]),
                    429
                );
            }
        }

        // 发送间隔与每日上限：before 账号查找，保证「存在」与「不存在」走同一分支
        self::reserveSend($channel, $account);

        $user = self::findUser($account);
        if ($user === null) {
            return;
        }

        $userId = (int)$user['id'];
        $target = $channel === 'email' ? (string)$user['email'] : (string)$user['phone'];
        if ($target === '') {
            // 未登记联系方式：不落码、不发送，响应保持统一
            return;
        }

        $code = self::generateCode();
        self::storeCode($userId, $channel, $code);

        if ($channel === 'email') {
            $sent = Mailer::send($target, self::mailSubject(), self::mailBody($code));
        } else {
            $sent = SmsSender::send($target, $code, SysConfig::getString('sms.sign_name'));
        }

        if (!$sent) {
            // 发送失败：清掉刚落的码，避免留下「用户收不到但可被猜」的验证码。
            // 同样静默返回，不因外部通道故障暴露账号是否存在（故障细节写日志）。
            self::clearCode($userId, $channel);
            Log::warning("password reset: {$channel} send failed for user {$userId}");
        }
    }

    /**
     * 用验证码重置密码。
     *
     * @throws ApiException 验证码无效 / 口令不合规 / 与旧口令相同
     */
    public static function reset(string $account, string $channel, string $code, string $password): void
    {
        self::assertChannel($channel);

        $account = trim($account);
        if ($account === '' || $code === '') {
            throw new ApiException(I18n::t('auth.reset_code_invalid'), 422);
        }

        $user = self::findUser($account);
        $valid = $user !== null && self::verifyCode((int)$user['id'], $channel, $code);
        if (!$valid) {
            throw new ApiException(I18n::t('auth.reset_code_invalid'), 422);
        }

        $userId = (int)$user['id'];

        // 口令策略：与新增 / 编辑 / 个人改密同一套（含弱口令、身份信息、字符类别）
        PasswordPolicy::assertValid($password, [
            'username' => (string)$user['username'],
            'nickname' => (string)$user['nickname'],
            'email'    => (string)$user['email'],
        ]);

        $oldHash = (string)User::withoutAllScopes('找回密码链路尚未建立用户上下文，按主键读口令摘要')->where('id', $userId)->value('password');
        if ($oldHash !== '' && password_verify($password, $oldHash)) {
            throw new ApiException(I18n::t('auth.reset_same_password'), 422);
        }

        User::withoutAllScopes('找回密码链路尚未建立用户上下文，按主键重置口令')->where('id', $userId)->update([
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        self::revokeSessions($userId);
    }

    // ------------------------------------------------------------------
    // 渠道
    // ------------------------------------------------------------------

    /** 当前可用的找回渠道（开关 + 通道自身可用性同时满足） */
    public static function channels(): array
    {
        $mode = self::mode();

        return array_values(array_filter(
            self::CHANNELS,
            static fn (string $channel): bool => self::channelAllowed(
                $mode,
                $channel,
                SysConfig::getBool('mail.enabled', false),
                SysConfig::getBool('sms.enabled', false)
            )
        ));
    }

    public static function channelAvailable(string $channel): bool
    {
        return in_array($channel, self::channels(), true);
    }

    /** 渠道可用性纯函数（便于单测，不受运行时配置影响） */
    public static function channelAllowed(string $mode, string $channel, bool $mailEnabled, bool $smsEnabled): bool
    {
        if ($channel === 'email') {
            return $mailEnabled && in_array($mode, ['email', 'both'], true);
        }
        if ($channel === 'sms') {
            return $smsEnabled && in_array($mode, ['sms', 'both'], true);
        }

        return false;
    }

    /** 渠道非法 / 未开启时抛错（与账号无关，不泄露账号存在性） */
    public static function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new ApiException(I18n::t('auth.reset_channel_invalid'), 422);
        }
        if (!self::channelAvailable($channel)) {
            throw new ApiException(I18n::t('auth.reset_channel_disabled'), 422);
        }
    }

    // ------------------------------------------------------------------
    // 账号
    // ------------------------------------------------------------------

    /**
     * 按用户名 / 邮箱 / 手机号匹配启用中的账号。
     *
     * 显式跳出**全部**作用域：找回接口是匿名的，没有当前用户上下文，而
     * 用户名 / 邮箱 / 手机号都是全局唯一键 —— 只跳出数据档位会查不到别的租户的账号。
     * 已删除 / 已禁用账号一律视为不存在（找回不应用于被停用的账号）。
     *
     * @return array<string,mixed>|null
     */
    public static function findUser(string $account): ?array
    {
        $account = trim($account);
        if ($account === '') {
            return null;
        }

        $row = User::withoutAllScopes('找回密码链路匿名访问，账号是全局唯一键，必须跨租户查')
            ->where('status', 1)
            ->where(static function ($query) use ($account): void {
                $query->where('username', $account)
                    ->whereOr('email', $account)
                    ->whereOr('phone', $account);
            })
            ->field('id,username,nickname,email,phone')
            ->find();

        return $row ? $row->toArray() : null;
    }

    // ------------------------------------------------------------------
    // 验证码存取
    // ------------------------------------------------------------------

    /** 生成 6 位数字验证码 */
    public static function generateCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** 只存 bcrypt 摘要，不存明文 */
    public static function storeCode(int $userId, string $channel, string $code): void
    {
        $ttl = self::codeTtl();
        Redis::setex(self::codeKey($channel, $userId), $ttl, password_hash($code, PASSWORD_BCRYPT));
        Redis::del(self::attemptKey($channel, $userId));
    }

    /**
     * 校验验证码：比对 hash + 尝试次数上限 + 成功后一次性消费。
     *
     * Redis 不可用视为无效（fail-closed）。
     */
    public static function verifyCode(int $userId, string $channel, string $code): bool
    {
        if ($userId <= 0 || $code === '') {
            return false;
        }

        try {
            $key  = self::codeKey($channel, $userId);
            $hash = Redis::get($key);
            if (!is_string($hash) || $hash === '') {
                return false;
            }

            $attemptKey = self::attemptKey($channel, $userId);
            // 原子初始化（不存在时置 1 并带 TTL），避免 INCR 后、EXPIRE 前崩溃导致 key 永久
            $attempts = (bool)Redis::set($attemptKey, '1', 'EX', self::codeTtl(), 'NX') ? 1 : (int)Redis::incr($attemptKey);

            if (self::exceededAttempts($attempts, self::maxAttempts())) {
                self::clearCode($userId, $channel);
                return false;
            }

            if (!password_verify($code, $hash)) {
                return false;
            }

            // 一次性消费
            self::clearCode($userId, $channel);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** 验证码是否已超过允许的校验次数（纯函数） */
    public static function exceededAttempts(int $attempts, int $max): bool
    {
        return $max > 0 && $attempts > $max;
    }

    public static function clearCode(int $userId, string $channel): void
    {
        try {
            Redis::del(self::codeKey($channel, $userId));
            Redis::del(self::attemptKey($channel, $userId));
        } catch (Throwable) {
            // 忽略
        }
    }

    /** 当前存储的摘要（仅供测试 / 排查，不返回明文） */
    public static function storedHash(int $userId, string $channel): ?string
    {
        try {
            $value = Redis::get(self::codeKey($channel, $userId));
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function codeKey(string $channel, int $userId): string
    {
        return self::KEY_PREFIX . 'code:' . $channel . ':' . $userId;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 发送间隔 + 每日上限（按账号字符串计数，账号不存在也占位） */
    private static function reserveSend(string $channel, string $account): void
    {
        try {
            $interval = max(0, SysConfig::getInt('security.reset_send_interval', 60));
            if ($interval > 0) {
                $key = self::sendKey($channel, $account);
                if (!self::acquire($key, $interval)) {
                    $ttl = (int)Redis::ttl($key);
                    throw new ApiException(
                        I18n::t('auth.reset_send_too_often', ['seconds' => $ttl > 0 ? $ttl : $interval]),
                        429
                    );
                }
            }

            $daily = max(0, SysConfig::getInt('security.reset_daily_limit', 10));
            if ($daily > 0) {
                $dailyKey = self::dailyKey($channel, $account);
                // 原子初始化（不存在时置 1 并带 TTL），避免 INCR 成功后、EXPIRE 前崩溃导致 key 永久
                $count = (bool)Redis::set($dailyKey, '1', 'EX', 86400, 'NX') ? 1 : (int)Redis::incr($dailyKey);
                if ($count > $daily) {
                    Redis::del(self::sendKey($channel, $account));
                    throw new ApiException(I18n::t('auth.reset_daily_limit'), 429);
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            // Redis 故障：统一报服务不可用，对所有账号一致
            throw new ApiException(I18n::t('auth.reset_service_down'), 503);
        }
    }

    private static function acquire(string $key, int $seconds): bool
    {
        // 原子 SET NX EX：避免 SETNX 成功后、EXPIRE 前进程崩溃导致 key 永久不失效
        return (bool)Redis::set($key, '1', 'EX', $seconds, 'NX');
    }

    /** 重置成功：作废该用户此前签发的全部令牌，并从在线列表移除 */
    private static function revokeSessions(int $userId): void
    {
        $maxExpiry = OnlineSession::maxExpiryOfUser($userId);
        TokenBlacklist::revokeUser($userId, 0, $maxExpiry > 0 ? $maxExpiry - time() : null);
        OnlineSession::removeUser($userId);
    }

    private static function mode(): string
    {
        return SysConfig::getString('security.reset_channel', 'off');
    }

    private static function codeTtl(): int
    {
        return max(60, SysConfig::getInt('security.reset_code_ttl', 300));
    }

    private static function maxAttempts(): int
    {
        return max(1, SysConfig::getInt('security.reset_max_attempts', 5));
    }

    private static function sendKey(string $channel, string $account): string
    {
        return self::KEY_PREFIX . 'send:' . $channel . ':' . md5(strtolower(trim($account)));
    }

    private static function dailyKey(string $channel, string $account): string
    {
        return self::KEY_PREFIX . 'daily:' . $channel . ':' . md5(strtolower(trim($account)));
    }

    private static function attemptKey(string $channel, int $userId): string
    {
        return self::KEY_PREFIX . 'attempt:' . $channel . ':' . $userId;
    }

    private static function mailSubject(): string
    {
        return I18n::t('auth.reset_mail_subject', [
            'name' => SysConfig::getString('system.name', 'CCCMS'),
        ]);
    }

    private static function mailBody(string $code): string
    {
        return I18n::t('auth.reset_mail_body', [
            'code'    => $code,
            'minutes' => max(1, (int)ceil(self::codeTtl() / 60)),
        ]);
    }
}
