<?php

return [
    // HS256 要求密钥 >= 32 字节。**不提供默认值**：未注入 JWT_SECRET 时
    // `SecurityBootstrap` 会在启动阶段直接终止进程（fail-closed），
    // 避免「仓库内公开的默认密钥」被用来伪造任意用户（含超管）令牌。
    'secret' => (string)(getenv('JWT_SECRET') ?: ''),
    // accessToken 有效期（秒）。默认 2 小时：令牌存在 localStorage，TTL 就是
    // XSS 一旦发生攻击者能用的窗口长度。体验代价由**滑动续期**抵消 ——
    // `CheckLogin` 在剩余不足 1/3 时签发新令牌并经 `X-Refresh-Token` 下发，
    // 前端静默替换，活跃用户不会掉线（见 `TokenService::shouldRenew()`）。
    // 后台可用 `security.token_ttl` 覆盖。
    'ttl' => 7200,
    // 字段级 encrypt 密钥（AES-256-GCM 种子，经 sha256 规整为 32 字节）。
    // **不提供默认值**：与 JWT_SECRET 同一口径 —— 仓库内公开的默认密钥等于没有密钥。
    // 未注入时 `Cipher::configured()` 返回 false，字段级 encrypt 自动停用（启动期只告警，
    // 因为更换密钥会让历史密文永久不可解密，见 SecurityBootstrap）。
    // 已确认**不做密钥轮换**，一旦更换，历史加密字段将无法解密。
    'data_encrypt_key' => (string)(getenv('DATA_ENCRYPT_KEY') ?: ''),
];
