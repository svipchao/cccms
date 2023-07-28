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
    public function getUserInfo(string $key = 'all', mixed $default = ''): mixed
    {
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
        $node = $node ?: NodeService::mk()->getCurrentNode();
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
        $data = $this->app->cache->get('SysUserNodes_' . $userInfo['id'], []);
        if (empty($data) && !$userInfo['is_admin']) {
            $sqlArr = [];
            $roleIds = join(',', SysRole::mk()->getAllOpenRoleIds());
            if ($roleIds) $sqlArr[] = '(a1.role_id = a2.role_id and a1.role_id in (' . $roleIds . '))';

            $deptIds = join(',', SysDept::mk()->getAllOpenDeptIds());
            if ($deptIds) $sqlArr[] = '(a1.dept_id = a2.dept_id and a1.dept_id in (' . $deptIds . '))';

            $postIds = join(',', SysPost::mk()->getAllOpenPostIds());
            if ($postIds) $sqlArr[] = '(a1.post_id = a2.post_id and a1.post_id in (' . $postIds . '))';

            if (!empty($sqlArr)) {
                $data = SysAuth::mk()->alias('a1')
                    ->join('sys_auth a2', join(' or ', $sqlArr))
                    ->where('a2.user_id', 2)->with(['post'])->select()->toArray();
            }
            // 单独拆出来 否则会扫描全表 数据越多越慢
            $userAuth = SysAuth::mk()->with(['post'])->where(['user_id' => $userInfo['id']])->_list();
            $data = array_merge($data, $userAuth);
            $this->app->cache->set('SysUserNodes_' . $userInfo['id'], $data);
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
        // 0:本人,1:本人及下属,2:本部门,3:本部门及下属部门,4:全部
        $data = $this->getUserAuths($userInfo);
        [$deptIds, $range3Ids] = [[], []];
        foreach ($data as $d) {
            if ($d['range'] == 2) {
                $deptIds[$d['dept_id']] = $d['dept_id'];
            } elseif ($d['range'] == 3) {
                $range3Ids[$d['dept_id']] = $d['dept_id'];
            } elseif ($d['range'] == 4) {
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
     * 获取用户拥有的角色ID
     * @param array $userInfo
     * @return array
     */
    public function getUserRoleIds(array $userInfo = []): array
    {
        $data = $this->getUserAuths($userInfo);
        $roleIds = array_column($data, 'role_id');
        $roleChildIds = $roleIds ? SysRole::mk()->whereOr(array_map(function ($item) {
            return ['dept_ids', 'like', '%,' . $item . ',%'];
        }, $roleIds))->column('id') : [];
        if (empty($roleIds) && empty($roleChildIds)) return [];
        return ArrExtend::toOneUnique([...$roleIds, ...$roleChildIds]);
    }

    /**
     * 获取用户拥有的权限节点
     * @param array $userInfo
     * @return array
     */
    public function getUserNodes(array $userInfo = []): array
    {
        $userInfo = $userInfo ?: $this->getUserInfo();
        if ($userInfo['is_admin']) return NodeService::mk()->getNodes();
        $nodes = array_column($this->getUserAuths($userInfo), 'node');
        return array_keys(NodeService::mk()->setFrameNodes($nodes));
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
