<?php

declare(strict_types=1);

namespace cccms\model;

use think\model\Pivot;

class SysDeptRole extends Pivot
{
    /**
     * 创建模型实例
     * @param array $data
     * @return SysDeptRole
     */
    public static function mk(array $data = []): SysDeptRole
    {
        return new static($data);
    }
}
