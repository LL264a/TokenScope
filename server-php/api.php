<?php
/**
 * Token Monitor - API 路由
 * 处理所有 /api/* 请求
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// 初始化数据库
tm_init_tables();

// 解析路由
$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($uri, PHP_URL_PATH);
$query = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

// 去除前缀（如果部署在子目录）
$base = dirname($_SERVER['SCRIPT_NAME']);
if ($base !== '/' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
}
$path = rtrim($path, '/') ?: '/';

// 获取请求体（优先用 index.php 已经解析的，避免 php://input 二次消费）
$input = $GLOBALS['_INDEX_INPUT'] ?? (json_decode(file_get_contents('php://input'), true) ?: []);

// ============ 路由分发 ============

// 认证 API
if ($path === '/api/auth/status' && $method === 'GET') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $authenticated = false;
    if (strpos($auth, 'Bearer ') === 0) {
        $authenticated = tm_validate_session(substr($auth, 7));
    }
    tm_json_response(['has_password' => tm_has_password(), 'authenticated' => $authenticated]);
}

if ($path === '/api/auth/setup' && $method === 'POST') {
    try {
        if (tm_has_password()) tm_json_error('密码已设置，请使用登录接口', 400);
        $password = $input['password'] ?? '';
        if (strlen($password) < 4) tm_json_error('密码至少4位', 400);
        tm_set_password_hash(tm_hash_password($password));
        $token = tm_create_session();
        tm_json_response(['status' => 'ok', 'token' => $token, 'message' => '密码设置成功']);
    } catch (\Exception $e) {
        tm_json_error('设置失败: ' . $e->getMessage(), 500);
    }
}

if ($path === '/api/auth/login' && $method === 'POST') {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!tm_check_login_rate($ip)) {
            tm_json_error('尝试次数过多，请300秒后再试', 429);
        }
        $password = $input['password'] ?? '';
        $stored_hash = tm_get_password_hash();
        if (!$stored_hash) tm_json_error('请先设置密码', 400);
        if (!tm_verify_password($password, $stored_hash)) {
            $remaining = tm_get_remaining_attempts($ip);
            tm_json_error("密码错误，剩余尝试次数: $remaining", 401);
        }
        $token = tm_create_session();
        tm_json_response(['status' => 'ok', 'token' => $token]);
    } catch (\Exception $e) {
        tm_json_error('登录失败: ' . $e->getMessage(), 500);
    }
}

if ($path === '/api/auth/logout' && $method === 'POST') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth, 'Bearer ') === 0) {
        tm_destroy_session(substr($auth, 7));
    }
    tm_json_response(['status' => 'ok']);
}

if ($path === '/api/auth/change-password' && $method === 'POST') {
    $token = tm_require_auth();
    try {
        $old_password = $input['old_password'] ?? '';
        $new_password = $input['new_password'] ?? '';
        if (!$new_password || strlen($new_password) < 4) tm_json_error('新密码至少4位', 400);
        $stored_hash = tm_get_password_hash();
        if ($stored_hash && !tm_verify_password($old_password, $stored_hash)) {
            tm_json_error('原密码错误', 401);
        }
        tm_set_password_hash(tm_hash_password($new_password));
        tm_destroy_all_sessions_except($token);
        tm_json_response(['status' => 'ok', 'message' => '密码已修改，其他设备已登出']);
    } catch (\Exception $e) {
        tm_json_error('修改失败: ' . $e->getMessage(), 500);
    }
}

// 监控 API（公开）
if ($path === '/api/stats' && $method === 'GET') {
    tm_api_stats();
}

if ($path === '/api/scrape-status' && $method === 'GET') {
    $sub_data = tm_get_latest_usage();
    $result = [];
    foreach ($sub_data as $p) {
        $result[] = [
            'platform' => $p['platform'],
            'last_scraped' => $p['last_updated'] ?? null,
            'total_tokens' => $p['total_tokens'] ?? 0,
            'remaining' => $p['remaining'] ?? '',
        ];
    }
    tm_json_response($result);
}

if ($path === '/api/cookie-status' && $method === 'GET') {
    tm_api_cookie_status();
}

// 管理 API（需认证）
if ($path === '/api/admin/credentials' && $method === 'GET') {
    tm_require_auth();
    tm_json_response(tm_list_credentials());
}

if ($path === '/api/admin/credentials' && $method === 'POST') {
    tm_require_auth();
    $platform = $input['platform'] ?? '';
    $cred_type = $input['credential_type'] ?? 'cookie';
    $cred_data = $input['credential_data'] ?? '';
    $note = $input['note'] ?? '';
    if (!$platform || !$cred_data) tm_json_error('平台和凭证数据不能为空', 400);
    $platforms = TM_PLATFORMS;
    if (!isset($platforms[$platform])) tm_json_error("不支持的平台: $platform", 400);
    tm_save_credential($platform, $cred_type, $cred_data, $note);
    tm_json_response(['status' => 'ok', 'message' => "已保存 {$platform} 的凭证"]);
}

// GET /api/admin/credentials/{platform}
if (preg_match('#^/api/admin/credentials/([a-z_]+)$#', $path, $m) && $method === 'GET') {
    tm_require_auth();
    $cred = tm_get_credential($m[1]);
    if (!$cred) tm_json_error('凭证不存在', 404);
    $data = $cred['credential_data'] ?? '';
    $masked = strlen($data) > 20 ? substr($data, 0, 8) . '...' . substr($data, -4)
        : (strlen($data) > 4 ? substr($data, 0, 4) . '...' : '***');
    tm_json_response([
        'platform' => $cred['platform'],
        'credential_type' => $cred['credential_type'],
        'credential_data_masked' => $masked,
        'note' => $cred['note'] ?? '',
        'updated_at' => $cred['updated_at'] ? date('Y-m-d H:i:s', $cred['updated_at']) : '',
    ]);
}

// DELETE /api/admin/credentials/{platform}
if (preg_match('#^/api/admin/credentials/([a-z_]+)$#', $path, $m) && $method === 'DELETE') {
    tm_require_auth();
    $platform = $m[1];
    if (tm_delete_credential($platform)) {
        tm_json_response(['status' => 'ok', 'message' => "已删除 {$platform} 的凭证"]);
    }
    tm_json_error('凭证不存在');
}

if ($path === '/api/admin/refresh' && $method === 'POST') {
    tm_require_auth();
    require_once __DIR__ . '/collectors.php';
    $results = tm_do_refresh_all();
    tm_json_response(['status' => 'ok', 'results' => $results]);
}

// POST /api/admin/refresh/{platform}
if (preg_match('#^/api/admin/refresh/([a-z_]+)$#', $path, $m) && $method === 'POST') {
    tm_require_auth();
    require_once __DIR__ . '/collectors.php';
    $platform = $m[1];
    if (!isset(TM_PLATFORMS[$platform])) {
        tm_json_error('未知平台', 404);
    }
    $results = tm_do_refresh_platform($platform);
    tm_json_response(['status' => 'ok', 'results' => $results]);
}

// POST /api/admin/check-credential/{platform}
if (preg_match('#^/api/admin/check-credential/([a-z_]+)$#', $path, $m) && $method === 'POST') {
    tm_require_auth();
    require_once __DIR__ . '/collectors.php';
    $platform = $m[1];
    if (!isset(TM_PLATFORMS[$platform])) {
        tm_json_error('未知平台', 404);
    }
    $result = tm_do_check_credential($platform);
    tm_json_response($result);
}

if ($path === '/api/admin/refresh-log' && $method === 'GET') {
    tm_require_auth();
    $limit = intval($query['limit'] ?? 30);
    tm_json_response(tm_get_refresh_log($limit));
}

if ($path === '/api/admin/refresh-log' && $method === 'DELETE') {
    tm_require_auth();
    $db = tm_get_db();
    $db->exec("DELETE FROM refresh_log");
    tm_json_response(['status' => 'ok']);
}

if ($path === '/api/admin/scheduler' && $method === 'GET') {
    tm_require_auth();
    $running = tm_get_setting('scheduler_running', '0') === '1';
    $interval = intval(tm_get_setting('scheduler_interval', '30'));
    tm_json_response([
        'running' => $running,
        'interval' => $interval,
        'mode' => 'cron',
        'next_run' => $running ? date('Y-m-d H:i:s', time() + $interval) : null,
    ]);
}

if ($path === '/api/admin/scheduler' && $method === 'POST') {
    tm_require_auth();
    $action = $input['action'] ?? '';
    $interval = $input['interval'] ?? null;
    if ($interval) tm_set_setting('scheduler_interval', strval(max(60, intval($interval))));
    if ($action === 'start') {
        tm_set_setting('scheduler_running', '1');
        tm_json_response(['status' => 'ok', 'message' => '调度器已启动（cron模式）']);
    } elseif ($action === 'stop') {
        tm_set_setting('scheduler_running', '0');
        tm_json_response(['status' => 'ok', 'message' => '调度器已停止']);
    } elseif ($action === 'restart') {
        tm_set_setting('scheduler_running', '1');
        tm_json_response(['status' => 'ok', 'message' => '调度器已重启（cron模式）']);
    }
    tm_json_error('未知操作');
}

if ($path === '/api/admin/platforms' && $method === 'GET') {
    tm_require_auth();
    tm_json_response(TM_PLATFORMS);
}

if ($path === '/api/admin/sort-weights' && $method === 'GET') {
    tm_require_auth();
    tm_json_response(tm_get_sort_weights());
}

if ($path === '/api/admin/sort_mode' && $method === 'GET') {
    tm_require_auth();
    $mode = tm_get_setting('sort_mode', 'weight');
    tm_json_response(['mode' => $mode]);
}

if ($path === '/api/admin/sort_mode' && $method === 'POST') {
    tm_require_auth();
    $mode = $input['mode'] ?? '';
    if (!in_array($mode, ['realtime', 'weight'])) tm_json_error('无效模式');
    tm_set_setting('sort_mode', $mode);
    tm_json_response(['status' => 'ok', 'message' => $mode === 'realtime' ? '实时排序' : '权重排序']);
}

if ($path === '/api/admin/sort-weights' && $method === 'POST') {
    tm_require_auth();
    foreach ($input as $platform => $weight) {
        tm_set_sort_weight($platform, intval($weight));
    }
    tm_json_response(['status' => 'ok', 'message' => '排序权重已更新']);
}

tm_json_error('Not Found', 404);

// ============ 辅助函数 ============

function tm_api_stats() {
    $sub_data = tm_get_latest_usage();
    $all_platforms = [];
    $tencent_services = [];
    $volcano_services = [];
    $hidden = tm_get_hidden_services();

    foreach ($sub_data as $p) {
        $group = TM_SUB_TO_PARENT[$p['platform']] ?? null;
        if ($group === 'tencent') {
            if (!in_array($p['platform'], $hidden)) $tencent_services[] = $p;
        } elseif ($group === 'volcano') {
            if (!in_array($p['platform'], $hidden)) $volcano_services[] = $p;
        } else {
            $entry = [
                'platform' => $p['platform'],
                'total_tokens' => $p['total_tokens'] ?? 0,
                'input_tokens' => $p['input_tokens'] ?? 0,
                'output_tokens' => $p['output_tokens'] ?? 0,
                'cost' => $p['cost'] ?? 0,
                'remaining' => $p['remaining'] ?? '',
                'last_updated' => $p['last_updated'] ?? '',
                'source' => 'console',
                'calls' => 0,
            ];
            $extra_keys = ['quotas','plan_type','plan_code','remaining_days','valid_from','valid_to',
                'plan_status','remaining_pct','balance_available','balance_cash','balance_credit',
                'balance_frozen','balance_arrears','balance','gift_balance','cash_balance','frozen_balance',
                'cache_tokens','tpm','rpm','current_month_cost','month_used','month_limit','month_pct',
                'plan_pct','comp_total','comp_used','comp_pct','auto_renew',
                'model_usages','monthly_cost','cost_total','granted_balance','topped_up_balance',
                'daily_counts','plan_name'];
            foreach ($extra_keys as $key) {
                if (isset($p[$key])) $entry[$key] = $p[$key];
            }
            $all_platforms[$p['platform']] = $entry;
        }
    }

    // 腾讯聚合
    if ($tencent_services) {
        $latest_ts = max(array_column($tencent_services, 'last_updated'));
        $all_platforms['tencent'] = [
            'platform' => 'tencent',
            'total_tokens' => array_sum(array_column($tencent_services, 'total_tokens')),
            'input_tokens' => array_sum(array_column($tencent_services, 'input_tokens')),
            'output_tokens' => array_sum(array_column($tencent_services, 'output_tokens')),
            'cost' => array_sum(array_column($tencent_services, 'cost')),
            'remaining' => '',
            'last_updated' => $latest_ts,
            'source' => 'console', 'calls' => 0,
            'services' => $tencent_services,
        ];
    }

    // 火山聚合
    if ($volcano_services) {
        $latest_ts = max(array_column($volcano_services, 'last_updated'));
        $all_platforms['volcano'] = [
            'platform' => 'volcano',
            'total_tokens' => array_sum(array_column($volcano_services, 'total_tokens')),
            'input_tokens' => array_sum(array_column($volcano_services, 'input_tokens')),
            'output_tokens' => array_sum(array_column($volcano_services, 'output_tokens')),
            'cost' => array_sum(array_column($volcano_services, 'cost')),
            'remaining' => '',
            'last_updated' => $latest_ts,
            'source' => 'console', 'calls' => 0,
            'services' => $volcano_services,
        ];
    }

    // 排序权重
    $weights = tm_get_sort_weights();
    foreach ($all_platforms as $key => &$p) {
        $p['sort_weight'] = $weights[$key] ?? 0;
    }
    unset($p);

    // 有凭证但无数据的平台 → no_data 占位卡
    $creds = tm_list_credentials();
    foreach ($creds as $cred) {
        $platform = $cred['platform'];
        if (!isset($all_platforms[$platform])) {
            $all_platforms[$platform] = [
                'platform' => $platform,
                'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
                'cost' => 0, 'remaining' => '',
                'last_updated' => '', 'source' => 'console', 'calls' => 0,
                'services' => [], 'no_data' => true,
            ];
        }
    }

    // 排序
    $sort_mode = tm_get_setting('sort_mode', 'weight');
    if ($sort_mode === 'realtime') {
        // 实时排序：活跃平台靠前（有数据 > 无数据），同批时间戳用 usage 量做活跃度排序
        usort($all_platforms, function($a, $b) {
            $aNo = !empty($a['no_data']);
            $bNo = !empty($b['no_data']);
            if ($aNo !== $bNo) return $aNo ? 1 : -1;
            // 有数据的平台，按 last_updated 降序
            $at = $a['last_updated'] ?? '';
            $bt = $b['last_updated'] ?? '';
            if ($at !== $bt) {
                if ($at === '') return 1;
                if ($bt === '') return -1;
                return ($at < $bt) ? 1 : -1;
            }
            // 时间戳相同（批量刷新），按 usage 总量降序
            $aUsage = ($a['total_tokens'] ?? 0) + ($a['cost'] ?? 0);
            $bUsage = ($b['total_tokens'] ?? 0) + ($b['cost'] ?? 0);
            if ($aUsage !== $bUsage) return ($aUsage > $bUsage) ? -1 : 1;
            // 仍然一样，按名字排序保证稳定
            return strcmp($a['platform'] ?? '', $b['platform'] ?? '');
        });
    } else {
        // 权重模式：按注册顺序（前端会从 localStorage 读取拖动顺序覆盖）
        usort($all_platforms, function($a, $b) {
            $aNo = !empty($a['no_data']);
            $bNo = !empty($b['no_data']);
            if ($aNo !== $bNo) return $aNo ? 1 : -1;
            return strcmp($a['platform'] ?? '', $b['platform'] ?? '');
        });
    }

    tm_json_response([
        'platforms' => array_values($all_platforms),
        'last_updated' => date('c'),
        'version' => APP_VERSION,
    ]);
}

function tm_api_cookie_status() {
    $db = tm_get_db();
    $rows = $db->query("
        SELECT platform, status, message, timestamp
        FROM refresh_log
        WHERE id IN (
            SELECT id FROM refresh_log rl2
            WHERE rl2.platform = refresh_log.platform
            ORDER BY id DESC LIMIT 3
        )
        ORDER BY platform, id DESC
    ")->fetchAll();

    // 每个平台取最近3条
    $grouped = [];
    foreach ($rows as $row) {
        $p = $row['platform'];
        if (!isset($grouped[$p])) $grouped[$p] = [];
        if (count($grouped[$p]) < 3) $grouped[$p][] = $row;
    }

    $platform_status = [];
    foreach ($grouped as $p => $items) {
        $status = ['platform' => $p, 'healthy' => true, 'last_check' => null, 'message' => ''];
        foreach ($items as $row) {
            $ts = $row['timestamp'];
            if ($status['last_check'] === null || $ts > $status['last_check']) {
                $status['last_check'] = $ts;
                $status['last_check_fmt'] = date('Y-m-d H:i:s', $ts);
            }
            if ($row['status'] !== 'success') {
                $status['healthy'] = false;
                $msg = $row['message'] ?? '';
                $status['message'] = str_replace('🍪 ', '', $msg);
                $status['cookie_expired'] = strpos($msg, '🍪') !== false || stripos($msg, 'Cookie') !== false || stripos($msg, '失效') !== false;
            }
        }
        $platform_status[$p] = $status;
    }

    tm_json_response(array_values($platform_status));
}
