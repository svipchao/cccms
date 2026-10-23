<?php

declare(strict_types=1);

namespace plugin\cccms\support\DataScope;

use InvalidArgumentException;

/**
 * 字段级数据权限的**纯函数**部分：规则编译 + 掩码。
 *
 * 与 `DataScope` 的分工：那边负责「当前用户是谁、他有哪些规则、日志怎么记」，
 * 这里只做「把规则数组变成有序动作列表」「把值按字段名打码」——
 * 不碰请求上下文，可以脱离数据库直接单测（见 `DataRuleCompileTest`）。
 */
final class FieldRules
{
    /** 允许的字段动作（`readonly` 只影响写入侧，出参照常） */
    public const ACTIONS = ['hidden', 'readonly', 'mask', 'encrypt'];

    /**
     * 把字段级规则编译成有序的「字段 + 动作」列表。
     *
     * 顺序必须保留：同一字段被多条规则命中时，`hidden` 会让字段从行里消失，
     * 后续规则自然不再处理它；折叠成 `field => action` 映射会改变这个语义。
     *
     * 编译失败（动作不在白名单、字段名为空或不是合法列名）直接抛异常，由调用方
     * **整条拒绝**：规则损坏时不能「跳过这条、其余照旧」—— 那等于把本该隐藏 /
     * 掩码的字段原样返回。保存侧（`DataRuleLogic::prepare()`）已做同样的校验，
     * 这里兜的是手改 SQL、旧版本数据、迁移异常。
     *
     * @param  array<int,array<string,mixed>> $rules 已按目标表过滤后的字段级规则
     * @return list<array{field:string,action:string}>
     * @throws InvalidArgumentException
     */
    public static function compile(array $rules): array
    {
        $compiled = [];

        foreach ($rules as $rule) {
            $field  = trim((string)($rule['field'] ?? ''));
            $action = strtolower(trim((string)($rule['action'] ?? '')));
            $id     = (int)($rule['id'] ?? 0);

            if ($field === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
                throw new InvalidArgumentException(
                    "字段规则 #{$id} 的字段名非法（" . ($field === '' ? '空' : $field) . '），已按 fail-closed 拒绝'
                );
            }

            if (!in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException(
                    "字段规则 #{$id} 的动作非法（" . ($action === '' ? '空' : $action) . '），已按 fail-closed 拒绝'
                );
            }

            $compiled[] = ['field' => $field, 'action' => $action];
        }

        return $compiled;
    }

    /**
     * 按字段名选择掩码策略。
     *
     * 只认常见命名（手机号 / 身份证），其余按长度均分保留首尾 —— 目的是「看不全」，
     * 不是「不可逆加密」，真实值永远不出库。
     */
    public static function mask(string $value, string $field): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($field, 'phone') || str_contains($field, 'mobile')) {
            return substr($value, 0, 3) . '****' . substr($value, -4);
        }
        if (str_contains($field, 'id_card') || str_contains($field, 'idcard')) {
            return substr($value, 0, 4) . '**********' . substr($value, -4);
        }
        $len = strlen($value);
        if ($len <= 2) {
            return $value;
        }
        $show = (int)floor($len / 3);

        return substr($value, 0, $show) . str_repeat('*', $len - 2 * $show) . substr($value, -$show);
    }
}
