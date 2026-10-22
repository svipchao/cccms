<?php

declare(strict_types=1);

/**
 * 通用文案（后端）。
 *
 * key 约定：`{命名空间}.{键名}`，命名空间对应本目录下的文件名。
 * 缺 key 时 I18n::t() 会回落到 zh-CN；两边都缺才返回 key 原文并记 warning。
 */
return [
    'not_logged_in'          => '未登录',
    'session_expired'        => '登录状态已失效，请重新登录',
    'tenant_context_missing' => '未识别到租户上下文，操作已拒绝',
    'invalid_credentials'    => '登录凭证无效或用户已失效',
    'unauthorized'           => '未登录或登录已失效',
    'no_permission'          => '权限不足：{slug}',
    'permission_not_declared' => '接口未声明权限：{target}',
    'too_many_requests'      => '操作过于频繁，请 {seconds} 秒后重试',

    // ---- 协议级错误（中间件产生，与具体业务模块无关） ----
    'method_not_allowed'     => '请求方法不允许：{method}',
    'encoding_not_acceptable' => '响应编码不允许：{encoding}',

    // ---- 通用操作结果（控制器响应 message，各模块复用） ----
    'created'          => '创建成功',
    'updated'          => '更新成功',
    'deleted'          => '删除成功',
    'saved'            => '保存成功',
    'imported'         => '导入完成',
    'copied'           => '复制成功',
    'generated'        => '生成成功',
    'moved'            => '移动成功',
    'reset'            => '重置成功',
    'logged_out'       => '已退出',
    'exec_success'     => '执行成功',
    'exec_failed'      => '执行失败',
    'marked_read'      => '已标记为已读',
    'marked_all_read'  => '已全部标记为已读',
    'forced_offline'   => '已强制下线',
    'password_changed' => '密码已修改',
    'device_offline'   => '该设备已下线',

    // 带数量的批量结果
    'updated_count'    => '已更新 {count} 条',
    'deleted_count'    => '已删除 {count} 条',
    'assigned_count'   => '已分配 {count} 条',
    'restored_count'   => '已恢复 {count} 条',
    'purged_count'     => '已彻底删除 {count} 条',

    // 通用校验
    'select_required'  => '请选择要操作的数据',
];
