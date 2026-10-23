<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Throwable;
use Webman\Http\Request;

/** JWT 签发 / 校验。 */
final class TokenService
{
    private const ALG = 'HS256';

    /** HS256 的密钥长度下限（字节） */
    public const MIN_SECRET_LENGTH = 32;

    /**
     * 未配置 `security.token_ttl` 时的兜底有效期（秒）= 2 小时。
     *
     * 刻意不用「7 天」这类长值：令牌存在 localStorage，TTL 就是 XSS 一旦发生
     * 攻击者能用的**窗口长度**。缩短 TTL 的体验代价由**滑动续期**抵消
     * （见 `shouldRenew()`：剩余不足 1/3 时自动换新令牌，活跃用户无感）。
     */
    public const DEFAULT_TTL = 7200;

    /** 剩余有效期低于 TTL 的几分之一时触发续期 */
    private const RENEW_RATIO = 3;

    public static function secret(): string
    {
        $secret = config('plugin.cccms.auth.secret', '');
        if (!is_string($secret) || $secret === '') {
            throw new ApiException('JWT secret 未配置', 500);
        }
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new ApiException('JWT secret 长度不足 ' . self::MIN_SECRET_LENGTH . ' 字节', 500);
        }
        return $secret;
    }

    /** 令牌有效期：优先取后台配置（security.token_ttl），回退到配置文件 */
    public static function ttl(): int
    {
        $ttl = SysConfig::getInt('security.token_ttl', (int)config('plugin.cccms.auth.ttl', self::DEFAULT_TTL));
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }

    /**
     * 是否需要滑动续期：剩余有效期不足 TTL 的 1/3。
     *
     * 由 `CheckLogin` 在每个已鉴权请求上判断，命中则签发新令牌并经响应头
     * `X-Refresh-Token` 下发，前端静默替换（用户无感）。
     *
     * 已过期 / 缺少 `exp` 一律返回 false：那是 401 的职责，不在这里救。
     *
     * @param array<string,mixed> $claims
     */
    public static function shouldRenew(array $claims): bool
    {
        $exp = (int)($claims['exp'] ?? 0);
        if ($exp <= 0) {
            return false;
        }

        $left = $exp - time();

        return $left > 0 && $left < (int)ceil(self::ttl() / self::RENEW_RATIO);
    }

    /**
     * 签发 token。
     *
     * @param int                   $userId
     * @param array<string,mixed>   $claims
     * @return array{token:string,jti:string,expires_in:int,expires_at:int}
     */
    public static function issue(int $userId, array $claims = [], ?int $ttl = null): array
    {
        // 有效期可由调用方覆盖：不同入口可以有各自的会话时长，
        // 但共用同一套密钥与校验逻辑，只是 exp 不同
        $ttl = $ttl !== null && $ttl > 0 ? $ttl : self::ttl();
        $now = time();
        $payload = array_merge($claims, [
            'iss' => 'cccms',
            'sub' => (string)$userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        return [
            'token'      => JWT::encode($payload, self::secret(), self::ALG),
            // 回传 jti：在线会话登记与登出黑名单都要用它（JWT 解出来也一样，这里省一次解码）
            'jti'        => (string)$payload['jti'],
            'expires_in' => $ttl,
            'expires_at' => $now + $ttl,
        ];
    }

    /**
     * 从请求头解析 Bearer 令牌。
     *
     * 同时供 `CheckLogin`（鉴权）与 `AuthController::logout`（登出）使用，
     * 避免同一段正则散落两处。
     */
    public static function fromRequest(Request $request): ?string
    {
        $auth = $request->header('Authorization', '');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * 校验并返回 claims；失败抛 ApiException(401)。
     *
     * @return array<string,mixed>
     */
    public static function verify(string $token): array
    {
        try {
            return (array)JWT::decode($token, new Key(self::secret(), self::ALG));
        } catch (ExpiredException) {
            throw new ApiException('登录凭证已过期', 401);
        } catch (SignatureInvalidException) {
            throw new ApiException('登录凭证无效', 401);
        } catch (BeforeValidException) {
            throw new ApiException('登录凭证尚未生效', 401);
        } catch (Throwable) {
            throw new ApiException('登录凭证无效', 401);
        }
    }
}
