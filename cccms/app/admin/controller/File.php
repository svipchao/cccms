<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\{Base, Storage};
use cccms\model\{SysFile, SysUser};
use cccms\services\{AuthService, TypesService};

/**
 * 附件管理
 * @sort 999
 */
class File extends Base
{
    public function init()
    {
        $this->model = SysFile::mk();
    }

    /**
     * 添加附件
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $file = $this->request->file('file');
        if (empty($file)) {
            _result(['code' => 403, 'msg' => '请选择文件'], _getEnCode());
        }
        $type_id = $this->request->post('type_id/d', 0);
        _result([
            'code' => 200,
            'msg' => '添加成功',
            'data' => Storage::instance()->upload($file, $type_id)
        ], _getEnCode());
    }

    /**
     * 删除附件
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        if (Storage::instance()->delete($this->request->delete('id/d', 0))) {
            _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
        }
    }

    /**
     * 修改附件
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $params = _validate($this->request->put(), 'sys_file|id|file_name,file_desc,extract_code,status,false');
        if ($this->model->update($params)) {
            _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '更新失败'], _getEnCode());
        }
    }

    /**
     * 附件列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = $this->app->request->get(['page' => 1, 'limit' => 10, 'type_id' => 0, 'user_id' => 0, 'like_user_str' => '']);
        if ($params['like_user_str']) {
            $res = SysUser::mk()->auth()->limit(10)->where([
                ['nickname|username', 'like', '%' . $params['like_user_str'] . '%'],
            ])->field('id,nickname,username')->_list();
            _result([
                'code' => 200,
                'msg' => 'success',
                'data' => $res
            ], _getEnCode());
        } else {
            [$types, $wheres] = TypesService::instance()->getTypesAndWheres(4, (int)$params['type_id']);
            $data = $this->model->auth($params['user_id'])->with([
                'type',
                'user'
            ])->where($wheres)->order('id desc')->_page($params);
            _result([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'fields' => AuthService::instance()->fields('sys_file'),
                    'types' => $types,
                    'total' => $data['total'],
                    'data' => $data['data']
                ]
            ], _getEnCode());
        }
    }
}