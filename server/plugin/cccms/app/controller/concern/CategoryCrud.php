<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller\concern;

use plugin\cccms\app\logic\CategoryLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 「分类」四件套（树 / 新增 / 更新 / 删除）：字典与附件的分类接口逐行同构，只差 module 常量。
 *
 * 为什么用 trait 而不是把整组 action 提到抽象父类：**权限注解必须留在各自控制器的方法上**。
 * `PermScanner` 按方法属性注册按钮节点，`cccms:dict:category*` 与 `cccms:file:category*`
 * 是两套独立节点（各自挂在对应菜单下，授权互不牵连），注解没法在 trait 里参数化。
 * 所以注解留在原地、方法体收到这里，控制器只剩一行委托，权限与菜单归属完全不变。
 *
 * 方法一律 protected：`PermScanner` 只扫 public 方法，trait 里的实现不能被当成 action 注册。
 *
 * @phpstan-require-extends BaseController
 */
trait CategoryCrud
{
    /** 分类所属模块（`sys_category.module`），由使用方声明，取值见 `CategoryLogic::MODULES` */
    abstract protected function categoryModule(): string;

    protected function categoryTree(Request $request): Response
    {
        // trashed=1 → 回收站视图（平铺已删分类，父节点可能还活着，拼树会漏节点）
        return $this->ok(CategoryLogic::tree($this->categoryModule(), (bool)$request->get('trashed', false)));
    }

    protected function categoryCreate(Request $request): Response
    {
        return $this->ok(['id' => CategoryLogic::create($this->categoryModule(), $request->post())], I18n::t('common.created'));
    }

    protected function categoryModify(Request $request): Response
    {
        CategoryLogic::update((int)$request->input('id', 0), $request->post());

        return $this->ok(null, I18n::t('common.updated'));
    }

    protected function categoryRemove(Request $request): Response
    {
        CategoryLogic::delete((int)$request->input('id', 0));

        return $this->ok(null, I18n::t('common.deleted'));
    }
}
