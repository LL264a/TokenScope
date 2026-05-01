<?php
/**
 * Token Monitor - 数据接收端点
 * 接收本地 Python 实例 push_to_server.py 推送的数据
 *
 * 用法:
 *   curl -X POST https://你的域名/token/receive.php \
 *     -H "Content-Type: application/json" \
 *     -H "X-Token: tm_2026_change_me" \
 *     -d '{"type":"stats","payload":{...}}'
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ============ 配置 ============
define('RECEIVE_TOKEN', 'tm_2026_change_me');  // 必须和 push_to_server.py 中一致

// ============ 初始化 ============
header('Content-Type: application/json; charset=utf-8');
tm_init_tables();

// ============ 鉴权 ============
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if (!hash_equals(RECEIVE_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => '无效的 Token']);
    exit;
}

// ============ 解析请求 ============
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['type']) || !isset($input['payload'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => '缺少 type 或 payload']);
    exit;
}

$type = $input['type'];
$payload = $input['payload'];
$db = tm_get_db();
$size = 0;

try {
    if ($type === 'stats') {
        // stats 数据: {"platforms": [...], "last_updated": "...", "version": "..."}
        $platforms = $payload['platforms'] ?? [];
        $inserted = 0;

        foreach ($platforms as $p) {
            $platform = $p['platform'] ?? '';
            if (!$platform) continue;

            // 跳过 no_data 占位卡（有凭证无数据，不写入 DB）
            if ($p['no_data'] ?? false) continue;

            // 处理聚合平台（tencent/volcano 带 services）→ 拆分子服务分别保存
            $services = $p['services'] ?? null;
            if ($services !== null) {
                foreach ($services as $svc) {
                    _save_platform($db, $svc);
                    $inserted++;
                }
            } else {
                // 独立平台（xiaomi/deepseek）→ 直接保存
                _save_platform($db, $p);
                $inserted++;
            }
        }

        $size = strlen(json_encode($input));
        echo json_encode([
            'status' => 'ok',
            'type' => 'stats',
            'platforms' => $inserted,
            'size' => $size,
        ]);

    } elseif ($type === 'cookie_status') {
        // cookie_status 数据: [{"platform":"...","healthy":bool,"message":"..."}, ...]
        $updated = 0;
        $status_map = [];

        foreach ($payload as $cs) {
            $platform = $cs['platform'] ?? '';
            if (!$platform) continue;
            $status_map[$platform] = $cs;
            $updated++;
        }

        // 写入 cookie 状态到 refresh_settings
        $stmt = $db->prepare(
            "INSERT INTO refresh_settings (key, value) VALUES (:key, :val)
             ON CONFLICT(key) DO UPDATE SET value = :val2"
        );
        $stmt->execute([
            ':key' => 'cookie_status',
            ':val' => json_encode($status_map, JSON_UNESCAPED_UNICODE),
            ':val2' => json_encode($status_map, JSON_UNESCAPED_UNICODE),
        ]);

        $size = strlen(json_encode($input));
        echo json_encode([
            'status' => 'ok',
            'type' => 'cookie_status',
            'platforms' => $updated,
            'size' => $size,
        ]);

    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => "未知类型: $type"]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}

// ============ 辅助函数 ============

function _save_platform(PDO $db, array $p): void {
    $platform = $p['platform'] ?? '';
    $raw = $p;
    unset($raw['platform'], $raw['total_tokens'], $raw['input_tokens'],
          $raw['output_tokens'], $raw['cost'], $raw['remaining']);

    $stmt = $db->prepare(
        "INSERT INTO platform_usage (timestamp, platform, total_tokens, input_tokens,
         output_tokens, cost, remaining, raw_json)
         VALUES (:ts, :platform, :total, :input, :output, :cost, :remaining, :raw)"
    );
    $stmt->execute([
        ':ts' => microtime(true),
        ':platform' => $platform,
        ':total' => intval($p['total_tokens'] ?? 0),
        ':input' => intval($p['input_tokens'] ?? 0),
        ':output' => intval($p['output_tokens'] ?? 0),
        ':cost' => floatval($p['cost'] ?? 0),
        ':remaining' => strval($p['remaining'] ?? ''),
        ':raw' => json_encode($raw, JSON_UNESCAPED_UNICODE),
    ]);
}
