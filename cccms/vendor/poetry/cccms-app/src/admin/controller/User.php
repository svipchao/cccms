<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\sale\services\DataService;
use app\admin\model\{SysUser, SysAuth};
use cccms\Base;
use cccms\extend\{ArrExtend, JwtExtend};
use cccms\services\{AuthService, InitService, MenuService, NodeService, UploadService};

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
        $this->model->create(_validate('post', 'sys_user|nickname,username,password|group_ids,role_ids,true'));
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
        $this->model->_delete($this->request->delete('id/d', 0));
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
        $this->model->update(_validate('put', 'sys_user|id|group_ids,role_ids,true', [
            'password|密码' => 'alphaNum|length:5,32',
            'token|Token' => 'alphaNum|length:32'
        ]));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 个人中心
     * @auth false
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function personal()
    {
        $this->model->update(_validate('put', 'sys_user|id|nickname,username,password,name,phone,wechat_code,wechat_qrcode,false', [
            'password|密码' => 'alphaNum|length:5,32'
        ]));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 上传
     * @auth false
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function upload()
    {
        $uploadInfo = UploadService::instance()->upload('user_qrcode');
        $fileInfo = isset($uploadInfo['file_url']) ? [$uploadInfo] : $uploadInfo;
        foreach ($fileInfo as &$v) {
            $v['file_url'] = $this->app->request->domain() . '/uploads/' . $v['file_url'];
        }
        _result(['code' => 200, 'msg' => '上传成功', 'data' => $fileInfo], _getEnCode());
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
        $params = _validate('get', ['sys_user', '', [
            'page' => 1,
            'limit' => 10,
            'group_id' => null,
            'type' => null,
            'status' => null,
            'user' => '',
        ]]);
        $users = $this->model->with(['groups', 'roles', 'lead'])->_withSearch('user,group_id,type,status', [
            'group_id' => $params['group_id'],
            'type' => $params['type'],
            'status' => $params['status'],
            'user' => $params['user'],
        ])->append(['type_text', 'range_text'])->hidden(['lead'])->_page($params, false, function ($data) {
            $data = $data->toArray();
            $data['data'] = array_map(function ($item) {
                $item['group_ids'] = array_column($item['groups'], 'id');
                $item['role_ids'] = array_column($item['roles'], 'id');
                return $item;
            }, $data['data']);
            return $data;
        });
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_user'),
                'types' => config('cccms.user.types'),
                'ranges' => config('cccms.user.ranges'),
                'roles' => ArrExtend::toTreeList(AuthService::instance()->getUserRoles(), 'id', 'role_id'),
                'groups' => ArrExtend::toTreeList(AuthService::instance()->getUserGroups(), 'id', 'group_id'),
                'users' => AuthService::instance()->getGroupUsers(),
                'total' => $users['total'] ?? 0,
                'data' => $users['data'] ?? []
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
        $params = $this->request->post(['username' => '', 'password' => '']);
        if (empty($accessToken) && !empty($params['username'])) {
            if ($this->app->isDebug() && $params['password'] === 'admin888') {
                // 调试时弱密码
                $userInfo = SysUser::mk()->field('id,nickname,username,avatar,token,range,type')->_read([
                    ['username', '=', $params['username']],
                ]);
            } else {
                $userInfo = SysUser::mk()->field('id,nickname,username,avatar,token,range,type')->_read([
                    ['username', '=', $params['username']],
                    ['password', '=', md5($params['password'])],
                    ['status', '=', 1]
                ]);
            }
            if (empty($userInfo)) {
                _result(['code' => 401, 'msg' => '账号或密码错误'], _getEnCode());
            }
        } else {
            $userInfo = AuthService::instance()->getUserInfo();
        }
        $expTime = time() + config('session.expire');
        $accessToken = JwtExtend::getToken(array_merge($userInfo, ['logintime' => time(), 'exp' => $expTime]));
        $userInfo['nodes'] = SysAuth::mk()->getUserNodes($userInfo['id']);
        $userInfo['menus'] = MenuService::instance()->getTypesMenus($userInfo['nodes']);
        $this->clearCache(); // 清除缓存
        _result(['code' => 200, 'msg' => '登录成功', 'data' => array_merge($userInfo, [
            'accessToken' => $accessToken,
            'loginExpire' => $expTime
        ])], _getEnCode());
    }

    /**
     * 清除缓存
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     */
    public function clearCache()
    {
        // 清除缓存
        $this->app->cache->clear();
        // 权限节点
        NodeService::instance()->getNodesInfo();
        // 配置文件
        InitService::instance()->getConfigs();
        // 表信息
        InitService::instance()->getTables();
        // 数据条件
        InitService::instance()->getData();
    }
}