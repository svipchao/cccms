<?php
declare (strict_types=1);

return [
    'menu_id|父级菜单' => 'integer|different:id',
    'menu_id.integer' => '请选择正确的 %s',
    'menu_id.different' => '禁止选择自身为 %s',

    'name|菜单名称' => 'require|chsDash|length:1,255',
    'name.require' => '%s 不能为空',
    'name.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'name.length' => '%s 长度至少 1 个字符或不超过 32 个字符',

    'icon|菜单图标' => 'alphaDash|length:0,32',
    'icon.alphaDash' => '%s 字母和数字，下划线_及破折号-',
    'icon.length' => '%s 长度至少 1 个字符或不超过 32 个字符',

    'url|菜单链接' => 'length:0,255',
    'url.length' => '%s 长度不能超过 255 个字符',

    'node|菜单节点' => 'length:0,255',
    'node.length' => '%s 长度不能超过 255 个字符',

    'sort|排序' => 'integer',
    'sort.integer' => '请录入正确的 %s',

    'status|状态' => 'require|in:0,1',
    'status.require' => '%s 不能为空',
    'status.in' => '请选择正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
