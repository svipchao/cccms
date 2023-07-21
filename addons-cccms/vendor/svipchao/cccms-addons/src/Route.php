<?php
declare(strict_types=1);

namespace cccms\addons;

use think\facade\{Db, Event};

class Route
{
    /**
     * 插件路由请求
     * @param null $addon
     * @param null $controller
     * @param null $action
     * @return mixed
     */
    public static function execute($addon = null, $controller = null, $action = null)
    {
        $app = app();
        $request = $app->request;

        Event::trigger('addons_begin', $request);

        // 控制器及其方法使用配置文件默认值
        $controller = $controller ?: config('route.default_controller');
        $action = $action ?: config('route.default_action');

        if (empty($addon)) {
            _result(['code' => 404, 'msg' => '插件不存在'], _getEncode());
        }

        // 设置当前请求的控制器、操作
        $request->setController($controller)->setAction($action);

        // 过滤内置插件
        if (!in_array(strtolower($addon), ['init', 'route'])) {
            // 获取插件基础信息
            $info = Db::table('sys_addons')->where(['name' => $addon])->findOrEmpty();
            if (empty($info)) {
                _result(['code' => 404, 'msg' => '插件不存在'], _getEncode());
            } elseif ($info['status'] === 0) {
                _result(['code' => 404, 'msg' => '插件已禁用'], _getEncode());
            }
        }

        // 监听addon_module_init
        Event::trigger('addon_module_init', $request);

        $class = get_addons_class((string)$addon, 'controller', $controller);
        if (!$class) {
            _result(['code' => 404, 'msg' => '控制器不存在'], _getEncode());
        }

        // 生成控制器对象
        $instance = new $class($app);
        $call = $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
        } elseif (is_callable([$instance, '_empty'])) {
            // 空方法
            $call = [$instance, '_empty'];
            $vars = [$action];
        } else {
            _result(['code' => 404, 'msg' => '方法不存在'], _getEncode());
        }

        Event::trigger('addons_action_begin', $call);

        return call_user_func_array($call, $vars);
    }
}