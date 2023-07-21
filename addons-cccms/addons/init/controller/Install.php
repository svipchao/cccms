<?php

namespace addons\init\controller;

use cccms\Base;
use PDO;
use think\facade\Db;

class Install extends Base
{
    public function index()
    {
        try {
            // 尝试连接 没有报错 则已经配置过数据库配置
            Db::query('SELECT 1');
            if (!empty(Db::query('show tables like "sys_config"'))) {
                _result(['code' => 200, 'msg' => '已安装'], 'json');
            }
            _result(['code' => 202, 'msg' => '已安装'], 'json');
        } catch (\PDOException $e) {
            _result([], 'index');
        }
    }

    public function doInstall()
    {
        $param = $this->app->request->param();
        if ($param['password'] !== $param['password1']) {
            _result(['code' => 200, 'msg' => '两次密码不一致'], 'json');
        }
        try {
            $param['mysqlHostPort'] = $param['mysqlHostPort'] ?: 3306;
            $dsn = config('database.default') . ":host={$param['mysqlHostName']};port={$param['mysqlHostPort']};dbname={$param['mysqlDatabase']}";
            $pdo = new PDO($dsn, $param['mysqlUserName'], $param['mysqlPassWord']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = file_get_contents(__DIR__ . '/../data/install.sql');
            $pdo->query($sql);

            // 将数据库配置写入配置文件
            $sqlInfo = [
                // 自动写入时间戳字段
                // true为自动识别类型 false关闭
                // 字符串则明确指定时间字段类型 支持 int timestamp datetime date
                'auto_timestamp' => true,

                // 时间字段取出后的默认时间格式
                'datetime_format' => false,

                'connections' => [
                    'mysql' => [
                        // 数据库类型
                        'type' => 'mysql',
                        // 服务器地址
                        'hostname' => $param['mysqlHostName'],
                        // 数据库名
                        'database' => $param['mysqlDatabase'],
                        // 用户名
                        'username' => $param['mysqlUserName'],
                        // 密码
                        'password' => $param['mysqlPassWord'],
                        // 端口
                        'hostport' => $param['mysqlHostPort'],
                    ]
                ]
            ];
            // 写入配置文件
            file_put_contents($this->app->getRootPath() . 'cccms/config/database.php', '<?php' . PHP_EOL . 'return ' . var_export($sqlInfo, true) . ';');

            _result(['code' => 200, 'msg' => '初始化成功'], 'json');
        } catch (\PDOException $e) {
            _result(['code' => 202, 'msg' => $e->getMessage()], 'json');
        }
    }
}