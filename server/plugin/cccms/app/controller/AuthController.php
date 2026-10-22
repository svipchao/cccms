<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\AuthLogic;
use plugin\cccms\app\logic\TenantLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\Captcha;
use plugin\cccms\support\ClientIp;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PasswordReset;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TenantContext;
use plugin\cccms\support\TenantDomain;
use plugin\cccms\support\TokenService;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

class AuthController extends BaseController
{
    #[NoLogin]
    public function ping(Request $request): Response
    {
        return $this->ok(['pong' => true, 'time' => date('Y-m-d H:i:s')]);
    }

    // title 是操作名：登录不是权限点（不产生按钮节点），但操作日志要显示「登录」
    #[NoLogin(title: '登录')]
    #[Restrict(methods: ['POST'])]
    public function login(Request $request): Response
    {
        $result = AuthLogic::login(
            (string)$request->post('username', ''),
            (string)$request->post('password', ''),
            (string)$request->post('captcha', ''),
            (string)$request->post('captcha_id', ''),
            // 通过租户自定义域名访问时，登录后只能停在该租户（见 TenantDomain 类注释）
            TenantDomain::fromRequest(),
        );
        return $this->ok($result, I18n::t('auth.login_success'));
    }

    /**
     * 图形验证码。
     *
     * 未开启（security.login_captcha=0）时返回 null，
     * 前端据此隐藏验证码输入框，不需要额外接口告知。
     */
    #[NoLogin]
    public function captcha(Request $request): Response
    {
        if (!SysConfig::getBool('security.login_captcha', false)) {
            return $this->ok(null);
        }

        $captcha = Captcha::make();
        return $this->ok(['captcha_id' => $captcha['id'], 'image' => $captcha['image']]);
    }

    /**
     * 找回密码：发送验证码。
     *
     * 无论账号是否存在都返回同一句提示（不泄露账号存在性）；
     * 渠道未开启时才明确报错（与账号无关）。
     */
    #[NoLogin(title: '发送找回验证码')]
    #[Restrict(methods: ['POST'])]
    public function sendResetCode(Request $request): Response
    {
        PasswordReset::sendCode(
            (string)$request->post('account', ''),
            (string)$request->post('channel', ''),
            ClientIp::resolve($request)
        );

        return $this->ok(null, I18n::t('auth.reset_code_sent'));
    }

    /** 找回密码：用验证码重置口令（成功后作废该用户全部旧令牌） */
    #[NoLogin(title: '重置密码')]
    #[Restrict(methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        PasswordReset::reset(
            (string)$request->post('account', ''),
            (string)$request->post('channel', ''),
            (string)$request->post('code', ''),
            (string)$request->post('password', '')
        );

        return $this->ok(null, I18n::t('auth.reset_success'));
    }

    #[NoAuth]
    public function me(Request $request): Response
    {
        return $this->ok(AuthLogic::profile($request->user));
    }

    /**
     * 可切换的租户候选（超管专属，非超管返回空数组）。
     *
     * 放在 auth 段而不是租户管理段：它是**登录会话**的一部分（顶栏切换器每页都要读），
     * 不该被 `cccms:tenant:*` 这类管理权限点拦住 —— 否则被授予租户菜单之外的超管
     * 反而看不到切换入口。越权由 `TenantLogic::options()` 内的超管判定兜住。
     */
    #[NoAuth]
    public function tenants(Request $request): Response
    {
        return $this->ok(TenantLogic::options($request->user, TenantDomain::fromRequest()));
    }

    /** 切换生效租户：用新 `tid` 重签令牌，前端替换后重新拉取菜单与数据 */
    #[NoAuth(title: '切换租户')]
    #[Restrict(methods: ['POST'])]
    public function switchTenant(Request $request): Response
    {
        $result = TenantLogic::switchTo(
            $request->user,
            (int)$request->post('tenant_id', TenantContext::PLATFORM_ID),
            (string)($request->jti ?? ''),
            TenantDomain::fromRequest()
        );

        return $this->ok($result, I18n::t('tenant.switched'));
    }

    #[NoAuth(title: '注销')]
    #[Restrict(methods: ['POST'])]
    public function logout(Request $request): Response
    {
        // 登出要拿到 jti / exp 才能精确作废令牌，因此这里再解析一次请求头里的 token。
        // 令牌已损坏 / 已过期时按「登出成功」处理（本来就是无效令牌），保证登出接口幂等。
        $claims = [];
        $token  = TokenService::fromRequest($request);
        if ($token !== null) {
            try {
                $claims = TokenService::verify($token);
            } catch (Throwable) {
                $claims = [];
            }
        }

        AuthLogic::logout($request->user, $claims);
        return $this->ok(null, I18n::t('common.logged_out'));
    }
}
