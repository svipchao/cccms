<?php

declare(strict_types=1);

namespace cccms\model;

use think\model\Pivot;
use think\model\relation\HasMany;

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

    public function getUserDept(int $user_id): array
    {
        return $this->where('user_id', $user_id)->select()->toArray();
    }
}
