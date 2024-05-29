<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\{Config, Request, Response};
use cccms\services\ConfigService;

/**
 * 日志记录中间件
 */
class Log
{
    protected array $cors;

    protected mixed $cookieDomain;

    public function __construct(Config $config)
    {
        // $this->cors = $config->get('cors', []);
        // $this->cookieDomain = $config->get('cookie.domain', '');
    }

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // 添加中间件执行代码
        // 请求类型
        $method = $request->method();
        $logs = ConfigService::instance()->getConfig('log', []);
        // 需要监控的请求类型
        $log_methods = array_map('strtolower', $logs['logMethods']);
        halt($request->method());

        dump($request->secureKey());
        return $response;
    }

    public function end(Response $response)
    {
        // 回调行为
        dump($response);
        die;
        dump($response->getData());
        dump($response->getVars());
        // dump($response->getContent());
    }
}
