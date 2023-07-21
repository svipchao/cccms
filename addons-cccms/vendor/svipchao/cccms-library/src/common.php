<?php

// 获取 系统时间戳
function _time(string $format = '')
{
    return $format ? date($format, $_SERVER['REQUEST_TIME']) : $_SERVER['REQUEST_TIME'];
}

if (!function_exists('_getAccessToken')) {
    /**
     * 获取 accessToken 值 优先级
     * 注意：GET传进来需要进行 urldecode (PHP)/encodeURIComponent(JS)加密
     */
    function _getAccessToken()
    {
        return request()->param('accessToken') ?? session('accessToken');
    }
}

if (!function_exists('_getNode')) {
    // 获取当前节点
    function _getNode(): string
    {
        return strtolower(app('http')->getName() . '/' . request()->controller() . '/' . request()->action());
    }
}

if (!function_exists('_getEnCode')) {
    /**
     * 获取返回编码类型 (view,json,jsonp,xml)
     * PS:  第一个为默认编码类型
     *      view 类型请自行阅读 common.php->_result()
     *      前后端分离开发模式不需要用到 view
     * @param string $enCode 默认值
     * @return string
     */
    function _getEnCode(string $enCode = 'view'): string
    {
        return strtolower(request()->param('encode/s', $enCode));
    }
}

use think\Validate;

if (!function_exists('_validate')) {
    /**
     * @param array $param 需要校验的参数
     * @param string $tableAndFields
     *    格式：认证的表名|表字段1,表字段2...|必须存在的参数(可为空)
     *    例如：sys_user|nickname,username,password|username,password
     * @param array $rule 校验的规则 与ThinkPHP官方验证器写法一样
     * @param array $message 校验的提示信息 与ThinkPHP官方验证器写法一样
     * @param string $enCode 返回编码
     * @return array
     */
    function _validate(array $param, string $tableAndFields = '', array $rule = [], array $message = [], string $enCode = 'json'): array
    {
        $validate = new Validate();
        if (!empty($tableAndFields)) {
            $tableAndFields = explode('|', $tableAndFields);
            // 获取必须存在的参数
            $requireParam = explode(',', $tableAndFields[2]) ?? [];
            // 判断必须存在的数据是否存在
            if (empty(array_intersect_key($param, array_flip($requireParam)))) {
                _result(['code' => 412, 'msg' => '必须存在参数：' . join(',', $requireParam)], _getEnCode($enCode)); // 先决条件错误
            }
            // 表名
            $tableName = $tableAndFields[0];
            // 需要验证的字段
            $fields = array_flip(explode(',', $tableAndFields[1]));
            // 获取系统生成的验证规则
            $validateConfig = config('validate.' . $tableName);
            // 销毁额外数据
            $param = array_intersect_key($param, $fields);
            // 取出验证规则键
            $ruleField = array_intersect_key($validateConfig['field'], array_keys($param));
            // 取出验证规则
            $rule = array_merge(array_intersect_key($validateConfig['rule'], array_flip($ruleField)), $rule);
        }
        if (!$validate->rule($rule)->message($message)->check($param)) {
            _result(['code' => 412, 'msg' => $validate->getError()], _getEnCode($enCode)); // 先决条件错误
        }
        return $param;
    }
}

use think\Response;

if (!function_exists('_result')) {
    /**
     * 返回数据
     * @param array $data 参数
     * @param string $type 输出类型(view,json,jsonp,xml)
     * @param array $header 设置响应的头信息
     */
    function _result(array $data = [], string $type = '', array $header = []): Response
    {
        $data = array_merge([
            'msg' => $data['msg'] ?? 'success',
            'code' => $data['code'] ?? 200,
            'data' => $data['data'] ?? [],
            'url' => $data['url'] ?? '/'
        ], $data);
        if (in_array(strtolower($type), ['json', 'jsonp', 'xml'])) {
            $response = Response::create($data, $type, (int)$data['code']);
        } else {
            // 处理视图
            $addonsName = app()->request->param('addon'); // 插件名
            if ($type === 'view') {
                $htmlName = 'result.html'; // 模版文件名
//                $htmlName = config('cccms.view.resultPath'); // 模版文件名
            } elseif (!empty($addonsName)) {
                $ds = DIRECTORY_SEPARATOR; // DIRECTORY_SEPARATOR
                $suffix = '.' . config('view.view_suffix'); // 模板后缀

                // 判断模版目录层级
                if (count(explode('/', $type)) === 1) {
                    $htmlPath = app()->request->controller() . $ds . $type;
                } else {
                    $htmlPath = $type;
                }
                $htmlName = root_path() . 'addons' . $ds . $addonsName . $ds . config('view.view_dir_name') . $ds . $htmlPath . $suffix;
            } else {
                $htmlName = $type; // 模版文件名
            }
            if (empty($htmlName)) {
                $response = Response::create('异常模版不存在', 'html', 404);
            } else {
                $response = Response::create($htmlName, 'view', (int)$data['code'])->assign($data);
            }
        }
        throw new \think\exception\HttpResponseException($response->header($header));
    }
}