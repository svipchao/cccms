<?php

/**
 * 存储驱动的**代码级默认值**。
 *
 * 驱动选择与四家云存储的凭证（AK/SK、bucket、endpoint/region、domain）都不在这里配：
 * 统一在后台「系统设置 → 配置管理 → 上传」维护（`sys_config.upload.*`，密钥类项加密存库）。
 * 本文件只保留无需运维调整的默认值，见 `support/storage/` 下各驱动的读取逻辑。
 */
return [
    // 初始驱动：仅在后台尚未配置 upload.storage_driver 时生效
    'driver'     => getenv('STORAGE_DRIVER') ?: 'local',

    // 本地驱动：URL 前缀（同域 /storage）与落盘目录 public/storage
    //
    // 匿名可读由「单个附件的 is_public」+ 后台配置 upload.anonymous（总开关）共同决定，
    // 校验在 StorageGuard 中间件里；⚠️ 因此 /storage 必须反代到后端，Nginx 直接托管会绕过校验。
    'url_prefix' => '/storage',

    // 上传白名单与大小上限（所有驱动共用）
    //
    // ⚠️ 安全约束：**不要加入 svg / html / xml**。本地驱动落盘 `public/storage` 且与后台
    // 同域直出，这类文件被浏览器按 Content-Type 内联渲染时会执行其中的脚本，
    // 形成存储型 XSS（可读取 localStorage 中的 token）。如需 SVG，请先二次渲染为 PNG。
    'allow_ext'  => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'md', 'zip', 'rar', '7z',
        'mp3', 'mp4', 'webm',
    ],
    'max_size'   => 10 * 1024 * 1024,
];
