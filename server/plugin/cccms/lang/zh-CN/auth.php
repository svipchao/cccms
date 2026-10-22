<?php

declare(strict_types=1);

/** 登录认证相关文案。 */
return [
    'missing_credentials' => '请输入用户名和密码',
    'captcha_invalid'     => '验证码错误或已过期',
    'captcha_service_down' => '验证码服务不可用，请联系管理员',
    'bad_credentials'     => '用户名或密码错误',
    'attempt_tip'         => '，还可尝试 {count} 次',
    'account_disabled'    => '账号已被禁用',
    'role_abnormal'       => '账号角色异常，请联系管理员',
    'domain_mismatch'     => '该账号不属于此站点，请使用平台主域名登录',
    'too_many_attempts'   => '登录失败次数过多，请 {minutes} 分钟后再试',
    'login_success'       => '登录成功',
    'maintenance'         => '系统维护中，请稍后访问',

    // ---- 找回密码（邮箱 / 短信） ----
    'reset_account_required'  => '请输入账号',
    'reset_channel_invalid'   => '找回方式无效',
    'reset_channel_disabled'  => '该找回方式暂未开启，请联系管理员',
    'reset_code_sent'         => '若账号存在，验证码已发送',
    'reset_send_too_often'    => '发送过于频繁，请 {seconds} 秒后再试',
    'reset_daily_limit'       => '今日发送次数已达上限，请明天再试',
    'reset_code_invalid'      => '验证码错误或已过期',
    'reset_same_password'     => '新密码不能与旧密码相同',
    'reset_service_down'      => '验证码服务暂不可用，请稍后重试',
    'reset_success'           => '密码重置成功，请使用新密码登录',
    'reset_mail_subject'      => '【{name}】密码重置验证码',
    'reset_mail_body'         => '你的密码重置验证码是 {code}，{minutes} 分钟内有效。如非本人操作，请忽略本邮件。',
];
