<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysRole;
use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\services\{NodeService, AuthService};

/**
 * 角色管理
 * @sort 999
 */
class Role extends Base
{
    public function init()
    {
        $this->model = SysRole::mk();
    }

    /**
     * 添加角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $this->model->create(_validate('post', 'sys_role|role_name|nodes,true'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除角色
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
     * 修改角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $this->model->update(_validate('put', 'sys_role|id|nodes,true'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 角色列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $roles = $this->model->with(['groups'])->where([
            ['id', 'in', AuthService::instance()->getUserRoles(true)]
        ])->_list(null, function ($data) {
            $data = $data->toArray();
            return array_map(function ($item) {
                $item['group_ids'] = array_column($item['groups'], 'id');
                return $item;
            }, $data);
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_role'),
            'data' => ArrExtend::toTreeList($roles, 'id', 'role_id')
        ]], _getEnCode());
    }

    /**
     * 节点授权
     * @auth  true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function auth()
    {
        $role_id = $this->request->get('role_id/d', 0);
        // 需要授权的全部节点
        $allNodes = NodeService::instance()->getAuthNodes();
        if ($role_id === 0) {
            if (AuthService::instance()->isAdmin()) {
                $nodes = array_keys($allNodes);
            } else {
                $nodes = AuthService::instance()->getUserNodes();
            }
        } else {
            // column不会触发获取器
            $nodes = explode(',', $this->model->where('id', $role_id)->value('nodes'));
        }
        // 框架节点
        $frameNodes = NodeService::instance()->getFrameNodes();
        $nodes = array_intersect_key($allNodes, array_flip($nodes));
        foreach ($nodes as &$val) {
            // 移除无用数据
            unset($val['parentTitle'], $val['encode'], $val['methods'], $val['appName'], $val['auth'], $val['login'], $val['sort']);
        }
        // 将节点框架合并进权限节点中
        $nodes = array_merge($nodes, NodeService::instance()->setFrameNodes($nodes, $frameNodes));
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => ArrExtend::toTreeArray($nodes, 'currentNode', 'parentNode')
        ], _getEnCode());
    }
}