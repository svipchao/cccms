<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\{Config, Request, Response};

/**
 * 跨域请求支持
 */
class Cors
{
    protected array $cors;

    protected mixed $cookieDomain;

    public function __construct(Config $config)
    {
        $this->cors = $config->get('cors', []);
        $this->cookieDomain = $config->get('cookie.domain', '');
    }

    /**
     * 获取Header头
     */
    public function getHeaders(): array
    {
        return [
            'Access-Control-Max-Age' => 1800,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => join(',', $this->cors['cors_host']),
            'Access-Control-Allow-Methods' => $this->cors['cors_methods'],
            'Access-Control-Expose-Headers' => $this->cors['cors_headers'],
            'Access-Control-Allow-Headers' => "Authorization,accessToken,Content-Type,If-Match,If-Modified-Since,If-None-Match,If-Unmodified-Since,X-CSRF-TOKEN,X-Requested-With," . $this->cors['cors_headers'],
        ];
    }

    /**
     * 允许跨域请求
     * @access public
     * @param Request $request
     * @param Closure $next
     * @param array|null $headers
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?array $headers = []): Response
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
