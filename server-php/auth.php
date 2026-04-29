<?php
/**
 * Token Monitor - 认证模块
 * PBKDF2-SHA256 密码哈希 + Bearer Token 会话
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ============ 会话存储（文件缓存） ============

function tm_session_file(): string {
    return TM_DATA_DIR . '/sessions.json';
}

function tm_load_sessions(): array {
    $f = tm_session_file();
    if (!file_exists($f)) return [];
    $data = json_decode(file_get_contents($f), true);
    if (!is_array($data)) return [];
    // 清理过期会话
    $now = time();
    $data = array_filter($data, fn($s) => ($s['expire'] ?? 0) > $now);
    return $data;
}

function tm_save_sessions(array $sessions) {
    file_put_contents(tm_session_file(), json_encode($sessions, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function tm_create_session(): string {
    $token = bin2hex(random_bytes(32));
    $sessions = tm_load_sessions();
    $sessions[$token] = ['expire' => time() + TM_SESSION_EXPIRE, 'created' => time()];
    tm_save_sessions($sessions);
    return $token;
}

function tm_validate_session(string $token): bool {
    $sessions = tm_load_sessions();
    return isset($sessions[$token]) && $sessions[$token]['expire'] > time();
}

function tm_destroy_session(string $token) {
    $sessions = tm_load_sessions();
    unset($sessions[$token]);
    tm_save_sessions($sessions);
}

function tm_destroy_all_sessions_except(string $except_token) {
    $sessions = tm_load_sessions();
    foreach ($sessions as $t => $s) {
        if ($t !== $except_token) unset($sessions[$t]);
    }
    tm_save_sessions($sessions);
}

// ============ 密码哈希 (PBKDF2-SHA256) ============

function tm_hash_password(string $password): string {
    return hash_pbkdf2('sha256', $password, TM_PBKDF2_SALT, TM_PBKDF2_ITERATIONS, 64, false);
}

function tm_verify_password(string $password, string $stored_hash): bool {
    if (strpos($stored_hash, 'pbkdf2_sha256$') === 0) {
        $stored_hash = substr($stored_hash, 14);
    }
    $computed = hash_pbkdf2('sha256', $password, TM_PBKDF2_SALT, TM_PBKDF2_ITERATIONS, 64, false);
    return hash_equals($stored_hash, $computed);
}

// ============ 登录限流 ============

function tm_login_rate_file(): string {
    return TM_DATA_DIR . '/login_rate.json';
}

function tm_check_login_rate(string $ip): bool {
    $f = tm_login_rate_file();
    $rates = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $now = time();
    // 清理所有过期记录
    $rates = array_filter($rates, fn($r) => ($r['expire'] ?? 0) > $now);
    // 检查此IP
    $key = $ip;
    if (!isset($rates[$key])) $rates[$key] = ['count' => 0, 'expire' => $now + TM_LOGIN_WINDOW];
    // 先检查是否超限
    if ($rates[$key]['count'] >= TM_LOGIN_MAX_ATTEMPTS) {
        file_put_contents($f, json_encode($rates), LOCK_EX);
        return false;
    }
    // 未超限，递增计数
    $rates[$key]['count']++;
    file_put_contents($f, json_encode($rates), LOCK_EX);
    return true;
}

function tm_get_remaining_attempts(string $ip): int {
    $f = tm_login_rate_file();
    $rates = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $key = $ip;
    $count = $rates[$key]['count'] ?? 0;
    return max(0, TM_LOGIN_MAX_ATTEMPTS - $count);
}

// ============ 内部 API Key ============

function tm_get_internal_key(): string {
    $key = tm_get_setting('internal_api_key', '');
    if (!$key) {
        $key = bin2hex(random_bytes(16));
        tm_set_setting('internal_api_key', $key);
    }
    return $key;
}

function tm_verify_internal_key(string $key): bool {
    $expected = tm_get_internal_key();
    return hash_equals($expected, $key);
}

// ============ 认证检查 ============

function tm_require_auth(): ?string {
    // 1. 检查 X-Internal-Key
    $internal_key = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    if ($internal_key && tm_verify_internal_key($internal_key)) {
        return 'internal';
    }
    // 2. 检查 Bearer Token
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth, 'Bearer ') === 0) {
        $token = substr($auth, 7);
        if (tm_validate_session($token)) {
            return $token;
        }
    }
    tm_json_response(['detail' => '未登录'], 401);
    exit;
}
