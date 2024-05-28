<?php
declare(strict_types=1);

namespace app\admin\model;

use think\model\relation\{HasOne, HasMany};
use cccms\Model;
use cccms\services\{AuthService, TypesService};

class SysFile extends Model
{
    protected $hidden = ['type', 'user'];

    protected $append = ['file_link'];

    public function type(): HasOne
    {
        return $this->hasOne(SysTypes::class, 'id', 'type_id')->bind([
            'type_name' => 'name',
            'type_alias' => 'alias'
        ]);
    }

    public function user(): hasOne
    {
        return $this->hasOne(SysUser::class, 'id', 'user_id')
            ->bind(['nickname', 'username']);
    }

    // 关联权限记录表
    public function relationAuth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'user_id', 'user_id');
    }

    public function searchUserAttr($query, $value)
    {
        // 管理员可以查看任何用户
        $query->when(!AuthService::instance()->isAdmin(), function ($query) {
            $query->hasWhere('relationAuth', [
                ['group_id', 'in', AuthService::instance()->getUserGroups(true, false, true)]
            ])->whereOr('id', AuthService::instance()->getUserInfo('id'));
        });
        $query->hasWhere('user', function ($query) use ($value) {
            $query->where('nickname|username', 'like', "%" . $value . "%");
        });
    }

    public function searchTypeIdAttr($query, $value)
    {
        $types = TypesService::instance()->getTypes(4, 'id');
        if (empty($types)) {
            $value = 0;
        } elseif (!isset($types[$value])) {
            $value = array_shift($types)['id'] ?? 0;
        }
        $query->where('type_id', '=', $value);
    }

    public function setTypeIdAttr($value): int
    {
        $types = TypesService::instance()->getTypes(4, 'id');
        if (!isset($types[$value])) {
            _result(['code' => 403, 'msg' => '类型错误'], _getEnCode());
        }
        return (int)$value;
    }

    public function getFileSizeAttr($value): string
    {
        return _format_bytes($value);
    }

    public function getFileLinkAttr($value, $data): string
    {
        return request()->domain() . '/file/' . ($data['file_code'] ?? '404');
    }
}