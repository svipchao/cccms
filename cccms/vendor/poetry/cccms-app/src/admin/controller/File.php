<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\{Base, Storage};
use cccms\model\{SysFile, SysFileCate};
use cccms\services\{AuthService, UploadService};

/**
 * 附件管理
 * @sort 991
 */
class File extends Base
{
    public function init(): void
    {
        $this->model = SysFile::mk();
    }

    /**
     * 添加附件
     * @auth true
     * @login true
     * @encode json
     * @methods POST
     */
    public function create(): void
    {
        $cate_id = $this->request->post('cate_id/d', 0);
        _result([
            'code' => 200,
            'msg' => '添加成功',
            'data' => UploadService::instance()->upload($cate_id)
        ], _getEnCode());
    }

    /**
     * 删除附件
     * @auth true
     * @login true
     * @encode json
     * @methods DELETE
     */
    public function delete(): void
    {
        Storage::instance()->delete($this->request->delete('id/d', 0));
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 修改附件
     * @auth true
     * @login true
     * @encode json
     * @methods PUT
     */
    public function update(): void
    {
        $params = _validate('put.sys_file.false', 'id|file_name,file_desc,extract_code,status');
        $this->model->update($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 附件列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $cate = SysFileCate::mk()->_list();
        $params = _validate('get.sys_file.true', 'page,limit|cate_id');
        $params['cate_id'] = $params['cate_id'] ?: ($cate[0]['id'] ?? 0);
        $data = $this->model->with(['cate', 'user'])->_withSearch('cate_id', [
            'cate_id' => $params['cate_id'],
        ])->order('id desc')->_page($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_file'),
            'total' => $data['total'],
            'data' => $data['data'],
            'cate' => $cate,
        ]], _getEnCode());
    }
}