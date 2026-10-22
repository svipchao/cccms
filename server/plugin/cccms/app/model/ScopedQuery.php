<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use plugin\cccms\support\DataScope;
use plugin\cccms\support\TenantContext;
use think\db\Query;

/**
 * 带「写入字段级规则」的查询构造器（`BaseModel::$query` 指向它）。
 *
 * 读取范围由 `BaseModel::scopeDataScope()`（全局作用域）负责；
 * **写入**由这里兜底：把当前用户不可写的字段（`hidden` / `mask` / `encrypt` / `readonly`）
 * 从 insert / update / save 的数据里剔除，避免掩码值、密文、空值被写回库里。
 *
 * 为什么放在查询层，而不是模型事件：
 *   1. 业务写入都是 `Model::where(...)->update()` 这类**构造器调用**，模型事件不会触发，
 *      写在事件里等于没生效；
 *   2. 字段级规则按人 / 按角色动态变化，配置期拿不到「当前用户」，只能在执行期判断。
 *
 * 超管与无用户场景（CLI、登录链路）在 `DataScope::applyWriteRules()` 里直接放行。
 */
final class ScopedQuery extends Query
{
    public function insert(array $data = [], bool $getLastInsID = false)
    {
        if ($data === []) {
            $this->protectOptionData();
        } else {
            $this->protect($data);
        }

        return parent::insert($data, $getLastInsID);
    }

    public function insertAll(array $dataSet = [], int $limit = 0): int
    {
        foreach ($dataSet as &$row) {
            if (is_array($row)) {
                $this->protect($row);
            }
        }
        unset($row);

        return parent::insertAll($dataSet, $limit);
    }

    public function update(array $data = []): int
    {
        if ($data === []) {
            $this->protectOptionData();
        } else {
            $this->protect($data);
        }

        return parent::update($data);
    }

    public function save(array $data = [], bool $forceInsert = false)
    {
        if ($data === []) {
            $this->protectOptionData();
        } else {
            $this->protect($data);
        }

        return parent::save($data, $forceInsert);
    }

    /**
     * 剔除当前用户不可写的字段（无用户 / 超管时原样放行），并强制写入当前租户。
     *
     * 租户放在这里而不是模型事件里，理由与字段规则完全一致：
     * 业务写入都是 `Model::where(...)->update()` / `insertGetId()` 这类**构造器调用**，
     * 模型事件不会触发；写在事件里等于「新增带租户、编辑不带」，一改就串租户。
     *
     * `tenant_id` 对客户端**不可控**：先无条件下掉，再由 `TenantContext` 按当前租户回填，
     * 因此不存在「把数据写到别人租户」或「把自己租户的数据搬走」的入口。
     */
    private function protect(array &$data): void
    {
        if ($data === [] || !$this->model instanceof BaseModel) {
            return;
        }

        DataScope::applyWriteRules($data, $this->model->getName());

        // 是否仍挂着租户作用域：`options['scope']` 里有 `tenant` 才算。
        // `withoutAllScopes()` 的查询没有任何作用域（登录链路更新登录信息属于有意跨租户），
        // 这类写入不该被「请求内缺身份」的安全网拦下。
        $scope = $this->options['scope'] ?? null;
        TenantContext::applyWriteRules(
            $data,
            $this->model->participatesInTenant(),
            is_array($scope) && in_array('tenant', $scope, true)
        );
    }

    /** `Model::save()` 这条路走的是 `options['data']`，同样要过一遍 */
    private function protectOptionData(): void
    {
        $data = $this->options['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return;
        }

        $this->protect($data);
        $this->options['data'] = $data;
    }
}
