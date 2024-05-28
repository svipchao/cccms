<?php
declare(strict_types=1);

namespace app\admin\model;

use cccms\Model;
use cccms\services\TypesService;
use think\facade\Cache;
use think\model\relation\HasOne;

class SysRoute extends Model
{
    public static function onBeforeWrite($model)
    {
        Cache::delete('SysRoutes');
    }

    public function type(): HasOne
    {
        return $this->hasOne(SysTypes::class, 'id', 'type_id')->bind([
            'type_name' => 'name'
        ]);
    }

    // 类别搜索器
    public function searchTypeIdAttr($query, $value)
    {
        $types = TypesService::instance()->getTypes(3, 'id');
        if (empty($types)) {
            $value = 0;
        } elseif (!isset($types[$value])) {
            $value = array_shift($types)['id'] ?? 0;
        }
        $query->where('type_id', '=', $value);
    }

    public function setTypeIdAttr($value): int
    {
        $types = TypesService::instance()->getTypes(3, 'id');
        if (!isset($types[$value])) {
            _result(['code' => 403, 'msg' => '类型错误'], _getEnCode());
        }
        return (int)$value;
    }
}