<?php
/**
 * Token Monitor - 数据接收端
 * 接收本地推送的监控数据，存为 JSON 文件
 */

// ============ 配置 ============
$TOKEN = 'tm_2026_change_me';  // 推送令牌，必须和本地 push_to_server.py 中一致
$DATA_DIR = __DIR__ . '/data'; // 数据存放目录

// ============ 逻辑 ============
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');

// CORS 预检
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 验证令牌
$reqToken = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';
if ($reqToken !== $TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// 读取请求体
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// 创建数据目录
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}

// 存储数据
$type = $data['type'] ?? 'stats';
$allowed = ['stats', 'cookie_status'];

if (!in_array($type, $allowed)) {
    echo json_encode(['error' => 'Unknown type: ' . $type]);
    exit;
}

$file = $DATA_DIR . '/' . $type . '.json';
$payload = [
    'data' => $data['payload'] ?? $data,
    'updated_at' => date('Y-m-d H:i:s'),
    'updated_ts' => time(),
];

if (file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Write failed']);
    exit;
}

echo json_encode(['status' => 'ok', 'type' => $type, 'size' => strlen($body)]);
