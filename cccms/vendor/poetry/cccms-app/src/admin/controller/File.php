<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysFile;
use cccms\{Base, Storage};
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
        Storage::instance()->delete($this->request->delete('id/d', 0));
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
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
        $this->model->update(_validate('put', ['sys_file', 'id', [
            'file_name',
            'file_desc',
            'extract_code',
            'status' => 1,
        ]]));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
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
        $params = _validate('get', ['sys_file', '', [
            'page' => 1,
            'limit' => 15,
            'type_id' => 0,
            'user' => ''
        ]]);
        $data = $this->model->with(['type', 'user'])->_withSearch('type_id,user', [
            'type_id' => $params['type_id'],
            'user' => $params['user']
        ])->order('id desc')->_page($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_file'),
            'types' => TypesService::instance()->getTypes(4),
            'total' => $data['total'],
            'data' => $data['data']
        ]], _getEnCode());
    }
}