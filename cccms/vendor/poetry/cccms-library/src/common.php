<?php
declare(strict_types=1);

use think\response\View;
use think\{Response, Validate};
use cccms\services\InitService;

if (!function_exists('_getEnCode')) {
    /**
     * 获取返回编码类型 (json,jsonp,xml)
     * @param string $enCode 默认值
     * @return string
     */
    function _getEnCode(string $enCode = 'view'): string
    {
        return strtolower(request()->param('encode/s', $enCode));
    }
}

if (!function_exists('_xss_safe')) {
    /**
     * 文本内容XSS过滤
     * @param string $text
     * @return string
     */
    function _xss_safe(string $text): string
    {
        // 将所有 onXxx= 中的字母 o 替换为符号 ο，注意它不是字母
        $rules = ['#<script.*?<\/script>#is' => '', '#(\s)on(\w+=\S)#i' => '$1οn$2'];
        // trim 会去除\n
        return preg_replace(array_keys($rules), array_values($rules), $text);
        // return preg_replace(array_keys($rules), array_values($rules), trim($text));
    }
}

if (!function_exists('_validate')) {
    /**
     * @param string $requestInfo 请求信息
     *    格式：请求类型.表名(可选).是否包含表字段(可选)
     *    例如：post.sys_user 或 post
     *    备注：默认不包含表内所有字段
     * @param array|string|null $filterParams
     *    格式：id|page,limit
     *              (必选参数|可选参数)
     *         id,?page,?limit
     *              (必选参数,?可选参数,?可选参数)
     *         ['key1' => 'value1', 'key2' => 'value2']
     *              (键名前存在 ? 则为可选参数)
     *    备注：可选参数在前面加英文问号?
     * @param array $rules 验证规则及其错误提示
     *    格式：[
     *        'user|用户名' => 'length:1,3',
     *        'user.length' => '用户名长度不正确'
     *    ]
     *    备注：与ThinkPHP官方验证器写法一致
     * @return array
     */
    function _validate(string $requestInfo = 'post', array|string $filterParams = null, array $rules = []): array
    {
        [$method, $tableName, $isTableFields] = array_pad(explode('.', $requestInfo), 3, '');

        [$params, $validateRule] = [[], []];
        if (!empty($filterParams)) {
            if (is_string($filterParams)) {
                if (str_contains($filterParams, '|')) {
                    $filterParams = explode('|', $filterParams);
                    $filterParams = array_merge(explode(',', $filterParams[0]), array_map(function ($item) {
                        return $item ? ('?' . $item) : '';
                    }, explode(',', $filterParams[1])));
                } else {
                    $filterParams = explode(',', $filterParams);
                }
                $filterParams = array_fill_keys(array_filter($filterParams), 0);
            }
            [$requireParams, $optionalParams] = [[], []];
            foreach ($filterParams as $filterParamKey => $filterParamVal) {
                if (str_contains($filterParamKey, '?')) {
                    $optionalParams[ltrim($filterParamKey, '?')] = $filterParamVal;
                } else {
                    $requireParams[$filterParamKey] = $filterParamVal;
                }
            }
            if (!empty($tableName)) {
                // 合并表验证信息
                $rules = array_merge(config('validate_' . $tableName, []), $rules);
                $isTableFields = $isTableFields === 'true';
                foreach ($rules as $rK => $rV) {
                    if (str_contains($rK, '.')) {
                        [$field, $fieldRule] = array_pad(explode('.', $rK), 2, '');
                        $validateRule[$field]['message'][$fieldRule] = $rV;
                    } else {
                        [$field, $fieldRule] = array_pad(explode('|', $rK), 2, '');
                        $validateRule[$field]['field_name'] = $fieldRule ?: $field;
                        // 必选参数添加 require
                        $rule = array_flip(explode('|', $rV));
                        if (isset($requireParams[$field])) {
                            $rule['require'] = '';
                            $isTableFields && $requireParams[$field] = 0;
                        } else {
                            unset($rule['require']);
                            // 可选参数不设置默认值 如不传值则不获取
                            $isTableFields && $optionalParams[$field] = 0;
                        }
                        $validateRule[$field]['rule'] = array_keys($rule);
                    }
                }
                $tableFields = InitService::instance()->getTables($tableName);
                foreach ($tableFields as $fieldKey => $fieldVal) {
                    if (isset($requireParams[$fieldKey])) {
                        $isTableFields && $requireParams[$fieldKey] = 0;
                    } else {
                        // 可选参数不设置默认值 如不传值则不获取
                        $isTableFields && $optionalParams[$fieldKey] = 0;
                    }
                }
            }
            $params = array_merge($requireParams, $optionalParams);
        }
        if (is_string($method) && method_exists(request(), $method)) {
            $params = array_intersect_key(request()->$method(), $params);
            $params = array_merge($requireParams ?? [], $params);
        }
        if (empty($params)) {
            _result(['code' => 412, 'msg' => '需要验证的数据为空'], _getEnCode());
        }
        // 覆盖数据库验证规则及其提示信息
        [$rules, $message] = [[], []];
        foreach ($validateRule as $key => $val) {
            $rules[$key . '|' . $val['field_name']] = join('|', $val['rule']);
            foreach ($val['message'] as $mK => $mV) {
                $message[$key . '.' . $mK] = str_replace('%s', $val['field_name'], $mV);
            }
        }
        [$rules, $message] = [array_filter($rules), array_filter($message)];
        $validate = new Validate;
        if (!$validate->rule($rules)->message($message)->check($params)) {
            _result(['code' => 412, 'msg' => $validate->getError()], _getEnCode());
        }
        return $params;
    }
}

