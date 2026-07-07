<?php
/**
 * Token Monitor - 统一入口
 * 所有请求通过此文件路由
 */

// ============ 安全响应头 ============
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'");
header('X-Powered-By: ');  // 隐藏 PHP 版本
header_remove('X-Powered-By');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if ($script_dir !== '/' && strpos($uri, $script_dir) === 0) {
    $uri = substr($uri, strlen($script_dir));
}
$uri = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
// 传给 api.php（避免 php://input 二次消费）
$GLOBALS['_INDEX_INPUT'] = $input;

// API 路由
if (strpos($uri, '/api/') === 0) {
    // ============ CORS ============
    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
    header("Access-Control-Allow-Origin: " . ($origin ?: "*"));
    header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-TOTP-Key, X-TOTP-Code");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
    if ($method === "OPTIONS") { http_response_code(204); exit; }

    // 公开平台配置（只返回有凭证的平台）
    if ($uri === "/api/platforms" && $method === "GET") {
        header("Content-Type: application/json; charset=utf-8");
        require_once __DIR__ . "/db.php";
        $all = [
            ["id"=>"deepseek","name"=>"DeepSeek","url"=>"https://platform.deepseek.com/usage","domains"=>[".deepseek.com"]],
            ["id"=>"tencent","name"=>"腾讯云","url"=>"https://console.cloud.tencent.com/tokenhub/codingplan","domains"=>[".cloud.tencent.com",".tencent.com"]],
            ["id"=>"volcano","name"=>"火山引擎","url"=>"https://console.volcengine.com/ark/region:ark+cn-beijing/plan","domains"=>[".volcengine.com"]],
            ["id"=>"xiaomi","name"=>"小米 MIMO","url"=>"https://platform.xiaomimimo.com/console/plan-manage","domains"=>[".xiaomimimo.com"]],
            ["id"=>"minimax","name"=>"MiniMax","url"=>"https://minnimax.chat/usage","domains"=>[".minnimax.chat"]],
        ];
        // 返回全部平台，不限制必须有凭证（用于一键打开）
        echo json_encode($all, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // TOTP 密钥管理 + 平台配置（独立 require，避免与 api.php 路由冲突）
    if (strpos($uri, "/api/admin/api_keys") === 0 || $uri === "/api/admin/platforms") {
        require_once __DIR__ . "/config.php";
        require_once __DIR__ . "/db.php";
        require_once __DIR__ . "/auth.php";
        header("Content-Type: application/json; charset=utf-8");
        tm_require_auth();

        if ($uri === "/api/admin/platforms" && $method === "GET") {
            echo json_encode(TM_PLATFORMS, JSON_UNESCAPED_UNICODE);
        } elseif (strpos($uri, "/api/admin/api_keys") === 0) {
            if ($method === "GET") {
                echo json_encode(tm_list_api_keys(), JSON_UNESCAPED_UNICODE);
            } elseif ($method === "POST") {
                $name = $input["name"] ?? "Chrome Extension " . date("Y-m-d");
                echo json_encode(tm_create_api_key($name), JSON_UNESCAPED_UNICODE);
            } elseif ($method === "DELETE" && preg_match("#^/api/admin/api_keys/(\d+)$#", $uri, $m)) {
                if (tm_revoke_api_key(intval($m[1]))) echo json_encode(["status"=>"ok","message"=>"已吊销"]);
                else echo json_encode(["detail"=>"not found"]);
            } else {
                http_response_code(405); echo json_encode(["detail"=>"Method Not Allowed"]);
            }
        } else {
            http_response_code(405); echo json_encode(["detail"=>"Method Not Allowed"]);
        }
        exit;
    }

    // 其余 API → api.php
    require __DIR__ . '/api.php';
    exit;
}

// 静态文件（防路径遍历 + 禁止 data/ 目录）
// URI 级别拦截（文件不存在时也能保护）
if (strpos($uri, '/data/') === 0) {
    http_response_code(404);
    echo json_encode(['detail' => 'Not Found']);
    exit;
}
// realpath 级别拦截（文件存在时的二次保护）
$static_path = __DIR__ . $uri;
$real_path = realpath($static_path);
$real_dir = realpath(__DIR__);
if ($uri !== '/' && $real_path && is_file($real_path) && strpos($real_path, $real_dir) === 0) {
    $ext = pathinfo($real_path, PATHINFO_EXTENSION);
    $mime_types = [
        'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    ];
    $mime = $mime_types[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime; charset=utf-8");
    readfile($real_path);
    exit;
}

// 首页
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
