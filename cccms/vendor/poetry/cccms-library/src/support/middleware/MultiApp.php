<?php
declare(strict_types=1);

namespace cccms\support\middleware;

use Closure;
use cccms\services\BaseService;
use think\exception\HttpException;
use think\{App, event\RouteLoaded, Request, Response};

/**
 * 多应用模式支持
 */
class MultiApp
{
    /** @var App */
    protected App $app;

    /**
     * 应用名称
     * @var string
     */
    protected string $name;

    /**
     * 应用名称
     * @var string
     */
    protected string $appName;

    /**
     * 应用路径
     * @var string
     */
    protected string $path;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();
    }

    /**
     * 多应用解析
     * @access public
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->parseMultiApp()) {
            return $next($request);
        }

        return $this->app->middleware->pipeline('app')
            ->send($request)
            ->then(function ($request) use ($next) {
                return $next($request);
            });
    }

    /**
     * 获取路由目录
     * @access protected
     * @return string
     */
    protected function getRoutePath(): string
    {
        return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
    }

    /**
     * 解析多应用
     * @return bool
     */
    protected function parseMultiApp(): bool
    {
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        if ($this->name || (in_array($scriptName, ['router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            $appName = null;
            $this->appName = '';

            $bind = $this->app->config->get('app.domain_bind', []);

            if (!empty($bind)) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain = $this->app->request->host(true);

                if (isset($bind[$domain])) {
                    $appName = $bind[$domain];
                    $this->app->http->setBind();
                } elseif (isset($bind[$subDomain])) {
                    $appName = $bind[$subDomain];
                    $this->app->http->setBind();
                } elseif (isset($bind['*'])) {
                    $appName = $bind['*'];
                    $this->app->http->setBind();
                }
            }

            if (!$this->app->http->isBind()) {
                $path = $this->app->request->pathinfo();
                $map = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $name = current(explode('/', $path));

                if (strpos($name, '.')) {
                    $name = strstr($name, '.', true);
                }

                if (isset($map[$name])) {
                    if ($map[$name] instanceof Closure) {
                        $result = call_user_func_array($map[$name], [$this->app]);
                        $appName = $result ?: $name;
                    } else {
                        $appName = $map[$name];
                    }
                } elseif ($name && (in_array($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    $appName = $map['*'];
                } else {
                    $ds = DIRECTORY_SEPARATOR;
                    $appName = $name ?: $defaultApp;
                    $appPath = $this->path ?: $this->app->getBasePath() . $appName . $ds;
                    $appPathLibrary = $this->path ?: $this->app->getRootPath() . 'vendor' . $ds . 'poetry' . $ds . 'cccms-app' . $ds . 'src' . $ds . $appName . $ds;

                    if (!is_dir($appPath) && !is_dir($appPathLibrary)) {
                        $express = $this->app->config->get('app.app_express', false);
                        if ($express) {
                            $this->setApp($defaultApp);
                            return true;
                        } else {
                            return false;
                        }
                    }
                }

                if ($name) {
                    $this->app->request->setRoot('/' . $name);
                    $this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
                }
            }
        }

        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 获取当前运行入口名称
     * @access protected
     * @codeCoverageIgnore
     * @return string
     */
    protected function getScriptName(): string
    {
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $file = $_SERVER['SCRIPT_FILENAME'];
        } elseif (isset($_SERVER['argv'][0])) {
            $file = realpath($_SERVER['argv'][0]);
        }

        return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
    }

    /**
     * 设置应用
     * @param string $appName
     */
    protected function setApp(string $appName): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->app->getBasePath() . $appName . '/';
        $appPathLibrary = $this->path ?: $this->app->getRootPath() . 'vendor/poetry/cccms-app/src/' . $appName . '/';
        $pathInfo = $this->app->request->pathinfo();
        $route = config('route');
        if (empty($pathInfo)) {
            $pathInfo = $appName . '/' . $route['default_controller'] . '/' . $route['default_action'];
        }
        [, $controller,] = array_pad(explode('/', $pathInfo), 3, 'index');
        if (file_exists($appPathLibrary . 'controller' . '/' . ucfirst($controller) . '.php')) {
            $appPath = $appPathLibrary;
        }
        $this->app->setAppPath($appPath);
        // 设置应用命名空间
        $this->app->setNamespace($this->app->config->get('app.app_namespace') ?: 'app\\' . $appName);
        // 加载公共路由
        $this->setRoute();
        if (is_dir($appPath)) {
            $this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
            $this->app->http->setRoutePath($this->getRoutePath());

            // 加载应用
            $this->loadApp($appPath);
        }
    }

    /**
     * 设置扩展配置文件
     * @return void
     */
    private function setRoute(): void
    {
        $files = BaseService::instance()->scanDirArray($this->app->getRootPath() . 'vendor/poetry/cccms-library/src/cccms/route/*');
        foreach ($files as $file) {
            include $file;
        }
        $this->app->event->trigger(RouteLoaded::class);
    }

    /**
     * 加载应用文件
     * @param string $appPath
     * @return bool
     */
    protected function loadApp(string $appPath): bool
    {
        [$ext, $fMaps] = [$this->app->getConfigExt(), []];
        if (is_file($file = $appPath . 'common' . $ext)) include_once $file;
        foreach (glob($appPath . 'config' . DIRECTORY_SEPARATOR . '*' . $ext) as $file) {
            $this->app->config->load($file, $fMaps[] = pathinfo($file, PATHINFO_FILENAME));
        }
        if (in_array('route', $fMaps) && method_exists($this->app->route, 'reload')) {
            $this->app->route->reload();
        }
        if (is_file($file = $appPath . 'event' . $ext)) {
            $this->app->loadEvent(include $file);
        }
        if (is_file($file = $appPath . 'middleware' . $ext)) {
            $this->app->middleware->import(include $file, 'app');
        }
        if (is_file($file = $appPath . 'provider' . $ext)) {
            $this->app->bind(include $file);
        }
        // 重新加载应用语言包
        if (method_exists($this->app->lang, 'switchLangSet')) {
            $this->app->lang->switchLangSet($this->app->lang->getLangSet());
        }
        return true;
    }
}