if (!function_exists('_logUpdateParams')) {
    /**
     * 记录日志修改参数信息
     * @param array $newData 新数据
     * @param array $oldData 旧数据
     * @return NULL
     */
    function _logUpdateParams(array $newData = [], array $oldData = [])
    {
        request()->updResult = array_diff_assoc($newData, $oldData);
    }
}

if (!function_exists('_result')) {
    /**
     * 返回数据
     * @param array $data 参数
     * @param string $type 输出类型(view,json,jsonp,xml)
     * @param array $header 设置响应的头信息
     * @param array $options 输出参数 \think\response\ 下的options输出参数配置
     * @return Response
     */
    function _result(array $data = [], string $type = 'json', array $header = [], array $options = []): Response
    {
        // OPTIONS请求时全部返回200状态码
        $code = (int)($data['code'] ?? 200);
        // $code = request()->method() == 'OPTIONS' ? 200 : (int)($data['code'] ?? 200);
        if (!in_array(strtolower($type), ['json', 'jsonp', 'xml'])) $type = 'json';
        $response = Response::create($data, $type, $code)->header($header)->options($options);
        throw new \think\exception\HttpResponseException($response);
    }
}

if (!function_exists('_view')) {
    /**
     * 渲染模板输出
     * @param string $template 模板文件
     * @param array $vars 模板变量
     * @param int $code 状态码
     * @param callable $filter 内容过滤
     * @return \think\response\View
     */
    function _view(string $template = '', $vars = [], $code = 200, $filter = null): View
    {
        if (empty($template)) {
            $template = config('cccms.resultPath');
        } else {
            $template = '../app/' . strtolower(app('http')->getName()) . '/view/' . $template . '.html';
        }
        return Response::create($template, 'view', $code)->assign($vars)->filter($filter);
    }
}

if (!function_exists('_display')) {
    /**
     * 渲染模板输出
     * @param string $content 渲染内容
     * @param array $vars 模板变量
     * @param int $code 状态码
     * @param callable $filter 内容过滤
     * @return \think\response\View
     */
    function _display(string $content, $vars = [], $code = 200, $filter = null): View
    {
        return Response::create($content, 'view', $code)->isContent(true)->assign($vars)->filter($filter);
    }
}
