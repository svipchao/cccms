<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;

class AuthService extends Service
{
    /**
     * 获取条件映射
     * @param string $where
     * @return array
     */
    public function getWhereCorresponding(string $where): array
    {
        return [];
    }

    /**
     * 获取表字段
     * @param string $tableName
     * @return array
     */
    public function fields(string $tableName = ''): array
    {
        $tableFields = InitService::instance()->getTables($tableName);
        $dataFields = DataService::instance()->getUserData($tableName);
        return array_diff_key($tableFields, array_flip($dataFields['withoutField']));
    }
}
