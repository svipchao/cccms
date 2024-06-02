<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\facade\Event;
use think\{Config, Request, Response};
use cccms\extend\StrExtend;
use cccms\model\{SysLog, SysLogInfo};
use cccms\services\{NodeService, ConfigService, UserService};

/**
 * 日志记录中间件
 */
class Log
{
    protected array $logData = [];

    protected array $logInfoData = [];

    public function handle(Request $request, Closure $next)
    {
        $request->reqKey = static::generate();
        $response = $next($request);
        // 请求类型
        $method = $request->method();
        $node = NodeService::getCurrentNodeInfo(static::getCurrentNode());
        $logs = ConfigService::getConfig('log', []);
        // 需要监控的请求类型
        $log_methods = array_map('strtolower', $logs['logMethods']);
        // 请求参数 排除掉不需要记录的参数
        $request_param = $request->except($logs['logNoParams']);
        // 不需要登陆的节点不记录 || 不需要监控的请求类型不记录
        if (!in_array($method, $log_methods)) {
            $this->logData = [
                'user_id' => UserService::isLogin() ? UserService::getUserId() : 0,
                'name' => ($node['parentTitle'] ?: '空') . '-' . $node['title'],
                'node' => $node['currentNode'],
                'req_ip' => $request->ip(),
                'req_method' => $method,
                'req_ua' => $request->server('HTTP_USER_AGENT'),
                'req_key' => $request->reqKey, // 修改数据可以使用此字段更新
            ];
            $this->logInfoData = [
                'log_id' => 0,
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
        if (!empty($this->logData) && $this->logInfoData['log_id'] == 0) {
            if (get_class($response) === 'think\response\View') {
                $this->logInfoData['req_result'] = $response->getVars();
            } elseif (method_exists(Response::class, 'getData')) {
                $this->logInfoData['req_result'] = $response->getData();
            }
            // 记录修改信息
            // _logUpdateParams(
            //     ["a" => "red", "b" => "green", "c" => "blue", "d" => "yellow"],
            //     ["a" => "red", "b" => "green", "c" => "blue1", "d" => "blue", "e" => "blue"]
            // );
            if (!empty(request()->updResult)) {
                $this->logInfoData['upd_params'] = request()->updResult;
            }
            $this->logInfoData['log_id'] = SysLog::mk()->insertGetId($this->logData);
            SysLogInfo::mk()->save($this->logInfoData);
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

    /**
     * 获取当前节点
     * @return string
     */
    private static function getCurrentNode()
    {
        return StrExtend::humpToUnderline(app('http')->getName() . '/' . str_replace('.', '/', request()->controller()) . '/' . request()->action());
    }
}
