<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\ConfigLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class ConfigController extends BaseController
{
    #[Permission(slug: 'cccms:config:index', title: '配置列表')]
    public function index(Request $request): Response
    {
        return $this->ok(ConfigLogic::list((string)$request->input('group', '')));
    }

    /**
     * 前端初始化用的公开配置（品牌信息 + UI 默认值 + 语言）。
     *
     * #[NoLogin]：登录页在拿到 token 前就需要系统名称/Logo/主题色/维护公告。
     * 返回的是白名单字段，不包含 security / upload 等敏感配置。
     *
     * locale / locales 属于框架级元信息（不是 sys_config 项），故在控制器合并，
     * 由 I18n 统一提供，避免 ConfigLogic 依赖 i18n 基础设施。
     */
    #[NoLogin]
    public function ui(Request $request): Response
    {
        return $this->ok([
            ...ConfigLogic::ui(),
            'locale'  => I18n::defaultLocale(),
            'locales' => I18n::locales(),
        ]);
    }

    /**
     * 保存配置。
     *
     * `cccms:config:save` 只覆盖「普通分组」；`security.*` / `upload.*` / `mail.*` / `sms.*`
     * 影响系统整体安全姿态（登录防护、上传白名单、找回密码凭据），仅平台超管可改。
     */
    #[Permission(slug: 'cccms:config:save', title: '保存配置')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        $values = (array)$request->post();
        ConfigLogic::assertGroupsWritable($values, $request->user);

        $updated = ConfigLogic::save($values);
        return $this->ok(['updated' => $updated], I18n::t('common.saved'));
    }
}
