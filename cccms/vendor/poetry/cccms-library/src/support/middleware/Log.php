<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\{Config, Request, Response};

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

    public function handle($request, \Closure $next)
    {
        dump(1111);
        $response = $next($request);

        // 添加中间件执行代码

        dump($request->secureKey());
        return $response;
    }

    public function end(\think\Response $response)
    {
        // 回调行为
        // dump($response->getContent());
    }

    /**
     * 允许跨域请求
     * @access public
     * @param Request $request
     * @param Closure $next
     * @param array|null $headers
     * @return Response
     */
    public function handle1(Request $request, Closure $next, ?array $headers = []): Response
    {
        $headers = !empty($headers) ? array_merge($this->getHeaders(), $headers) : $this->getHeaders();

        if ($this->cors['cors_auto'] || empty($headers['Access-Control-Allow-Origin'])) {
            $origin = $request->header('origin');

            if ($origin && ('' == $this->cookieDomain || strpos($origin, $this->cookieDomain))) {
                $headers['Access-Control-Allow-Origin'] = $origin;
            } else {
                $headers['Access-Control-Allow-Origin'] = '*';
            }
        }

        return $next($request)->header($headers);
    }
}
