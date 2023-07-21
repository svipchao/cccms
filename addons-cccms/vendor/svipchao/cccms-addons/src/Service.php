<?php
declare(strict_types=1);

namespace cccms\addons;

use think\Route;
use think\facade\{Config, Cache, Event};

/**
 * 插件服务
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    public function register()
    {
        // 加载插件事件
        $this->loadEvent();
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }

    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 注册控制器路由 路由脚本
            $route->rule("addons/:addon/[:controller]/[:action]", '\\cccms\\addons\\Route::execute');
            // 自定义路由 这里采用路由插件实现
            // https://github.com/zz-studio/think-addons/blob/master/src/addons/Service.php line 53
        });
    }

    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array)Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array)$values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        // 如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }
}
