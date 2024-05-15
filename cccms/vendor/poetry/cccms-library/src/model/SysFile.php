<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\extend\FormatExtend;
use think\model\relation\{HasOne, HasMany};

class SysFile extends Model
{
    protected $hidden = ['type', 'user'];

    protected $append = ['file_link'];

    public function cate(): HasOne
    {
        return $this->hasOne(SysFileCate::class, 'id', 'cate_id')->bind([
            'cate_name'
        ]);
    }

    public function user(): hasOne
    {
        return $this->hasOne(SysUser::class, 'id', 'user_id')->bind([
            'nickname',
            'username'
        ]);
    }

    // 关联权限记录表
    public function relationAuth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'user_id', 'user_id');
    }

    public function searchCateIdAttr($query, $value)
    {
        $query->where('cate_id', '=', $value);
    }

    public function getFileSizeAttr($value): string
    {
        return FormatExtend::formatBytes($value);
    }

    public function getFileLinkAttr($value, $data): string
    {
        return request()->domain() . '/file/' . ($data['file_code'] ?? '404');
    }
}
