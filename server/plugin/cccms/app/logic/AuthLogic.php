<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\Captcha;
use plugin\cccms\support\Cipher;
use plugin\cccms\support\ClientIp;
use plugin\cccms\support\I18n;
use plugin\cccms\support\LoginThrottle;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TenantContext;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\TokenService;
use plugin\cccms\support\UserContext;

/** 登录认证逻辑。 */
final class AuthLogic
{
    /**
     * 账号密码登录，返回 token 与用户信息。
     *
     * 成功与失败都会写入登录日志（`sys_log`，`path='/auth/login'`）——失败记录是审计的重点。
     */
    public static function login(
        string $username,
        string $password,
        string $captcha = '',
        string $captchaId = '',
        ?int $domainTenantId = null,
    ): array {
        try {
            $result = self::attempt($username, $password, $captcha, $captchaId, $domainTenantId);
        } catch (ApiException $e) {
            LogLogic::recordLogin(0, $username, false, $e->getMessage());
            throw $e;
        }

        LogLogic::recordLogin((int)($result['user']['id'] ?? 0), $username, true, I18n::t('auth.login_success'));

        return $result;
    }

    /**
     * 登录的实际执行体：失败一律抛 `ApiException`，由 `login()` 统一落登录日志。
     *
     * @return array{token:array<string,mixed>,user:array<string,mixed>}
     */
    private static function attempt(
        string $username,
        string $password,
        string $captcha,
        string $captchaId,
        ?int $domainTenantId = null,
    ): array {
        if ($username === '' || $password === '') {
            throw new ApiException(I18n::t('auth.missing_credentials'), 422);
        }

        // 登录来源 IP：失败锁定与在线会话都要用，统一取一次。
        // 走 ClientIp（可信代理 + 取最右非可信 IP），不能用 request()->getRealIp() ——
        // 反代部署下后者取 XFF 第一个值，攻击者可任意伪造以绕过 IP 维度限流（见 R-01）。
        $ip = ClientIp::resolve(request());

        // ① 失败锁定：先判断，避免锁定期间仍可用于撞库。
        // 账号维度防定向爆破，IP 维度防分布式撞库（见 LoginThrottle 类注释）
        LoginThrottle::assertNotLocked($username, $ip);

        // ② 图形验证码（由 security.login_captcha 控制，默认关闭）
        if (SysConfig::getBool('security.login_captcha', false) && !Captcha::verify($captchaId, $captcha)) {
            throw new ApiException(I18n::t('auth.captcha_invalid'), 422);
        }

        // 已进回收站的账号不能登录（用户名仍被占用，但登录入口直接当作不存在）。
        // 显式跳出**全部**作用域：此时还没有当前用户，登录也不解析租户
        // （`username` 是全局唯一键），因此必须连租户一起绕过。
        $user = User::withoutAllScopes('登录链路尚未建立用户上下文，用户名是全局唯一键，必须跨租户查')->where('username', $username)->find();
        if (!$user || !password_verify($password, (string)$user['password'])) {
            LoginThrottle::recordFailure($username, $ip);
            throw new ApiException(I18n::t('auth.bad_credentials') . self::attemptTip($username), 422);
        }
        if ((int)$user['status'] !== 1) {
            throw new ApiException(I18n::t('auth.account_disabled'), 403);
        }

        // 自定义域名访问：只放行归属该租户的账号。平台归属（0）的账号必须同时是超管，
        // 才允许以该租户身份进入 —— 这一步由 `buildContext()` 对非超管返回 null 兜住，
        // 因此「普通平台账号从客户域名混进来」不会成立。
        if ($domainTenantId !== null) {
            $home = (int)($user['tenant_id'] ?? TenantContext::PLATFORM_ID);
            if ($home !== $domainTenantId && $home !== TenantContext::PLATFORM_ID) {
                throw new ApiException(I18n::t('auth.domain_mismatch'), 403);
            }
        }

        $context = AuthService::buildContext((int)$user['id'], $domainTenantId);
        if ($context === null) {
            throw new ApiException(
                $domainTenantId === null
                    ? I18n::t('auth.role_abnormal')
                    : I18n::t('auth.domain_mismatch'),
                403
            );
        }

        // ③ 维护模式：只允许超管登录（前端也会展示维护公告）
        if (SysConfig::getBool('system.maintenance', false) && !$context->isSuperAdmin()) {
            throw new ApiException(self::maintenanceNotice(), 503);
        }

        LoginThrottle::clear($username, $ip);

        // 更新登录信息（登录链路同样没有当前用户上下文，显式跳出**全部**作用域）
        User::withoutAllScopes('登录链路尚未建立用户上下文，按 id 更新登录信息')->where('id', $user['id'])->update([
            'login_time' => date('Y-m-d H:i:s'),
            'login_ip'   => $ip,
        ]);

        // 令牌写入 tid 声明：这是「生效租户」的唯一来源，超管切换租户即重签一份
        $token = TokenService::issue((int)$user['id'], ['tid' => $context->tenantId]);

        // 「强制下线」是按用户记录的签发时间分界线（`iat <= cutoff` 即失效）。
        // 登录成功必须清除它，否则与该分界线**同一秒**签发的新令牌会被立刻判成失效。
        TokenBlacklist::clearUser((int)$user['id']);

        // 登记在线会话，供「在线用户 / 强制下线」使用（Redis 不可用时静默降级）
        OnlineSession::register(
            (int)$user['id'],
            (string)$user['username'],
            (string)$user['nickname'],
            (string)$token['jti'],
            $ip,
            (string)request()->header('user-agent', ''),
        );

        return [
            'token' => $token,
            'user'  => self::profile($context),
        ];
    }

