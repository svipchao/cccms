<?php

declare(strict_types=1);

/** 租户（多租户隔离）相关文案。 */
return [
    // 平台租户（虚拟租户，库里没有这一行）
    'platform_name'   => '平台',
    'platform_remark' => '平台租户（系统内置，不可编辑或删除）',

    // 档案读写
    'not_found'             => '租户不存在',
    'name_required'         => '请填写租户名称',
    'code_required'         => '请填写租户标识',
    'code_invalid'          => '租户标识只能包含字母、数字、下划线与短横线，长度 2-64',
    'code_exists'           => '租户标识已存在',
    'code_in_trashed'       => '租户标识 {code} 在回收站中，请先恢复或彻底删除',
    'cannot_edit_platform'  => '平台租户是系统内置的，不可编辑',
    'cannot_delete_platform' => '平台租户是系统内置的，不可删除',
    'has_users'             => '该租户下还有 {count} 个账号，请先转移或删除后再删除租户',

    // 自定义域名
    'domain_invalid'    => '域名格式不正确：只填主机名（如 crm.example.com），不要带协议、端口或路径',
    'domain_exists'     => '该域名已被其它租户占用',
    'domain_in_trashed' => '域名 {domain} 已被回收站中的租户占用，请先恢复或彻底删除',

    // 权限与切换
    'super_only'    => '仅超级管理员可执行该操作',
    'platform_only' => '该操作只能在平台租户下执行，请先切换回平台',
    'not_usable'    => '该租户已被禁用或已过期，无法切换',
    'domain_locked' => '当前通过租户自定义域名访问，只能使用该租户，不能切换到平台或其它租户',
    'switched'      => '已切换租户',
];
