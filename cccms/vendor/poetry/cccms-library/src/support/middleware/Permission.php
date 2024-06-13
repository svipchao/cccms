<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\Request;
use cccms\services\{NodeService, UserService};

/**
 * 权限验证中间件
 */
class Permission
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // 请求类型
        $node = NodeService::getCurrentNodeInfo();
        if (empty($node)) {
            _result(['code' => 404, 'msg' => '页面不存在']);
        } else {
            // 判断访问方式是否符合注解
            if (!in_array($request->method(), $node['methods'])) {
                _result(['code' => 405, 'msg' => '客户端请求中的方法被禁止']);
            }
            // 判断返回编码是否符合注解
            if (!in_array(_getEnCode(), $node['encode'])) {
                _result(['code' => 405, 'msg' => '禁止此编码类型']);
            }
            // 检测是否需要验证登录
            if ($node['login']) {
                // 判断是否登陆
                if (!UserService::isLogin()) {
                    _result(['code' => 401, 'msg' => '请登陆']);
                }
                // 判断是否需要验证权限 检查用户是否拥有权限
                if ($node['auth'] && !UserService::isAuth()) {
                    _result(['code' => 403, 'msg' => '权限不足，请申请【' . $node['currentPath'] . '】节点权限！']);
                }
            }
        }
        return $response;
    }
}
