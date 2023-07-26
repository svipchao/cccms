<?php
declare(strict_types=1);

namespace cccms\extend;

use Exception;
use cccms\extend\ip2Region\XdbSearcher;
use think\Container;

/**
 * class Ip2Region
 * 为兼容老版本调度而创建
 * @author Anyon<zoujingli@qq.com>
 * @datetime 2022/07/18
 */
class Ip2RegionExtend
{
    /**
     * 查询实例对象
     * @var XdbSearcher
     */
    private XdbSearcher $searcher;

    /**
     * 初始化构造方法
     * @throws Exception
     */
    public function __construct()
    {
        $this->searcher = XdbSearcher::newWithFileOnly(__DIR__ . '/ip2Region/ip2region.xdb');
    }

    /**
     * 静态实例对象
     * @param array $var 实例参数
     * @param boolean $new 创建新实例
     * @return static
     */
    public static function mk(array $var = [], bool $new = false): static
    {
        return Container::getInstance()->make(static::class, $var, $new);
    }

    /**
     * 兼容原 memorySearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function memorySearch(string $ip): array
    {
        return ['city_id' => 0, 'region' => $this->searcher->search($ip)];
    }

    /**
     * 兼容原 binarySearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function binarySearch(string $ip): array
    {
        return $this->memorySearch($ip);
    }

    /**
     * 兼容原 btreeSearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function btreeSearch(string $ip): array
    {
        return $this->memorySearch($ip);
    }

    /**
     * 直接查询并返回名称
     * @param string $ip
     * @param string $type
     * @return string
     * @throws Exception
     */
    public function simple(string $ip, string $type = ''): string
    {
        $geo = $this->memorySearch($ip);
        // 国家|区域|省份|城市|ISP
        $types = ['country', 'area', 'province', 'city', 'isp'];
        $data = array_combine($types, explode('|', $geo['region']));
        $data = array_filter(array_unique($data));
        return $data[$type] ?? join('', $data);
    }

    /**
     * destruct method
     * resource destroy
     */
    public function __destruct()
    {
        $this->searcher->close();
        unset($this->searcher);
    }
}