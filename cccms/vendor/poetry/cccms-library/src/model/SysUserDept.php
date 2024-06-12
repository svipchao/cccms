<?php

declare(strict_types=1);

namespace cccms\model;

use think\model\Pivot;

class SysUserDept extends Pivot
{
    /**
     * 创建模型实例
     * @return static
     */
    public static function mk($data = []): SysUserDept
    {
        return new static($data);
    }
}
