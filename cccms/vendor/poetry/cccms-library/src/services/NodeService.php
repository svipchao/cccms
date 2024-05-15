<?php

declare(strict_types=1);

namespace cccms\services;

use ReflectionClass;
use ReflectionMethod;
use cccms\Service;
use cccms\extend\{StrExtend, ArrExtend};

class NodeService extends Service
{
    /**
     * 所有框架父级节点 应用节点、类节点
     * @return array
     */
    public function getFrameNodes(): array
    {
        $data = $this->app->cache->get('SysFrameNodes') ?? [];
        if (empty($data)) {
            $data = $this->getNodesInfo();
            foreach ($data as $key => $val) {
                if (isset($val['encode'], $val['methods'])) {
                    unset($data[$key]);
                }
            }
            $this->app->cache->set('SysFrameNodes', $data);
        }
        return $data;
    }

    /**
     * 合并框架节点
     * @param array $nodes
     * @return array
     */
    public function setFrameNodes(array $nodes): array
    {
        $nodes = array_merge($this->getFrameNodes(), array_intersect_key($this->getNodesInfo(), array_flip($nodes)));
        $tree = ArrExtend::toTreeArray($nodes, 'currentNode', 'parentNode');
        $call = function (callable $call, array $tree = [], array &$data = []) {
            foreach ($tree as $val) {
                if (isset($val['children'])) {
                    $children = $val['children'];
                    unset($val['children']);
                    $data[$val['currentNode']] = $val;
                    $call($call, $children, $data);
                } elseif (isset($val['encode'], $val['methods'])) {
                    $data[$val['currentNode']] = $val;
                }
            }
            return $data;
        };
        $data = $call($call, $tree);
        // 去除没有子节点的框架节点
        $parentNodes = array_column($data, 'parentNode');
        foreach ($data as $key => $val) {
            if ($val['parentNode'] == '#' && !in_array($val['currentNode'], $parentNodes)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /**
     * 获取所有节点
     * @return array
     */
    public function getNodes(): array
    {
        $data = $this->app->cache->get('SysNodes', []);
        if (empty($data)) {
            $data = array_keys($this->getNodesInfo());
            $this->app->cache->set('SysNodes', $data);
        }
        return $data;
    }

    /**
     * 获取所有授权的节点
     * @return array
     */
    public function getAuthNodes(): array
    {
        $data = $this->app->cache->get('SysAuthNodes', []);
        if (empty($data)) {
            $data = [];
            $nodes = $this->getNodesInfo();
            foreach ($nodes as $node) {
                if ($node['auth'] ?? false) $data[] = $node['currentNode'];
            }
            $data = $this->setFrameNodes($data);
            $this->app->cache->set('SysAuthNodes', $data);
        }
        return $data;
    }

    /**
     * 获取所有授权的节点
     * @return array
     */
    public function getAuthNodesTree(): array
    {
        $data = $this->app->cache->get('SysAuthNodesTree', []);
        if (empty($data)) {
            $data = ArrExtend::toTreeArray($this->getAuthNodes(), 'currentNode', 'parentNode');
            $this->app->cache->set('SysAuthNodesTree', $data);
        }
        return $data;
    }

    /**
     * 获取当前节点
     * @return string
     */
    public function getCurrentNode()
    {
        return StrExtend::humpToUnderline(app('http')->getName() . '/' . str_replace('.', '/', request()->controller()) . '/' . request()->action());
    }

    /**
     * 获取节点信息
     * @param string $node 权限节点(键)
     * @return array
     */
    public function getCurrentNodeInfo(string $node = '')
    {
        $node = $node ?: self::getCurrentNode();
        return $this->getNodesInfo()[$node] ?? [];
    }

    /**
     * 获取所有控制器方法
     * @param array $toScanFileArray 待扫描文件数组
     * @param bool $isCache
     * @return array
     */
    public function getNodesInfo(array $toScanFileArray = [], bool $isCache = false): array
    {
        $data = $this->app->cache->get('SysNodesInfo', []);
        if ($isCache || empty($data)) {
            // 访问控制器层名称
            $controller_layer = $this->app->config->get('route.controller_layer');
            if (empty($toScanFileArray)) {
                $rootPath = $this->app->getRootPath();
                // 这里扫描文件路径需要独立出来 有可能会有其他应用扩展
                $toScanFileArray = array_merge(
                    BaseService::instance()->scanDirArray($rootPath . 'vendor/poetry/cccms-app/src/*/' . $controller_layer . '/*'),
                    BaseService::instance()->scanDirArray($rootPath . 'app/*/' . $controller_layer . '/*')
                );
            }
            $appNames = config('cccms.appName');
            $data = [];
            // 排除内置方法，禁止访问内置方法
            $ignores = get_class_methods('\cccms\Base');
            foreach ($toScanFileArray as $val) {
                if (!preg_match("/(\w+)[\/\\\\](\w+)[\/\\\\]controller[\/\\\\](.*)\.php/i", $val, $matches)) continue;
                [, , $appName, $className] = $matches;
                // 添加应用
                $title = $appNames[$appName] ?? $appName;
                $data[$appName] = ['title' => $title, 'sort' => 0, 'currentNode' => $appName, 'parentNode' => '#', 'parentTitle' => '#'];
                // 默认命名空间
                $namespace = $this->app->config->get('app.app_namespace') ?: 'app';
                // 类全名
                $classFullName = $namespace . '\\' . $appName . '\\controller\\' . strtr($className, '/', '\\');
                if (!class_exists($classFullName)) continue;
                $reflect = new ReflectionClass($classFullName);
                // 判断是否继承基础类库 没有继承 跳出循环 || 如果没有注释 跳出循环
                if (($reflect->getParentClass()->name ?? '') !== 'cccms\Base' || $reflect->getDocComment() === false) continue;
                // 前缀 类的命名空间
                $prefix = StrExtend::humpToUnderline(strtr($appName . '/' . $className, ['\\' => '/', '.' => '/']));
                // 赋值类节点 方便处理Tree
                $comment = $this->parseComment($reflect->getDocComment(), $className);
                $data[$prefix] = array_merge($comment, [
                    'currentNode' => $prefix,
                    'currentPath' => $title . '-' . ($data[$appName]['title'] ?? '#') . '-' . $comment['title'],
                    'parentNode' => $data[$appName]['currentNode'] ?? '#',
                    'parentTitle' => $data[$appName]['title'] ?? '#',
                ]);
                unset($data[$prefix]['auth'], $data[$prefix]['login'], $data[$prefix]['encode'], $data[$prefix]['methods']);
                $reflectionMethod = $reflect->getMethods(ReflectionMethod::IS_PUBLIC);
                foreach ($reflectionMethod as $method) {
                    // 忽略的方法 || 没有注释 跳出循环
                    if (in_array($metName = StrExtend::humpToUnderline($method->getName()), $ignores) || $method->getDocComment() === false) continue;
                    // 赋值类节点 方便处理Tree
                    $comment = $this->parseComment($method->getDocComment(), $metName, $method->getName());
                    $data[$prefix . '/' . $metName] = array_merge($comment, [
                        'currentNode' => $prefix . '/' . $metName,
                        'currentPath' => $title . '-' . ($data[$prefix]['title'] ?? '#') . '-' . $comment['title'],
                        'parentNode' => $prefix,
                        'parentTitle' => $data[$prefix]['title'],
                    ]);
                }
            }
            $data = array_change_key_case($data);
            $data = ArrExtend::toSort($data, 'sort');
            $this->app->cache->set('SysNodesInfo', $data);
        }
        return $data;
    }

    /**
     * 解析硬节点属性
     * @param string $comment 备注内容
     * @param string $default 默认标题
     * @return array
     */
    private function parseComment(string $comment, string $defaultTitle = '', $node = ''): array
    {
        $text = strtolower(strtr($comment, "\n", ' '));
        $title = preg_replace('/^\/\*\s*\*\s*\*\s*(.*?)\s*\*.*?$/', '$1', $text);
        foreach (['@auth', '@login', '@methods'] as $find) {
            if (stripos($title, $find) === 0) $title = $default;
        }
        preg_match('/@encode.(\S+)/i', $text, $enCode);
        preg_match('/@sort.(\S+)/i', $text, $sort);
        preg_match('/@methods.(\S+)/i', $text, $methods);
        // 请求返回编码 view|json|jsonp|xml
        // 请求类型详细解释请看 https://www.kancloud.cn/manual/thinkphp6_0/1037520
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        return [
            'title' => $title ?: $default,
            'sort' => $sort[1] ?? strpos($letters, substr($node, 0, 1)),
            'auth' => (bool)intval(preg_match('/@auth\s*true/i', $text)),
            'login' => (bool)intval(preg_match('/@login\s*true/i', $text)),
            'encode' => isset($enCode[1]) ? explode('|', $enCode[1]) : [],
            'methods' => isset($methods[1]) ? explode('|', strtoupper($methods[1])) : [],
        ];
    }
}
