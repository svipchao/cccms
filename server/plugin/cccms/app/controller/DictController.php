<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\controller\concern\CategoryCrud;
use plugin\cccms\app\logic\DictLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class DictController extends BaseController
{
    use CategoryCrud;

    #[Permission(slug: 'cccms:dict:index', title: '字典类型列表')]
    public function index(Request $request): Response
    {
        return $this->ok(DictLogic::typePaginate($request->get()));
    }

    #[Permission(slug: 'cccms:dict:save', title: '新增字典类型')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => DictLogic::typeCreate($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:dict:update', title: '更新字典类型')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        DictLogic::typeUpdate((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:dict:delete', title: '删除字典类型')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        DictLogic::typeDelete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }

    #[Permission(slug: 'cccms:dict:data', title: '字典数据列表')]
    public function data(Request $request): Response
    {
        // trashed=1 → 回收站视图（只看该类型下已删的数据）
        return $this->ok(DictLogic::dataList(
            (int)$request->input('type_id', 0),
            (bool)$request->input('trashed', false)
        ));
    }

    #[Permission(slug: 'cccms:dict:save_data', title: '新增字典数据')]
    #[Restrict(methods: ['POST'])]
    public function saveData(Request $request): Response
    {
        return $this->ok(['id' => DictLogic::dataCreate($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:dict:update_data', title: '更新字典数据')]
    #[Restrict(methods: ['POST'])]
    public function updateData(Request $request): Response
    {
        DictLogic::dataUpdate((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:dict:delete_data', title: '删除字典数据')]
    #[Restrict(methods: ['POST'])]
    public function deleteData(Request $request): Response
    {
        DictLogic::dataDelete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }

    // ---- 批量操作（不可见 / 已删除的 id 自动跳过并回报） ----

    #[Permission(slug: 'cccms:dict:batch_status', title: '批量启停字典类型')]
    #[Restrict(methods: ['POST'])]
    public function batchStatus(Request $request): Response
    {
        $result = DictLogic::typeBatchStatus((array)$request->post('ids', []), (int)$request->post('status', 1));

        return $this->ok($result, I18n::t('common.updated_count', ['count' => $result['affected']]));
    }

    #[Permission(slug: 'cccms:dict:batch_delete', title: '批量删除字典类型')]
    #[Restrict(methods: ['POST'])]
    public function batchDelete(Request $request): Response
    {
        $result = DictLogic::typeBatchDelete((array)$request->post('ids', []));

        return $this->ok($result, I18n::t('common.deleted_count', ['count' => $result['affected']]));
    }

    #[Permission(slug: 'cccms:dict:batch_status_data', title: '批量启停字典数据')]
    #[Restrict(methods: ['POST'])]
    public function batchStatusData(Request $request): Response
    {
        $result = DictLogic::dataBatchStatus((array)$request->post('ids', []), (int)$request->post('status', 1));

        return $this->ok($result, I18n::t('common.updated_count', ['count' => $result['affected']]));
    }

    #[Permission(slug: 'cccms:dict:batch_delete_data', title: '批量删除字典数据')]
    #[Restrict(methods: ['POST'])]
    public function batchDeleteData(Request $request): Response
    {
        $result = DictLogic::dataBatchDelete((array)$request->post('ids', []));

        return $this->ok($result, I18n::t('common.deleted_count', ['count' => $result['affected']]));
    }

    // ---- 字典分类 ----
    // 与附件分类共用 sys_category 表与 CategoryLogic，但 slug 按模块拆开，
    // 这样「只有附件权限」的角色不会因为分类权限挂错菜单而被牵连。
    // 方法体在 CategoryCrud trait 里（与附件分类逐行同构），这里只保留权限注解。

    protected function categoryModule(): string
    {
        return 'dict';
    }

    #[Permission(slug: 'cccms:dict:category', title: '字典分类列表')]
    public function category(Request $request): Response
    {
        return $this->categoryTree($request);
    }

    #[Permission(slug: 'cccms:dict:category_save', title: '新增字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categorySave(Request $request): Response
    {
        return $this->categoryCreate($request);
    }

    #[Permission(slug: 'cccms:dict:category_update', title: '更新字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryUpdate(Request $request): Response
    {
        return $this->categoryModify($request);
    }

    #[Permission(slug: 'cccms:dict:category_delete', title: '删除字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryDelete(Request $request): Response
    {
        return $this->categoryRemove($request);
    }
}
