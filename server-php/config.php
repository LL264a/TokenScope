<?php
/**
 * Token Monitor v1.9.4 - 配置文件
 */

// ============ 版本 ============
define('APP_VERSION', 'v1.9.4');

// ============ 路径 ============
define('TM_ROOT', __DIR__);
define('TM_DB_PATH', '/var/lib/token-monitor/data/token_monitor.db');
define('TM_DATA_DIR', '/var/lib/token-monitor/data');

// ============ 平台配置 ============
define('TM_PLATFORMS', [
    'tencent' => [
        'name' => '腾讯云',
        'icon' => '🔷',
        'services' => [
            ['key' => 'tencent_codingplan', 'name' => 'Coding Plan', 'plan_type' => 'codingplan'],
            ['key' => 'tencent_hy_tokenplan', 'name' => 'Hy Token Plan', 'plan_type' => 'hy_tokenplan'],
            ['key' => 'tencent_tokenplan', 'name' => 'Token Plan（个人版）', 'plan_type' => 'tokenplan'],
        ],
        'credential_types' => ['cookie'],
        'cookie_hint' => 'JSON格式: {"cookie":"完整Cookie字符串","uin":"你的QQ号","ownerUin":"同uin","csrfCode":"940711892"}',
    ],
    'volcano' => [
        'name' => '火山引擎',
        'icon' => '🌋',
        'services' => [
            ['key' => 'volcano_codingplan', 'name' => 'Coding Plan + Agent Plan', 'plan_type' => 'codingplan'],
            ['key' => 'volcano', 'name' => '方舟余额', 'plan_type' => 'volcano'],
        ],
        'credential_types' => ['cookie', 'api_key'],
        'cookie_hint' => '从浏览器 DevTools 复制 Cookie（需先登录 console.volcengine.com）→ Cookie中需包含 csrfToken',
        'api_key_hint' => 'JSON格式: {"ak":"AccessKey","sk":"SecretKey"}  从火山引擎控制台 → 安全认证获取（仅查余额，不含Coding Plan配额）' . "\n\n" . '💡 同时配Cookie和AK/SK: {"cookie":"完整Cookie","ak":"AK","sk":"SK"}',
    ],
    'xiaomi' => [
        'name' => 'MIMO',
        'icon' => '🧸',
        'services' => [
            ['key' => 'xiaomi', 'name' => 'MiMo 用量', 'plan_type' => 'xiaomi'],
        ],
        'credential_types' => ['cookie'],
        'cookie_hint' => '从浏览器 DevTools 复制 Cookie（需先登录 platform.xiaomimimo.com）',
    ],
    'deepseek' => [
        'name' => 'DeepSeek',
        'icon' => '🔮',
        'services' => [
            ['key' => 'deepseek', 'name' => 'DeepSeek 用量', 'plan_type' => 'deepseek'],
        ],
        'credential_types' => ['api_key', 'cookie'],
        'api_key_hint' => '粘贴 DeepSeek API Key（从 https://platform.deepseek.com/api_keys 获取）→ 显示账户余额（¥）',
        'cookie_hint' => '粘贴 Token（登录 platform.deepseek.com → F12 → Application → LocalStorage → userToken → 复制value值）→ 显示按模型用量明细\n\n也可同时提供：{"api_key":"sk-xxx","token":"xxx"} 同时显示余额和用量',
    ],
    'minimax' => [
        'name' => 'MiniMax',
        'icon' => '',
        'services' => [
            ['key' => 'minimax', 'name' => 'Token Plan 用量', 'plan_type' => 'minimax'],
            ['key' => 'minimax_gateway', 'name' => '中转站网关', 'plan_type' => 'minimax_gateway'],
        ],
        'credential_types' => ['cookie', 'api_key'],
        'cookie_hint' => '官方平台 Cookie（登录 platform.minimaxi.com → F12 → Application → Cookies → 复制全部）→ 显示 Token Plan 用量与模型明细',
        'api_key_hint' => '中转站 API Key（minnimax.chat，以 gw- 开头）→ 显示 5h/周额度',
    ],
    'gpt_gateway' => [
        'name' => 'GPT中转',
        'icon' => '',
        'services' => [
            ['key' => 'gpt_gateway', 'name' => 'API Gateway', 'plan_type' => 'gpt_gateway'],
        ],
        'credential_types' => ['token'],
        'token_hint' => '粘贴 auth_token（登录中转站 → F12 → Application → Local Storage → auth_token → 复制 value）。如需自动续期，可粘贴 JSON：{"token":"...","refresh_token":"..."}',
    ],
]);

// ============ 子计划→父平台映射 ============
define('TM_SUB_TO_PARENT', [
    'tencent_codingplan' => 'tencent',
    'tencent_hy_tokenplan' => 'tencent',
    'tencent_tokenplan' => 'tencent',
    'volcano_codingplan' => 'volcano',
    'volcano' => 'volcano',
    'xiaomi' => 'xiaomi',
    'deepseek' => 'deepseek',
    'minimax' => 'minimax',
    'minimax_gateway' => 'minimax',
    'gpt_gateway' => 'gpt_gateway',
]);

// ============ 腾讯云 API ============
define('TENCENT_CAPI_URL', 'https://console.cloud.tencent.com/cgi/capi');

// ============ 火山引擎 API ============
define('VOLCANO_CONSOLE_API', 'https://console.volcengine.com/api/top/ark/cn-beijing/2024-01-01');

// ============ 火山 AK/SK 签名配置 ============
define('VOLCANO_BILLING_HOST', 'billing.volcengineapi.com');
define('VOLCANO_BILLING_SERVICE', 'billing');
define('VOLCANO_BILLING_REGION', 'cn-beijing');
define('VOLCANO_BILLING_ACTION', 'QueryBalanceAcct');
define('VOLCANO_BILLING_VERSION', '2022-01-01');

// ============ 安全 ============
define('TM_SESSION_EXPIRE', 86400); // 24小时
define('TM_LOGIN_MAX_ATTEMPTS', 5);
define('TM_LOGIN_WINDOW', 300); // 5分钟
define('TM_PBKDF2_ITERATIONS', 200000);
define('TM_PBKDF2_SALT', getenv('TOKEN_MONITOR_SALT') ?: 'token_monitor_2026_production_salt');

// ============ 通用 JSON 响应函数 ============
function tm_json_response($data, int $code=200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function tm_json_error(string $msg, int $code=400) {
    tm_json_response(['detail' => $msg], $code);
}
