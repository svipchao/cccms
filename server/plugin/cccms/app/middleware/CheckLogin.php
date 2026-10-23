<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\I18n;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\SessionGuard;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\TokenService;
use plugin\cccms\support\UserContext;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 认证：解析 token → 从 DB 构建 $request->user（权限实时加载）；#[NoLogin] 跳过。 */
class CheckLogin implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $controller = $request->controller;
        if (!is_string($controller) || $controller === '') {
            return $handler($request);
        }

        $meta = PermissionMeta::of($controller, (string)$request->action);
        $renewed = null;

        if (!$meta['noLogin']) {
            $token = TokenService::fromRequest($request);
            if ($token === null) {
                throw new ApiException(I18n::t('common.not_logged_in'), 401);
            }
            $claims = TokenService::verify($token);

            // 只接受后台令牌：带 `aud` 的令牌来自其他账号体系（历史签发的 `member` 令牌
            // 在有效期内仍可能被拿来重放）。`member` 与 `sys_user` 是两套自增主键，
            // 不隔离时 member id 恰好撞上某个 sys_user id，就会被当成那个人登录。
            if (($claims['aud'] ?? '') === 'member') {
                throw new ApiException(I18n::t('common.invalid_credentials'), 401);
            }

            // 失效名单：登出写入的单令牌黑名单 + 「强制下线」写入的用户级时间分界线
            if (TokenBlacklist::claimsRevoked($claims)) {
                throw new ApiException(I18n::t('common.session_expired'), 401);
            }

            $userId = (int)($claims['sub'] ?? 0);

            // 令牌里的租户声明（超管「切换租户」后重新签发）：缺失 = 未切换 / 升级前的旧令牌。
            // 非超管携带的 tid 与账号归属不一致时 buildContext 直接返回 null → 401，
            // 因此 tampered / 失配的令牌不会带来跨租户访问。
            $tenantId = isset($claims['tid']) ? (int)$claims['tid'] : null;

            // 权限集合实时加载（Redis 缓存 + 版本号失效见 AuthService::buildContext）
            $user = $userId > 0 ? AuthService::buildContext($userId, $tenantId) : null;
            if ($user === null) {
                throw new ApiException(I18n::t('common.invalid_credentials'), 401);
            }
            $request->user = $user;

            // 挂上当前会话 ID：登出（精确作废）与「在线用户」（禁止踢自己）都要用
            $request->jti = (string)($claims['jti'] ?? '');

            // 只刷新登录时已登记会话的活跃时间（60 秒节流）；未登记就跳过，不补建
            OnlineSession::touch((string)($claims['jti'] ?? ''));

            // 维护模式：把「此前签发的令牌全部失效」这条分界线推上去（幂等，只需一次）。
            // 服务端发 401 而不是 503，是为了让前端走既有的 401 逻辑：
            // 清 token → 提示 → 回登录页；登录页会展示维护公告，非超管也登不进来。
            if (SysConfig::getBool('system.maintenance', false) && SessionGuard::cutoff() === 0) {
                SessionGuard::cut();
            }

            // 超管豁免，否则维护期间没人能进系统
            if (!$user->isSuperAdmin() && SessionGuard::isStale((int)($claims['iat'] ?? 0))) {
                throw new ApiException(self::reauthMessage(), 401);
            }

            // 滑动续期放在最后：只有全部校验通过（未被拉黑、不在维护闸门内）才换新令牌
            $renewed = self::renew($claims, $user);
        }

        $response = $handler($request);

        // 续期令牌经响应头下发（跨域下需 `Access-Control-Expose-Headers` 声明，见 Cors）
        return $renewed === null ? $response : $response->withHeaders(['X-Refresh-Token' => $renewed]);
    }

    /**
     * 令牌滑动续期：剩余有效期不足 TTL 的 1/3 时签发新令牌。
     *
     * 为什么缩短 TTL 还必须配续期：令牌存在 localStorage，7 天的 TTL 意味着
     * XSS 一旦发生，攻击者拿到的是长期有效凭证；缩短 TTL 后若没有续期，
     * 用户会周期性被强制登出。
     *
     * **刻意不把旧令牌写入黑名单**：同一页面常并发多个请求，先到的那个续期后，
     * 其余仍在途的请求带的是旧令牌，若立即拉黑会让它们被判 401 而跳登录。
     * 旧令牌继续有效到自然过期，前端收到新令牌后即不再使用它。
     *
     * @param array<string,mixed> $claims
     */
    private static function renew(array $claims, UserContext $user): ?string
    {
        if (!TokenService::shouldRenew($claims)) {
            return null;
        }

        $oldJti = (string)($claims['jti'] ?? '');

        // 同一旧 jti 只允许一个请求续期：并发续期会签发多个新 jti，浏览器最终只保存一个，
        // 其余会在 Redis 里变成该用户的重复在线记录。
        if (!OnlineSession::lockRenew($oldJti)) {
            return null;
        }

        try {
            // 带上当前生效租户：超管切换租户后若漏传 tid，续期会把他悄悄弹回平台租户
            $token = TokenService::issue($user->id, ['tid' => $user->tenantId]);

            // 迁移失败说明旧会话已被别的请求迁走，不再下发这个没有会话记录的新令牌
            if (!OnlineSession::rotate($oldJti, (string)$token['jti'], (int)$token['expires_at'])) {
                return null;
            }

            return (string)$token['token'];
        } finally {
            OnlineSession::unlockRenew($oldJti);
        }
    }

    /** 被闸门作废时给用户的提示：维护期间说维护，平时说会话失效 */
    private static function reauthMessage(): string
    {
        if (SysConfig::getBool('system.maintenance', false)) {
            return SysConfig::getString('system.maintenance_notice', I18n::t('auth.maintenance'))
                ?: I18n::t('auth.maintenance');
        }

        return I18n::t('common.session_expired');
    }
}
