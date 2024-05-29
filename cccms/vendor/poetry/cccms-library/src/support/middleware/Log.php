<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\{Config, Request, Response};
use cccms\services\{NodeService, ConfigService, UserService};

/**
 * 日志记录中间件
 */
class Log
{
    protected array $data = [];

    public function __construct(Config $config)
    {
        // $this->cors = $config->get('cors', []);
        // $this->cookieDomain = $config->get('cookie.domain', '');
    }

    public function handle(Request $request, Closure $next)
    {
        $request->reqKey = static::generate();
        $response = $next($request);
        // 请求类型
        $method = $request->method();
        $node = NodeService::instance()->getCurrentNodeInfo();
        $logs = ConfigService::instance()->getConfig('log', []);
        // 需要监控的请求类型
        $log_methods = array_map('strtolower', $logs['logMethods']);
        // 请求参数 排除掉不需要记录的参数
        $request_param = $request->except($logs['logNoParams']);
        // 不需要登陆的节点不记录 || 不需要监控的请求类型不记录
        if (!$node['login'] || in_array($method, $log_methods)) {
            $this->data = [
                'user_id' => UserService::instance()->getUserInfo('id'),
                'name' => ($node['parentTitle'] ?: '空') . '-' . $node['title'],
                'node' => $node['currentNode'],
                'req_ip' => $request->ip(),
                'req_method' => $method,
                'req_ua' => $request->server('HTTP_USER_AGENT'),
                'secure_key' => $request->secureKey(),
                'req_params' => $request_param,
                'upd_params' => [],
                'req_result' => [],
            ];
        }
        return $response;
    }

    public function end(Response $response)
    {
        // 回调行为
        if (!empty($this->data)) {
            if (get_class($response) === 'think\response\View') {
                $this->data['req_result'] = $response->getVars();
            } elseif (method_exists(Response::class, 'getData')) {
                $this->data['req_result'] = $response->getData();
            }
        }
    }

    /**
     * 生成唯一请求id
     * @return String
     */
    private static function generate()
    {
        // 使用session_create_id()方法创建前缀
        $prefix = session_create_id(date('YmdHis'));
        // 使用uniqid()方法创建唯一id
        return md5(uniqid($prefix, true));
    }
}
