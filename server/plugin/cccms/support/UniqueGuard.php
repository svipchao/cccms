<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\BaseModel;

/**
 * 唯一值查重（用户名 / 角色标识 / 岗位编码 / 租户标识与域名）。
 *
 * 口径统一为：**绕过全部作用域** + `withTrashed()` + 可选的「排除自身」。
 *
 * 为什么必须这样查：
 *   - 这些列上的唯一索引是**全局键**（不含 `tenant_id`），只按当前租户查会漏判，
 *     插入时直接撞唯一键报 500；
 *   - 软删除行仍占用该值，不带 `withTrashed()` 会把「回收站里已占用」误报成可用。
 *
 * 提示文案由调用方给 key：各模块的「已存在 / 在回收站」措辞不同，
 * 且刻意不给「是否存在」的明确答复（避免被用来探测他人取值）。
 */
final class UniqueGuard
{
    /**
     * @param class-string<BaseModel> $modelClass 目标模型类名
     * @param array<string,string>    $params     文案插值（如 `['code' => $code]`）
     */
    public static function assertFree(
        string $modelClass,
        string $column,
        string $value,
        int $excludeId,
        string $existsKey,
        string $trashedKey,
        array $params = []
    ): void {
        if ($value === '') {
            // 必填校验由调用方负责：各模块的 required 文案与豁免条件不同
            return;
        }

        $query = $modelClass::withoutAllScopes('唯一索引是全局键，跨租户查重才不会撞索引')
            ->withTrashed()
            ->where($column, $value);

        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        $exist = $query->find();
        if (!$exist) {
            return;
        }

        throw new ApiException(
            !empty($exist->delete_time) ? I18n::t($trashedKey, $params) : I18n::t($existsKey),
            422
        );
    }
}
