<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\Cipher;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PasswordReset;
use plugin\cccms\support\SessionGuard;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/** 系统配置逻辑。 */
final class ConfigLogic
{
    /**
     * 只允许**平台超管**改动的配置前缀。
     *
     * 这些项决定的是「系统整体的安全姿态」，不是某个业务参数：
     *   - `security.*` 登录锁定阈值 / 验证码开关 / 令牌有效期 / 限流白名单 / 找回渠道
     *   - `upload.*`   上传白名单、匿名读取开关、云存储凭证
     *   - `mail.*` / `sms.*` 找回密码通道的 SMTP / 网关凭据（可被改成攻击者地址，
     *     把「验证码」这条链路变成凭据收集器）
     *
     * 只靠 `cccms:config:save` 一个权限节点覆盖全部配置，等于把「改站点名」和
     * 「关掉登录防护」放在同一把钥匙下，粒度与风险不匹配。
     */
    private const ADMIN_ONLY_PREFIXES = ['security.', 'upload.', 'mail.', 'sms.'];
    /** 配置列表（按分组、排序输出；group 为空则返回全部） */
    public static function list(string $group = ''): array
    {
        $query = Db::name('config')->where('status', 1);
        if ($group !== '') {
            $query->where('group', $group);
        }

        $rows = $query->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        // 这里走的是查询构造器而非模型，json 字段不会自动解码，手工转一次
        foreach ($rows as &$row) {
            $options = $row['options'] ?? null;
            if (is_string($options)) {
                $decoded         = json_decode($options, true);
                $row['options'] = is_array($decoded) ? $decoded : null;
            }

            // 敏感项不下发密文：只回传「是否已配置」，编辑时留空即不修改
            if ((string)($row['type'] ?? '') === 'password') {
                $row['has_value'] = (string)($row['value'] ?? '') !== '';
                $row['value']     = '';
            }

            self::localize($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * 配置项的展示文案国际化。
     *
     * - `title`：内置项在 `lang/{locale}/config_item.php` 里有 `config_item.{name}` → 覆盖；
     *   管理员自建项（用户录入数据）没有 key → 保留 DB 里的原文。
     * - `group_label`：新增字段承载翻译后的分组名；`group` 原样保留，
     *   因为前端用它做 `?group=` 过滤值，改掉会破坏过滤。
     * - `options[].label`：选项标签同理按 `config_item.option.{value}` 覆盖。
     */
    private static function localize(array &$row): void
    {
        $name = (string)($row['name'] ?? '');
        if ($name !== '' && I18n::has('config_item.' . $name)) {
            $row['title'] = I18n::t('config_item.' . $name);
        }

        $group = (string)($row['group'] ?? '');
        $row['group_label'] = ($group !== '' && I18n::has('config_item.group.' . $group))
            ? I18n::t('config_item.group.' . $group)
            : $group;

        if (!is_array($row['options'] ?? null)) {
            return;
        }

        foreach ($row['options'] as &$option) {
            if (!is_array($option)) {
                continue;
            }
            $value = (string)($option['value'] ?? '');
            if ($value !== '' && I18n::has('config_item.option.' . $value)) {
                $option['label'] = I18n::t('config_item.option.' . $value);
            }
        }
        unset($option);
    }

    /**
     * 登录页 / 前端初始化需要的公开配置。
     *
     * 这是**白名单**：只有品牌信息、UI 默认值、找回密码渠道开关与全局水印参数，
     * 不含上传等敏感项。登录页在拿到 token 之前就要用系统名称 / Logo / 主题色，所以走 #[NoLogin]。
     *
     * @return array{
     *     system:array<string,mixed>,
     *     ui:array<string,mixed>,
     *     security:array<string,mixed>,
     *     watermark:array<string,mixed>
     * }
     */
    public static function ui(): array
    {
        return [
            'system' => [
                'name'        => SysConfig::getString('system.name', 'CCCMS'),
                'logo'        => SysConfig::getString('system.logo'),
                'icp'         => SysConfig::getString('system.icp'),
                'copyright'   => SysConfig::getString('system.copyright'),
                'maintenance' => SysConfig::getBool('system.maintenance'),
                'notice'      => SysConfig::getString('system.maintenance_notice'),
            ],
            'ui' => [
                'theme_mode'      => SysConfig::getString('ui.theme_mode', 'light'),
                'theme_primary'   => SysConfig::getString('ui.theme_primary', '#2b6cff'),
                'page_size'       => SysConfig::getInt('ui.page_size', 15),
                'tags_view'       => SysConfig::getBool('ui.tags_view', true),
                'container_width' => SysConfig::getInt('ui.container_width', 0),
            ],
            // 找回密码可用渠道（空数组 = 关闭）：登录页据此决定是否展示「忘记密码」入口。
            // 只下发渠道名，不下发任何密钥类配置。
            'security' => [
                'reset_channels' => PasswordReset::channels(),
            ],
            // 全局水印：登录后的前台页面由前端叠加渲染（登录页不在 BasicLayout 内，不渲染）。
            // 只下发渲染参数；用户名 / 用户 ID 等由前端按当前登录用户自行代入，不下发隐私数据。
            'watermark' => [
                'enabled'   => SysConfig::getBool('watermark.enabled'),
                'content'   => SysConfig::getString('watermark.content', '{username}{newline}{time}'),
                'opacity'   => SysConfig::getInt('watermark.opacity', 12),
                'angle'     => SysConfig::getInt('watermark.angle', -22),
                'font_size' => SysConfig::getInt('watermark.font_size', 14),
                'color'     => SysConfig::getString('watermark.color', '#8c8c8c'),
                'gap_x'     => SysConfig::getInt('watermark.gap_x', 180),
                'gap_y'     => SysConfig::getInt('watermark.gap_y', 140),
            ],
        ];
    }

    /**
     * 校验「这次保存有没有碰到敏感分组」。
     *
     * 放在控制器而不是 `save()` 里：`save()` 是纯写库函数，CLI / 初始化流程也要用，
     * 而「谁能改 security.*」是**入口处的授权判断**，属于控制器职责。
     * 未来如果有第二个写入口，必须同样调一次这里。
     *
     * @param array<string,mixed> $values
     * @throws ApiException 非超管提交了敏感分组
     */
    public static function assertGroupsWritable(array $values, ?UserContext $user): void
    {
        // 无用户上下文 = CLI / 初始化（平台级操作），放行；HTTP 路径必须传当前用户
        if ($user === null || $user->isSuperAdmin()) {
            return;
        }

        foreach (array_keys($values) as $name) {
            foreach (self::ADMIN_ONLY_PREFIXES as $prefix) {
                if (str_starts_with((string)$name, $prefix)) {
                    throw new ApiException(
                        I18n::t('config.admin_only', ['group' => rtrim($prefix, '.')]),
                        403
                    );
                }
            }
        }
    }

    /**
     * 按 name 批量保存配置值。
     *
     * 只更新**已存在**的配置项：请求体里的未知键一律忽略，
     * 避免前端传参出错或恶意请求往 sys_config 里塞脏数据。
     *
     * @param array<string,mixed> $values
     * @return int 实际更新的配置项数量
     */
    public static function save(array $values): int
    {
        if ($values === []) {
            return 0;
        }



        // name => type（type=password 的敏感项需要加密存储）
        $types = Db::name('config')->whereIn('name', array_keys($values))->column('type', 'name');
        if ($types === []) {
            return 0;
        }

        $now     = date('Y-m-d H:i:s');
        $updated = 0;

        Db::transaction(static function () use ($values, $types, $now, &$updated): void {
            $rows = [];
            foreach ($values as $name => $value) {
                $name = (string)$name;
                if (!isset($types[$name])) {
                    continue; // 未知键一律忽略，避免往 sys_config 塞脏数据
                }

                if ((string)$types[$name] === 'password') {
                    if ((string)$value === '') {
                        continue; // 敏感项留空 = 保持原值不变
                    }
                    $stored = SysConfig::SECRET_PREFIX . Cipher::encrypt((string)$value);
                } else {
                    $stored = self::encode($value);
                }

                $rows[] = [$name, $stored, $now];
            }

            if ($rows === []) {
                return;
            }

            // 一条 upsert 写完：逐条 update 在保存整页配置时会产生几十次往返，
            // 并把事务持有时间拉长（`name` 有 uk_name，可安全按唯一键覆盖）
            $holder = implode(', ', array_fill(0, count($rows), '(?, ?, ?)'));
            $bind   = [];
            foreach ($rows as $row) {
                array_push($bind, ...$row);
            }

            Db::execute(
                'INSERT INTO `' . Db::name('config')->getTable() . '` (`name`, `value`, `update_time`) VALUES '
                . $holder
                . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `update_time` = VALUES(`update_time`)',
                $bind
            );
            $updated = count($rows);
        });

        // 让本次及各进程尽快看到新配置（清 Redis 缓存 + 本进程 memo）
        if ($updated > 0) {
            SysConfig::flush();
        }

        // 开启维护模式：立刻作废此前签发的令牌（超管豁免），
        // 已登录的普通用户下一次请求就会 401 被退回登录页，无需等令牌过期。
        if (array_key_exists('system.maintenance', $values) && self::truthy($values['system.maintenance'])) {
            SessionGuard::cut();
        }

        return $updated;
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes'], true);
    }

    /** 配置值统一按字符串入库，非标量（数组等）转 JSON */
    private static function encode(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
