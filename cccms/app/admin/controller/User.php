<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysUser;
use cccms\extend\{ArrExtend, JwtExtend};
use cccms\services\{AuthService, MenuService};

/**
 * 用户管理
 * @sort 999
 */
class User extends Base
{
    public function init()
    {
        $this->model = SysUser::mk();
    }

    /**
     * 添加用户
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $params = _validate($this->request->post(), 'SysUser|nickname,username,password|groupIds');
        $res = $this->model->create($params);
        // 处理组织
        if (isset($params['groupIds'])) {
            $groups = AuthService::instance()->filterUserGroups($params['groupIds']);
            $res->groups()->saveAll($groups);
        }
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除用户
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        $id = $this->request->delete('id/d', 0);
        if ($id === _getAccessToken('id')) {
            _result(['code' => 403, 'msg' => '禁止删除自己的账户'], _getEnCode());
        }
        // 删除
        $res = $this->model->_read($id, false);
        if ($res->isEmpty()) {
            _result(['code' => 403, 'msg' => '用户不存在'], _getEnCode());
        }
        // 删除组织以及关联权限节点表数据
        $res->groups()->detach();
        $res->delete();
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 更新用户
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $params = _validate($this->request->put(), 'SysUser|id|groupIds,nickname,username');
        $params['password'] = $this->request->put('password', '');
        $res = $this->model->findOrEmpty($params['id']);
        if ($res->isEmpty()) {
            _result(['code' => 403, 'msg' => '用户不存在'], _getEnCode());
        }
        // 处理组织
        if (isset($params['groupIds'])) {
            $groups = AuthService::instance()->filterUserGroups($params['groupIds']);
            // 删除组织关联权限节点表数据
            $res->groups()->detach();
            $res->groups()->attach($groups);
        }
        $res->update($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 用户列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = $this->request->get(['group_id' => 0, 'type' => null, 'nickname' => '', 'username' => '', 'limit' => 10, 'page' => 1]);
        $groupIds = [];
        if ($params['group_id'] == 0 && !AuthService::instance()->isAdmin()) {
            $groupIds = AuthService::instance()->getUserGroups(true);
        } elseif (!AuthService::instance()->isUserGroup((int)$params['group_id'])) {
            _result(['code' => 403, 'msg' => '没有该组织权限']);
        } else {
            $groupIds = AuthService::instance()->getGroupChildren([$params['group_id']], true);
        }
        $where = [
            ['nickname', 'like', '%' . $params['nickname'] . '%'],
            ['username', 'like', '%' . $params['username'] . '%'],
        ];
        if (is_numeric($params['type'])) {
            $where[] = ['type', '=', $params['type']];
        }
        // 判断是否拥有当前组织
        $users = $this->model->where($where)->with(['groups']);
        if (!empty($params['group_id'])) {
            $users = $users->hasWhere('userGroup', [['group_id', '=', $params['group_id']]]);
        } elseif (!AuthService::instance()->isAdmin()) {
            $users = $users->hasWhere('userGroup', [['group_id', 'in', $groupIds]]);
        }
        $users = $users->_page($params);
        foreach ($users['data'] as &$user) {
            $user['groupIds'] = array_column($user['groups'], 'id');
        }
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_user'),
                'types' => config('cccms.user.types'),
                'groups' => ArrExtend::toTreeList(AuthService::instance()->getUserGroups(), 'id', 'group_id'),
                'total' => $users['total'],
                'data' => $users['data']
            ]
        ], _getEnCode());
    }

    /**
     * 用户登陆
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     */
    public function login()
    {
        $accessToken = $this->app->request->header('accessToken', '');
        if (empty($accessToken)) {
            $params = _validate($this->app->request->post(), 'sys_user|username,password');
            $userInfo = AuthService::instance()->setUserInfo([
                ['username', '=', $params['username']],
                ['password', '=', md5($params['password'])],
                ['status', '=', 1]
            ]);
        } else {
            $accessToken = JwtExtend::verifyToken($accessToken);
            if (!$accessToken) {
                _result(['code' => 401, 'msg' => 'Token已失效，请重新登陆'], _getEnCode());
            }
            $userInfo = AuthService::instance()->setUserInfo([
                ['id', '=', $accessToken['id']],
                ['token', '=', $accessToken['token']],
                ['status', '<>', 0]
            ]);
        }
        $userInfo['menus'] = MenuService::instance()->getTypesMenus($userInfo['nodes']);
        $expTime = time() + config('session.expire');
        $accessToken = JwtExtend::getToken([
            'id' => $userInfo['id'],
            'token' => $userInfo['token'],
            'logintime' => time(),
            'exp' => $expTime,
        ]);
        _result(['code' => 200, 'msg' => '登录成功', 'data' => array_merge($userInfo, [
            'accessToken' => $accessToken,
            'loginExpire' => $expTime
        ])], _getEnCode());
    }
}