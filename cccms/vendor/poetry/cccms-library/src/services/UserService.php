<?php

declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\extend\{JwtExtend, ArrExtend};
use cccms\model\{SysDept, SysPost, SysRole, SysAuth, SysUser};

class UserService extends Service
{
    protected mixed $userInfo;

    /**
     * 获取 accessToken 值 优先级
     * 注意：GET传进来需要进行 urlDecode (PHP)/encodeURIComponent(JS)加密
     * @return string
     */
    public function getAccessToken(): string
    {
        return (string)request()->header('accessToken', request()->param('accessToken', Session('accessToken')));
    }

    /**
     * 获取用户信息
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getUserInfo(string $key = 'all', mixed $default = null): mixed
    {
        $id = 2;
        $userInfo = SysUser::mk()->findOrEmpty($id)->toArray();
        $userInfo['is_admin'] = $userInfo['id'] == 1;
        return $userInfo;
        $userInfo = JwtExtend::verifyToken($this->getAccessToken());
        if (!$userInfo || !empty($userInfo['exp']) && $userInfo < time()) {
            if ($default !== '') return $default;
            _result(['code' => 401, 'msg' => '登陆状态失效，请重新登陆'], _getEnCode());
        }
        if ($key == 'all') $default = $userInfo;
        return $userInfo[$key] ?? $default;
    }

    /**
     * 判断是否登录
     * @return bool
     */
    public function isLogin(): bool
    {
        return (bool)$this->getUserInfo('id', false);
    }

    /**
     * 判断是否是管理员
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->getUserInfo('is_admin', false);
    }

    /**
     * 判断是否有权限
     * @param string $node 权限节点
     * @return bool
     */
    public function isAuth(string $node = ''): bool
    {
        $node = $node ?: NodeService::instance()->getCurrentNode();
        return in_array($node, $this->getUserNodes());
    }

    /**
     * 获取用户拥有的权限信息
     * @param array $userInfo
     * @return array
     */
    public function getUserAuths(array $userInfo = []): array
    {
        $userInfo = $userInfo ?: $this->getUserInfo();
        $data = $this->app->cache->get('SysUserAuth_' . $userInfo['id'], []);
        if (empty($data) && !$userInfo['is_admin']) {
            $userData = SysAuth::mk()->where('user_id', $userInfo['id'])->_list();
            $userDeptIds = array_filter(array_column($userData, 'dept_id'));
            $userRoleIds = array_filter(array_column($userData, 'role_id'));

            // 部门暂时不允许设置角色和权限
            // $deptData = SysAuth::mk()->where('dept_id', 'in', $userDeptIds)->_list();
            // $deptRoleIds = array_filter(array_merge(array_column($userData, 'role_id'), array_column($deptData, 'role_id')));

            $roleData = SysAuth::mk()->where('role_id', 'in', $userRoleIds)->_list();
            $data = array_merge($userData, $roleData);
            foreach ($data as &$d) $d['key'] = md5(join('|', $d));
            $data = array_values(array_column($data, null, 'key'));
            $this->app->cache->set('SysUserAuth_' . $userInfo['id'], $data);
        }
        return $data;
    }

    /**
     * 获取用户拥有的部门ID(权限范围)
     * @param array $userInfo
     * @return array
     */
    public function getUserDeptIds(array $userInfo = []): array
    {
        $userInfo = $userInfo ?: $this->getUserInfo();
        // 0:本人,1:本人及下属,2:本部门,3:本部门及下属部门,4:全部
        $data = $this->getUserAuths($userInfo);
        [$deptIds, $range3Ids] = [[], []];
        foreach ($data as $d) {
            if ($userInfo['range'] == 2) {
                $deptIds[$d['dept_id']] = $d['dept_id'];
            } elseif ($userInfo['range'] == 3) {
                $range3Ids[$d['dept_id']] = $d['dept_id'];
            } elseif ($userInfo['range'] == 4) {
                return SysDept::mk()->getAllOpenDeptIds();
            }
        }
        $deptChildIds = $range3Ids ? SysDept::mk()->whereOr(array_map(function ($item) {
            return ['dept_ids', 'like', '%,' . $item . ',%'];
        }, $range3Ids))->column('id') : [];
        if (empty($deptIds) && empty($deptChildIds)) return [];
        return ArrExtend::toOneUnique([...$deptIds, ...$deptChildIds]);
    }

    /**
     * 获取用户拥有的部门ID(权限范围)
     * @param array $userInfo
     * @return array
     */
    public function getUserDepts($format = 'default'): array
    {
        if (UserService::instance()->isAdmin()) {
            $data = SysDept::mk()->where('status', 1)->_list();
        } else {
            $data = SysDept::mk()->where([
                ['status', '=', 1],
                ['id', 'in', $this->getUserDeptIds()]
            ])->_list();
        }
        if ($format == 'tree') {
            return ArrExtend::toTreeArray($data, 'id', 'dept_id');
        } elseif ($format == 'list') {
            return ArrExtend::toTreeList($data, 'id', 'dept_id');
        } else {
            return $data;
        }
    }

    /**
     * 获取用户拥有的角色ID
     * @param array $userInfo
     * @return array
     */
    public function getUserRoleIds(array $userInfo = []): array
    {
        $data = $this->getUserAuths($userInfo);
        $roleIds = array_column($data, 'role_id');
//        $roleChildIds = $roleIds ? SysRole::mk()->whereOr(array_map(function ($item) {
//            return ['role_ids', 'like', '%,' . $item . ',%'];
//        }, $roleIds))->column('id') : [];
//        if (empty($roleIds) && empty($roleChildIds)) return [];
        return ArrExtend::toOneUnique($roleIds);
    }

    /**
     * 获取用户拥有的权限节点
     * @param array $userInfo
     * @return array
     */
    public function getUserNodes(array $userInfo = []): array
    {
        $userInfo = $userInfo ?: $this->getUserInfo();
        if ($userInfo['is_admin']) return NodeService::instance()->getNodes();
        $nodes = array_column($this->getUserAuths($userInfo), 'node');
        return array_keys(NodeService::instance()->setFrameNodes($nodes));
    }

    /**
     * 获取用户下级用户ID
     * @param array $userInfo
     * @return array
     */
    public function getUserSubUserIds(array $userInfo = []): array
    {
        $userInfo = $userInfo ?: $this->getUserInfo();
        if ($userInfo['is_admin']) return [];
        $range = array_column($this->getUserAuths($userInfo), 'range');
        if (!in_array(1, $range)) return [$userInfo['id']];
        return [...SysUser::mk()->where('user_id', $userInfo['id'])->column('id'), $userInfo['id']];
    }
}
