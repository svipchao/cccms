<?php
declare (strict_types=1);

namespace cccms\service;

use cccms\Service;
use cccms\model\{SysUser, SysRole};
use cccms\extend\{ArrExtend, JwtExtend};
use think\db\exception\DbException;
use think\Paginator;

/**
 * 角色权限类
 * Class Auth
 * @package cccms
 */
class AuthService extends Service
{
    /**
     * 用户信息
     * @var array
     */
    protected $userInfo;

    /**
     * @var SysUser
     */
    private $userModel;

    /**
     * @var SysRole
     */
    private $roleModel;

    protected function initialize()
    {
        $this->userModel = new SysUser;
        $this->roleModel = new SysRole;
    }

    /**
     * 获取指定用户
     * @param string $field
     * @return mixed
     */
    public function getUserInfo(string $field = '')
    {
        if (empty($this->userInfo)) {
            $accessToken = JwtExtend::verifyToken(_getAccessToken());
            if (!$accessToken) {
                _result(['code' => 401, 'msg' => '请登录', 'url' => (string)url('/user/login')], _getEnCode());
            }
            $this->userInfo = $this->userModel->where(['id' => $accessToken['id'], 'token' => $accessToken['token']])->findOrEmpty()->toArray();
            if (empty($this->userInfo)) {
                _result(['code' => 401, 'msg' => '账号异常', 'url' => (string)url('/user/login')], _getEnCode());
            }
            if ($this->getUserInfo('status') !== 1) {
                _result(['code' => 401, 'msg' => '账号已禁用', 'url' => (string)url('/user/login')], _getEnCode());
            }
        }
        return $this->userInfo[$field] ?? $this->userInfo;
    }

    /**
     * 添加用户
     * @param array $param
     * @return bool
     */
    public function addUser(array $param = []): bool
    {
        $param = _validate($param, 'sys_user|username,nickname,password|nickname,username,password');
        $this->userModel->where(['nickname' => $param['nickname']])->value();
        if ($this->userModel->getByNickname($param['nickname']) !== null) {
            _result(['code' => 412, 'msg' => '昵称已存在'], _getEnCode());
        }
        if ($this->userModel->getByUsername($param['username']) !== null) {
            _result(['code' => 412, 'msg' => '账号已存在'], _getEnCode());
        }
        $param['token'] = md5(uniqid('cc.', true) . time());
        return (bool)$this->userModel->field('nickname,username,password,token')->insert($param);
    }

    /**
     * 修改用户
     * @param array $param
     * @return bool
     */
    public function updUser(array $param = []): bool
    {
        $param = _validate($param, 'sys_user|id,username,nickname,password,status|id');
        // 判断用户是否存在
        $user = $this->userModel->findOrEmpty($param['id']);
        if ($user->isEmpty()) {
            _result(['code' => 412, 'msg' => '用户不存在'], _getEnCode());
        }
        // 注意：
        //    这里只是认证了是否在同一个角色 并不包含职位或上下级关系认证
        //    具体职位或上下级关系认证可通过 角色授权相对应的方法
        // 例如：主管可以修改员工信息
        //    那么主管需要拥有用户列表及其用户修改等操作权限 反之员工则没有这些权限
        if (!$this->hasUserToUser($param['id'])) {
            _result(['code' => 412, 'msg' => '你没有权限修改该用户'], _getEnCode());
        }
        if ($this->userModel->getByNickname($param['nickname']) !== null) {
            _result(['code' => 412, 'msg' => '昵称已存在'], _getEnCode());
        }
        if ($this->userModel->getByUsername($param['username']) !== null) {
            _result(['code' => 412, 'msg' => '账号已存在'], _getEnCode());
        }
        // 下面进行正常修改操作
        return $user->allowField(['id', 'username', 'nickname', 'password', 'status'])->save($param);
    }

    /**
     * 删除用户
     * @param int $user_id
     * @return bool
     */
    public function delUser(int $user_id): bool
    {
        if ($user_id === $this->getUserInfo('id')) {
            _result(['code' => 412, 'msg' => '你不能删除自己的账号'], _getEnCode());
        }
        $userInfo = $this->getUser($user_id);
        if (empty($userInfo)) {
            _result(['code' => 412, 'msg' => '用户不存在'], _getEnCode());
        }
        // 禁止删除超级管理员
        if ($userInfo['isadmin']) {
            _result(['code' => 412, 'msg' => '禁止删除管理员用户'], _getEnCode());
        }
        // 不判断组织和用户了，如果加功能的话，没办法面面俱到，够用了
        return $this->userModel->destroy($userInfo['id']);
    }

