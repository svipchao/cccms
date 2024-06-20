<?php

declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\extend\JwtExtend;
use cccms\model\{SysDept, SysRoleNode, SysUserDept};

class UserService extends Service
{
    protected mixed $userInfo;

    /**
     * 获取 accessToken 值 优先级
     * 注意：GET传进来需要进行 urlDecode (PHP)/encodeURIComponent(JS)加密
     * @return string
     */
    public static function getAccessToken(): string
    {
        return (string)request()->header('accessToken', request()->param('accessToken', Session('accessToken')));
    }

    /**
     * 获取用户信息
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getUserInfo(string $key = 'all', mixed $default = null): mixed
    {
        $userInfo = JwtExtend::verifyToken(static::getAccessToken());
        if (!$userInfo || !empty($userInfo['exp']) && $userInfo['exp'] < time()) {
            if ($default !== '') return $default;
            _result(['code' => 401, 'msg' => '登陆状态失效，请重新登陆'], _getEnCode());
        }
        if ($key == 'all') $default = $userInfo;
        return $userInfo[$key] ?? $default;
    }

    /**
     * 获取后台用户ID
     * @return integer
     */
    public static function getUserId(): int
    {
        return static::getUserInfo('id', 0);
    }

    /**
     * 判断是否登录
     * @return bool
     */
    public static function isLogin(): bool
    {
        return (bool)static::getUserInfo('id', false);
    }

    /**
     * 判断是否是管理员
     * @return bool
     */
    public static function isAdmin(int $user_id = 0): bool
    {
        return static::getUserInfo('id', $user_id) === 1;
    }

    /**
     * 判断是否有权限
     * @param string $node 权限节点
     * @return bool
     */
    public static function isAuth(string $node = ''): bool
    {
        return in_array($node ?: NodeService::getCurrentNode(), static::getUserNodes());
    }

    /**
     * 获取用户拥有的权限节点
     * @param int $user_id
     * @return array
     */
    public static function getUserNodes(int $user_id = 0): array
    {
        if (static::isAdmin($user_id)) return NodeService::getNodes();
        $user_id = $user_id ?: static::getUserId();
        $data = static::$app->cache->get('SysUserAuth_' . $user_id, []);
        if (empty($data)) {
            $data = SysRoleNode::mk()->getUserNodes($user_id);
            static::$app->cache->set('SysUserAuth_' . $user_id, $data);
        }
        return array_keys(NodeService::instance()->setFrameNodes($data));
    }

    /**
     * 获取用户拥有的部门(权限范围)
     * @param int $user_id
     * @return array
     */
    public static function getUserDept(int $user_id = 0): array
    {
        if (static::isAdmin()) return SysDept::mk()->getAllOpenDept();
        $user_id = $user_id ?: static::getUserId();
        $userDeptRelation = SysUserDept::mk()->getUserDept($user_id);
        // 0:本人,1:本部门,2:本部门及下属部门
        [$deptIds, $range3Ids] = [[], []];
        foreach ($userDeptRelation as $relation) {
            if ($relation['auth_range'] == 1) {
                $deptIds[$relation['dept_id']] = $relation['dept_id'];
            } elseif ($relation['auth_range'] == 2) {
                $range3Ids[$relation['dept_id']] = $relation['dept_id'];
            }
        }
        // $dept = SysDept::mk()->whereFindInSet('dept_path', 2)->select()->toArray();
        return SysDept::mk()->where(function ($query) use ($deptIds, $range3Ids) {
            $query->whereOr(array_map(function ($item) {
                return ['dept_path', 'like', '%,' . $item . ',%'];
            }, $range3Ids))->whereOr('id', 'in', $deptIds);
        })->where('status', 1)->_list();
    }

    /**
     * 获取用户拥有的部门ID(权限范围)
     * @param int $user_id
     * @return array
     */
    public static function getUserDeptIds(int $user_id = 0): array
    {
        if (static::isAdmin()) return SysDept::mk()->getAllOpenDeptIds();
        $user_id = $user_id ?: static::getUserId();
        $dept = static::getUserDept($user_id);
        return array_column($dept, 'id');
    }
}
