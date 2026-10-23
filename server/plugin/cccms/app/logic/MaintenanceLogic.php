<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\I18n;
use plugin\cccms\support\MenuSyncer;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\PermScanner;
use plugin\cccms\support\SqlFileRunner;
use plugin\cccms\support\SysConfig;
use support\Log;

/**
 * 系统维护：把原先只能在命令行执行的动作搬到后台界面。
 *
 * 与 CLI 命令**共用同一份实现**（MenuSyncer / PermScanner），所以「界面点一下」和
 * 「手动 php webman ...」的结果完全一致。
 */
final class MaintenanceLogic
{
    /** 刷新范围：全部 / 菜单 / 按钮节点 / 业务插件表结构 / 缓存 */
    public const SCOPES = ['all', 'menu', 'perm', 'schema', 'cache'];

    public static function refresh(string $scope): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new ApiException(I18n::t('maintenance.unknown_scope', ['scope' => $scope]), 422);
        }

        $result = [];
        $cost   = [];
        $start  = microtime(true);

        // 每步单独计时：刷新变慢时能直接从日志 / 响应看出卡在哪一步
        if ($scope === 'all' || $scope === 'menu') {
            $t = microtime(true);
            $result['menu'] = MenuSyncer::syncAll();
            $cost['menu']   = (int)round((microtime(true) - $t) * 1000);
        }
        if ($scope === 'all' || $scope === 'perm') {
            $t = microtime(true);
            $result['perm'] = self::syncPermissionNodes();
            $cost['perm']   = (int)round((microtime(true) - $t) * 1000);
        }
        if ($scope === 'all' || $scope === 'schema') {
            $t = microtime(true);
            $result['schema'] = self::syncPluginSchemas();
            $cost['schema']   = (int)round((microtime(true) - $t) * 1000);
        }
        if ($scope === 'all' || $scope === 'cache') {
            $t = microtime(true);
            $result['cache'] = self::clearCache();
            $cost['cache']   = (int)round((microtime(true) - $t) * 1000);
        }

        $cost['total'] = (int)round((microtime(true) - $start) * 1000);
        $result['cost'] = $cost;

        if ($cost['total'] > 1000) {
            Log::warning('[maintenance] refresh scope=' . $scope . ' cost=' . json_encode($cost));
        }

        return $result;
    }

    /**
     * 业务插件表结构（执行各插件的 db/schema.sql，幂等）。
     *
     * @return array<string,int> 插件名 => 执行语句数
     */
    private static function syncPluginSchemas(): array
    {
        $out = [];
        foreach (SqlFileRunner::pluginSchemaFiles() as $file) {
            $out[SqlFileRunner::pluginNameOf($file)] = SqlFileRunner::run($file);
        }

        return $out;
    }

    /** 扫描控制器 #[Permission] 注解 → 同步 sys_menu 按钮节点 */
    private static function syncPermissionNodes(): array
    {
        $scanner = new PermScanner();
        $items   = PermScanner::scanAllPlugins();

        $errors = PermScanner::validate($items);
        if ($errors !== []) {
            // 注解写错了就整批不写库，把最早几条错误抛给界面，避免只同步一半
            throw new ApiException(I18n::t('maintenance.permission_annotation_invalid', [
                'errors' => implode('；', array_slice($errors, 0, 3)),
            ]), 422);
        }

        $result = $scanner->sync($items);

        return ['created' => $result['created'], 'skipped' => $result['skipped']];
    }

    /** 清理运行期缓存 */
    private static function clearCache(): array
    {
        // Redis：sys_config（跨进程共享，清了立即生效）
        SysConfig::flush();
        // Redis：角色 / 权限集合缓存（版本号 +1，所有用户立即重算）
        PermissionCache::bump();
        // 本进程的注解元数据缓存；其余 worker 靠部署 reload 重建
        PermissionMeta::flush();

        return ['config' => true, 'permission' => true, 'permission_meta' => true];
    }
}
