<?php
/**
 * Token Monitor - 统一入口
 * 所有请求通过此文件路由
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
// 去除子目录前缀
if ($script_dir !== '/' && strpos($uri, $script_dir) === 0) {
    $uri = substr($uri, strlen($script_dir));
}
$uri = rtrim($uri, '/') ?: '/';

// API 路由 → api.php
if (strpos($uri, '/api/') === 0) {
    require __DIR__ . '/api.php';
    exit;
}

// 静态文件
$static_path = __DIR__ . $uri;
// 防止路径遍历：必须在实际目录内
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

// 首页 → index.html
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