    /**
     * 获取用户
     * @param int $user_id
     * @return array
     */
    public function getUser(int $user_id = 0): array
    {
        if (empty($user_id)) {
            return $this->getUserInfo();
        } else {
            return $this->userModel->findOrEmpty($user_id)->toArray();
        }
    }

    /**
     * 获取用户拥有的角色ID
     * @param int $user_id 用户ID
     * @param bool $isSub 是否包含子级
     * @return array
     */
    public function getUserToRoleIds(int $user_id = 0, bool $isSub = false): array
    {
        $user = $this->getUser($user_id);
        if (empty($user)) return [];
        // 如果是管理员 则拥有顶级角色 与 全部角色
        if ($user['isadmin']) {
            $roleIds = ArrExtend::getOneUnique(array_column($this->getRoles(), 'id'));
            array_push($roleIds, 0);
            return $roleIds;
        } else {
            $res = $this->userModel->findOrEmpty($user['id'])->toRole()->column('id');
            // 判断是否包含子级
            if ($isSub) {
                foreach ($res as $val) {
                    array_push($res, ...AuthService::instance()->getRoleToRoleIds($val));
                }
            }
            return ArrExtend::getOneUnique($res);
        }
    }

    /**
     * 获取用户拥有的权限
     * @param int $user_id
     * @return array
     */
    public function getUserToNodes(int $user_id = 0): array
    {
        $user = $this->getUser($user_id);
        if (empty($user)) return [];
        // 如果是管理员 则拥有全部权限
        if ($user['isadmin']) {
            return array_keys(NodeService::instance()->getAuthNodes());
        } else {
            [$nodes, $roles] = [[], $this->getRoles($this->getUserToRoleIds($user['id']))];
            foreach ($roles as $val) {
                array_push($nodes, ...$val['nodes']);
            }
            return ArrExtend::getOneUnique($nodes);
        }
    }

    /**
     * 判断当前用户是否超级管理员
     * @return bool
     */
    public function hasAdmin(): bool
    {
        return (bool)$this->getUserInfo('isadmin');
    }

    /**
     * 判断是否登陆
     * @return bool
     */
    public function hasLogin(): bool
    {
        return (bool)$this->getUserInfo('id');
    }

    /**
     * 判断当前用户是否可以修改目标用户
     * @param int $user_id
     * @return bool
     */
    public function hasUserToUser(int $user_id): bool
    {
        // 这里没有验证用户是否存在
        if ($this->hasAdmin()) return true;
        // 采用反向查询 项目上线之后 用户量级会大得多
        // 为了不造成资源浪费 通过对比当前用户与要修改用户的角色是否存在并集
        // 获取用户的角色及其组织
        $targetRoleIds = $this->getUserToRoleIds($user_id);
        $currentRoleIds = $this->getUserToRoleIds($this->getUserInfo('id'));
        return !empty(array_intersect($targetRoleIds, $currentRoleIds));
    }

    /**
     * 判断用户是否拥有角色
     * @param int $user_id 为空则判断当前用户
     * @param int $role_id
     * @return bool
     */
    public function hasUserToRole(int $role_id, int $user_id = 0): bool
    {
        return in_array($role_id, $this->getUserToRoleIds($user_id));
    }

    /**
     * 判断用户是否拥有权限
     * @param int $user_id 为空则判断当前用户
     * @param string $node
     * @return bool
     */
    public function hasUserAuth(string $node, int $user_id = 0): bool
    {
        // 先判断节点是否需要授权
        $nodeArr = NodeService::instance()->getNode($node);
        if (empty($nodeArr)) return false;
        if ($nodeArr['login'] && !$nodeArr['auth']) return true;
        return in_array($node, $this->getUserToNodes($user_id));
    }

