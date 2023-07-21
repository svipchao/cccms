<?php
declare (strict_types=1);

namespace cccms\service;

use cccms\Service;
use think\facade\Db;

class ConfigService extends Service
{
    /**
     * 生成配置文件
     */
    public function createConfigFile()
    {
        $configRes = Db::table('sys_config')->select()->toArray();
        $configs = [];
        // 根据类别分组 由于Thinkphp数据库链式操作group()会出错 这里进行数据重组
        foreach ($configRes as $val) {
            $configs[$val['type']][] = $val;
        }
        // 组合配置文件格式
        foreach ($configs as $key => $val) {
            $items = [];
            foreach ($val as $v1) {
                // 判断是否需要分割
                if (in_array($v1['form_type'], ['switch', 'input'])) {
                    $items[$v1['key']] = in_array($v1['val'], ['0', '1']) ? (bool)$v1['val'] : $v1['val'];
                } else {
                    // 判断是否需要二次分割
                    if (strstr($v1['val'], ',')) {
                        $item = explode('|', $v1['val']);
                        foreach ($item as $v2) {
                            $v2 = explode(',', $v2);
                            $items[$v1['key']][$v2[0]] = $v2[1];
                        }
                    } else {
                        $items[$v1['key']] = explode('|', $v1['val']);
                    }
                }
            }
            // 写入配置文件
            file_put_contents($this->app->getRootPath() . 'cccms/config/' . $key . '.php', '<?php' . PHP_EOL . 'return ' . var_export($items, true) . ';');
        }
    }

    /**
     * 创建验证规则
     */
    public function createValidateRuleFile()
    {
        $tables = Db::getTables();
        $rules = [];
        foreach ($tables as $tableName) {
            $fields = Db::getFields($tableName);
            foreach ($fields as $key => $val) {
                $rule = [];

                // 移除用户自定义注释内容
                $val['comment'] = preg_replace("/(?=【).*?(?<=】)/", '', $val['comment']);

                // 没有字段备注的时候使用字段名
                $val['comment'] = $val['comment'] ?: $val['name'];

                // 判断是否可以为空
                if ($val['notnull']) $rule['require'] = 'require';

                // 判断类型 没有括号的时候 返回当前类型
                if (strstr($val['type'], 'int')) $rule['number'] = 'number';

                // 设置长度
                preg_match("/(?<=\().*?(?=\))/", $val['type'], $length);
                if (!empty($length)) $rule['length'] = 'length:1,' . (int)$length[0];

                // 读取扩展验证
                preg_match("/(?<=\().*?(?=\))/", $val['comment'], $comment);
                if (!empty($comment)) {
                    // 判断生成的验证是否和用户自定义规则冲突
                    if (strstr($comment[0], 'length')) unset($rule['length']);
                    $comment = explode('|', $comment[0]);
                    $rule = array_merge($comment, $rule);
                }

                // 给字段设置中文名
                $val['comment_new'] = strstr($val['comment'], '(', true) ?: $val['comment'];
                // 设置映射字段
                $rules[$tableName]['field'][$key] = $key . '|' . $val['comment_new'];
                $rules[$tableName]['rule'][$key . '|' . $val['comment_new']] = implode('|', array_keys(array_flip($rule)));
            }
        }
        // 写入配置文件
        file_put_contents($this->app->getRootPath() . 'cccms/config/validate.php', '<?php' . PHP_EOL . 'return ' . var_export($rules, true) . ';');
    }
}