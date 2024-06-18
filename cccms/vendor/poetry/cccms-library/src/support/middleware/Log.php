<?php
declare (strict_types=1);

namespace cccms\support\middleware;

use Closure;
use think\{Request, Response};
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
        $response = $next($request);
        // 请求类型
        $method = $request->method();
        $node = NodeService::getCurrentNodeInfo();
        $logs = ConfigService::getConfig('log', []);
        // 记录日志
        if ($logs['logClose']) {
            // 需要监控的请求类型
            $log_methods = array_map('strtolower', $logs['logMethods']);
            // 请求参数 排除掉不需要记录的参数
            $request_param = $request->except($logs['logNoParams']);
            // 不需要登陆的节点不记录 || 不需要监控的请求类型不记录
            if (in_array($method, $log_methods)) {
                $this->logData = [
                    'user_id' => UserService::isLogin() ? UserService::getUserId() : 0,
                    'name' => ($node['parentTitle'] ?: '空') . '-' . $node['title'],
                    'node' => $node['currentNode'],
                    'req_ip' => $request->ip(),
                    'req_method' => $method,
                    'req_ua' => $request->server('HTTP_USER_AGENT'),
                ];
                $this->logInfoData = [
                    'log_id' => 0,
                    'req_params' => $request_param,
                    'upd_params' => [],
                    'req_result' => [],
                ];
            }
        }
        return $response;
    }

    public function end(Response $response): void
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
}
