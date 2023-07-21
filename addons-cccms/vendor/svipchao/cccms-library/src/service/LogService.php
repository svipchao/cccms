<?php
declare (strict_types=1);

namespace cccms\service;

use cccms\Service;
use cccms\model\SysLog;

class LogService extends Service
{
    /**
     * 记录日志
     */
    public function log($node): bool
    {
        // 请求类型
        $method = $this->app->request->method();
        // 需要监控的请求类型
        $log_methods = strtolower($this->app->config->get('log.log_methods'));
        // 不需要登陆的节点不记录 || 不需要监控的请求类型不记录
        if (!$node['login'] || !in_array(strtolower($method), explode('|', $log_methods))) return true;
        // 请求参数
        $request_param = $this->app->request->except(explode('|', $this->app->config->get('log.log_params')));
        // 隐藏私密信息
        if (isset($request_param['password'])) {
            $request_param['password'] = '********';
        }
        if (isset($request_param['token'])) {
            $request_param['token'] = '********';
        }
        $data = [
            'user_id' => AuthService::instance()->getUserInfo('id'),
            'name' => ($node['parentTitle'] ?: '空') . '-' . $node['title'],
            'node' => $node['currentNode'],
            'request_ip' => $this->app->request->ip(),
            'request_method' => $method,
            'request_param' => $request_param,
            'request_ua' => $this->app->request->server('HTTP_USER_AGENT'),
        ];
        return SysLog::create($data)->isEmpty();
    }
}