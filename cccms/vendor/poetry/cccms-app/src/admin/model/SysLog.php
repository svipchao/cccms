<?php
declare(strict_types=1);

namespace app\admin\model;

use think\model\relation\{HasOne, HasMany};
use cccms\Model;
use cccms\services\AuthService;

class SysLog extends Model
{
    protected $hidden = ['user'];

    public function user(): HasOne
    {
        return $this->hasOne(SysUser::class, 'id', 'user_id')
            ->bind(['username', 'nickname']);
    }

    // 关联权限记录表
    public function relationAuth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'user_id', 'user_id');
    }

    public function searchUserAttr($query, $value)
    {
        // 管理员可以查看任何用户
        $query->alias('log')->when(!AuthService::instance()->isAdmin(), function ($query) {
            $query->where('log.user_id', 'in', function ($query) {
                $query->table('sys_auth')->whereOr([
                    ['group_id', 'in', AuthService::instance()->getUserGroups(true, false, true)],
                    ['user_id', '=', AuthService::instance()->getUserInfo('id')]
                ])->field('user_id');
            });
        });
        $query->where('log.user_id', 'in', function ($query) use ($value) {
            $query->table('sys_user')->where('nickname|username', 'like', '%' . $value . '%')->field('id');
        });
    }

    public function searchReqMethodAttr($query, $value)
    {
        $query->where('log.req_method', '=', $value);
    }

    public function searchReqParamAttr($query, $value)
    {
        $query->where('log.req_param', 'like', "%" . $value . "%");
    }
}
