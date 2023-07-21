<?php
declare (strict_types=1);

namespace cccms\service;

use cccms\Service;
use cccms\extend\FileExtend;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class NodeService extends Service
{
    // 所有需要授权的节点
    public function getAuthNodes()
    {
        $data = $this->app->cache->get('SysAuthNodes') ?? [];
        if (empty($data)) {
            $data = $this->getNodes();
            foreach ($data as $key => $val) {
                if (!isset($val['auth']) || !$val['auth']) unset($data[$key]);
            }
            $this->app->cache->set('SysAuthNodes', $data);
        }
        return $data;
    }

    // 所有框架父级节点 应用节点、类节点
    public function getFrameNodes()
    {
        $data = $this->app->cache->get('SysFrameNodes') ?? [];
        if (empty($data)) {
            $data = $this->getNodes();
            foreach ($data as $key => $val) {
                if (!isset($val['frame'])) unset($data[$key]);
            }
            $this->app->cache->set('SysFrameNodes', $data);
        }
        return $data;
    }

    /**
     * 动态添加框架节点
     * @param array $nodes
     * @param array $frameNodes
     * @return array
     */
    public function setFrameNodes(array $nodes, array $frameNodes): array
    {
        $parentNode = array_intersect_key($frameNodes, array_column($nodes, 'parentNode', 'parentNode'));
        foreach ($parentNode as $val) {
            if ($val['parentNode'] !== '#') {
                $parentNode = array_merge($parentNode, $this->setFrameNodes($parentNode, $frameNodes));
            }
        }
        return $parentNode;
    }

    /**
     * 获取节点信息
     * @param string $node 节点
     * @return array
     */
    public function getNode(string $node): array
    {
        return $this->getNodes()[$node] ?? [];
    }

    // 待扫描文件数组
    private function getToScanFileArray(): array
    {
        $files = $this->app->cache->get('toScanFileArray') ?? [];
        if (empty($files)) {
            $nodeConfig = $this->app->config->get('node');
            // 忽略的文件 忽略的方法 需要扫描的路径
            [$files, $ignoresFile, $folderPath] = [[], $nodeConfig['ignoresFile'], $nodeConfig['folderPath']];
            foreach ($folderPath as $val) {
                $files = array_merge($files, FileExtend::scanDirArray($this->app->getRootPath() . $val, $ignoresFile));
            }
            // 这里需要处理一次结果
            foreach ($files as &$val) {
                foreach ($val as $k => $v) {
                    if ($k === 'controller') {
                        $val = $v;
                    }
                }
            }
            $this->app->cache->set('toScanFileArray', $files);
        }
        return $files;
    }

    /**
     * 获取所有控制器方法
     *    修复多级控制器目录结构不明显
     *    部分代码摘自 (https://gitee.com/zoujingli/ThinkAdmin | /vendor/zoujingli/think-library/src/service/NodeService.php)
     * @param array $toScanFileArray 待扫描文件数组
     * @param array $parentInfo 父级数组信息
     * @param bool $isCache
     * @return array
     */
    public function getNodes(array $toScanFileArray = [], array $parentInfo = [], bool $isCache = false): array
    {
        $data = $this->app->cache->get('SysNodes') ?? [];
        if ($isCache || empty($data)) {
            $toScanFileArray = $toScanFileArray ?: $this->getToScanFileArray();
            $nodeConfig = $this->app->config->get('node');
            [$folderAlias, $ignoresAction] = [$nodeConfig['folderAlias'], $nodeConfig['ignoresAction']];
            $data = [];
            foreach ($toScanFileArray as $key => $val) {
                if (is_array($val)) {
                    $title = $folderAlias[$key] ?? $key;
                    $currentNode = $prefix = isset($parentInfo['currentNode']) ? $parentInfo['currentNode'] . '/' . $key : $key;
                    $data[$prefix] = [
                        'parentNode' => $parentInfo['currentNode'] ?? '#',
                        'currentNode' => $currentNode,
                        'parentTitle' => $parentInfo['title'] ?? '#',
                        'appName' => $parentInfo['appName'] ?? $key,
                        'title' => $title,
                        'sort' => 1,
                        'frame' => 2,
                    ];
                    $data = array_merge($data, $this->getNodes($val, $data[$prefix], true));
                } else {
                    if (preg_match("/(\w+)\/(\w+)\/controller\/(.*)\.php/i", $val, $matches)) {
                        [, $namespace, $appName, $className] = $matches;
                        if (!class_exists($namespace . '\\' . $appName . '\\controller\\' . strtr($className, '/', '\\'))) continue;
                        try {
                            $reflect = new ReflectionClass($namespace . '\\' . $appName . '\\controller\\' . strtr($className, '/', '\\'));
                            // 判断是否继承基础类库 没有继承 跳出循环 || 如果没有注释 跳出循环
                            if (($reflect->getParentClass()->name ?? '') !== 'cccms\Base' || $reflect->getDocComment() === false) continue;
                            // 前缀 类的命名空间
                            $prefix = strtolower(strtr($appName . '/' . $className, ['\\' => '/', '.' => '/']));
                            // 赋值类节点 方便处理Tree
                            $data[$prefix] = array_merge($this->_parseComment($reflect->getDocComment(), $className), [
                                'parentNode' => $parentInfo['currentNode'],
                                'parentTitle' => $parentInfo['title'],
                                'appName' => $appName,
                                'currentNode' => $prefix,
                                'frame' => 1,
                            ]);
                            $reflectionMethod = $reflect->getMethods(ReflectionMethod::IS_PUBLIC);
                            $noNeed = array_merge($ignoresAction, $reflect->getDefaultProperties()['noNeed']); // 不需要继承的方法
                            foreach ($reflectionMethod as $method) {
                                // 忽略的方法 || 如果没有注释 跳出循环
                                if (in_array($metName = $method->getName(), $noNeed) || $method->getDocComment() === false) continue;
                                // 赋值类节点 方便处理Tree
                                $data[$prefix . '/' . $metName] = array_merge($this->_parseComment($method->getDocComment(), $metName), [
                                    'parentNode' => $prefix,
                                    'parentTitle' => $data[$prefix]['title'],
                                    'appName' => $appName,
                                    'currentNode' => $prefix . '/' . $metName,
                                ]);
                            }
                        } catch (ReflectionException $e) {
                        }
                    }
                }
            }
            $data = array_change_key_case($data, CASE_LOWER);
            $this->app->cache->set('SysNodes', $data);
        }
        return $data;
    }

    /**
     * 解析硬节点属性
     * @param string $comment 备注内容
     * @param string $default 默认标题
     * @return array
     */
    private function _parseComment(string $comment, string $default = ''): array
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
        return [
            'title' => $title ?: $default,
            'sort' => $sort[1] ?? 0,
            'auth' => intval(preg_match('/@auth\s*true/i', $text)),
            'login' => intval(preg_match('/@login\s*true/i', $text)),
            'encode' => isset($enCode[1]) ? explode('|', $enCode[1]) : [],
            'methods' => isset($methods[1]) ? explode('|', strtoupper($methods[1])) : [],
        ];
    }
}