    /**
     * 增删改角色通用判断
     * @param array $param
     * @return array|void
     */
    public function checkRole(array $param = [])
    {
        // 增加
        if (isset($param['role_id'])) {
            if (empty($param['role_id'])) {
                // 不是管理员 父级角色不允许为 0
                if (!$this->hasAdmin()) {
                    _result(['code' => 412, 'msg' => '你没有该角色权限'], _getEnCode());
                }
            } else {
                // 判断父级角色是否存在
                if (empty($this->getRole((int)$param['role_id']))) {
                    _result(['code' => 412, 'msg' => '父级角色不存在'], _getEnCode());
                }
                // 判断是否拥有该角色
                if (!$this->hasUserToRole((int)$param['role_id'])) {
                    _result(['code' => 412, 'msg' => '你没有该角色权限'], _getEnCode());
                }
            }
            // 删除 修改
            if (isset($param['id'])) {
                // 判断角色是否存在
                $role = $this->roleModel->findOrEmpty($param['id']);
                if ($role->isEmpty()) {
                    _result(['code' => 412, 'msg' => '角色不存在'], _getEnCode());
                }
                // 判断是否自己的子角色
                if ($this->hasRoleToRole((int)$param['id'], (int)$param['role_id'])) {
                    _result(['code' => 412, 'msg' => '你不能选择该角色的子级角色'], _getEnCode());
                }
                // 修改了父级角色 处理角色权限
                if ((int)$param['role_id'] !== (int)$role['role_id']) {
                    $param['nodes'] = [];
                }
            }
            return $param;
        } elseif (isset($param['status'], $param['id'])) {
            // 判断角色是否存在
            $role = $this->roleModel->findOrEmpty($param['id']);
            if ($role->isEmpty()) {
                _result(['code' => 412, 'msg' => '角色不存在'], _getEnCode());
            }
            return $param;
        } else {
            _result(['code' => 412, 'msg' => '非法操作'], _getEnCode());
        }
    }

    /**
     * 添加角色
     * @param array $param
     * @return bool
     */
    public function addRole(array $param = []): bool
    {
        $param = $this->checkRole(_validate($param, 'sys_role|role_id,role_name,role_desc,status,nodes|role_id,role_name,nodes'));
        return $this->roleModel->allowField(['id', 'role_id', 'role_name', 'role_desc', 'status', 'nodes'])->save($param);
    }

    /**
     * 更新角色
     * @param array $param
     * @return bool
     */
    public function updRole(array $param = []): bool
    {
        $param = $this->checkRole(_validate($param, 'sys_role|id,role_id,role_name,role_desc,status,nodes|id'));
        return (bool)$this->roleModel->allowField(['id', 'role_id', 'role_name', 'role_desc', 'status', 'nodes'])->update($param);
    }

    /**
     * 删除角色
     * @param int $role_id
     * @return bool
     */
    public function delRole(int $role_id): bool
    {
        if (empty($this->getRole($role_id))) {
            _result(['code' => 412, 'msg' => '角色不存在'], _getEnCode());
        }
        // 判断是否拥有该角色
        if (!$this->hasUserToRole($role_id)) {
            _result(['code' => 412, 'msg' => '你未拥有该角色'], _getEnCode());
        }
        // 判断是否拥有子角色
        if (!empty($this->getRoleToRoleIds($role_id))) {
            _result(['code' => 412, 'msg' => '你不能删除含有子角色的角色'], _getEnCode());
        }
        // 不判断组织和用户了，如果加功能的话，没办法面面俱到，够用了
        return $this->roleModel->destroy($role_id);
    }

    /**
     * 获取指定角色
     * @param int $role_id
     * @return array
     */
    public function getRole(int $role_id): array
    {
        // 由于存在关联模型 需要先判断ID是否存在
        $role = $this->roleModel->findOrEmpty($role_id);
        return $role->isEmpty() ? [] : $role->toArray();
    }

    /**
     * 获取所有角色
     * @param array $roleIds 为空则获取全部数据
     * @return array
     */
    public function getRoles(array $roleIds = []): array
    {
        try {
            return $this->roleModel->select($roleIds ?: null)->toArray();
        } catch (DbException $e) {
            return [];
        }
    }

    /**
     * 获取角色下的权限
     * @param int $role_id
     * @return array
     */
    public function getRoleToNodes(int $role_id): array
    {
        return $this->getRole($role_id)['nodes'];
    }

    /**
     * 获取角色下属角色ID
     * @param int $role_id
     * @return array
     */
    public function getRoleToRoleIds(int $role_id): array
    {
        return ArrExtend::getChildrenIds($this->getRoles(), $role_id, false, 'id', 'role_id');
    }

    /**
     * 获取角色下的用户
     * @param array $roleIds 角色ID
     * @return Paginator
     * @throws DbException
     */
    public function getRoleToUsers(array $roleIds = []): Paginator
    {
        return $this->roleModel->toOneUser()->where(['id' => $roleIds])->paginate([
            'list_rows' => $param['limit'] ?? 10,
            'page' => $param['page'] ?? 1
        ]);
    }

    /**
     * 判断角色是否拥有角色
     * @param int $role_id 当前角色ID
     * @param int $children_id 需要判断的子级角色ID
     * @return bool
     */
    public function hasRoleToRole(int $role_id, int $children_id): bool
    {
        // 需要过滤一下角色ID数组内的0
        return in_array($children_id, array_filter($this->getRoleToRoleIds($role_id)));
    }
}