    /**
     * 用户信息。
     *
     * `crypto_key` 是字段级 `encrypt` 动作的解密密钥（**对称密钥**），只下发给
     * 「能配置数据权限规则」的管理员（持有 `cccms:data_rule*` 权限节点或超管）。
     *
     * 为什么收敛下发范围：它是**存储加密**而不是访问控制 —— 任何拿到它的人都能解密
     * 全部密文，等于把「数据库裸读防护」摊平给所有登录用户。敏感字段的可见性
     * 请用 `mask` + 数据权限（见 docs/06-数据权限）。
     */
    public static function profile(UserContext $user): array
    {
        $data = $user->toArray();
        if (Cipher::configured() && self::mayDecryptFields($user)) {
            $data['crypto_key'] = Cipher::publicKey();
        }
        return $data;
    }

    /** 是否下发字段级 encrypt 密钥：超管，或持有任一 `cccms:data_rule` 权限节点 */
    private static function mayDecryptFields(UserContext $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($user->permissions as $node) {
            if (str_starts_with((string)$node, 'cccms:data_rule')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 登出：作废当前令牌（写入 jti 黑名单）+ 移除在线会话。
     *
     * 令牌是无状态的，只靠前端丢弃 token 并不能阻止旧 token 在有效期内继续使用，
     * 因此这里把 jti 记入 Redis 黑名单（TTL = 剩余有效期），`CheckLogin` 每次校验。
     *
     * @param array<string,mixed> $claims 当前令牌的 claims（由控制器解析后传入）
     */
    public static function logout(UserContext $user, array $claims = []): void
    {
        $jti = (string)($claims['jti'] ?? '');
        TokenBlacklist::revoke($jti, (int)($claims['exp'] ?? 0));
        OnlineSession::remove($jti);
    }

    /** 密码错误时附带剩余次数提示（未启用锁定时不提示） */
    private static function attemptTip(string $username): string
    {
        if (SysConfig::getInt('security.login_fail_limit', 5) <= 0) {
            return '';
        }
        $left = LoginThrottle::remainingAttempts($username);
        return $left > 0 ? I18n::t('auth.attempt_tip', ['count' => $left]) : '';
    }

    private static function maintenanceNotice(): string
    {
        return SysConfig::getString('system.maintenance_notice', I18n::t('auth.maintenance'))
            ?: I18n::t('auth.maintenance');
    }
}
