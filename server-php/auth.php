<?php
/**
 * Token Monitor - 认证模块
 * PBKDF2-SHA256 密码哈希 + Bearer Token 会话
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ============ 会话存储（SQLite 原子操作） ============

function tm_init_auth_tables() {
    $db = tm_get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        token TEXT PRIMARY KEY,
        expire INTEGER NOT NULL,
        created INTEGER NOT NULL
    )");
    // 迁移旧 sessions.json → SQLite
    $old_file = TM_DATA_DIR . '/sessions.json';
    if (file_exists($old_file)) {
        $old_data = json_decode(file_get_contents($old_file), true);
        if (is_array($old_data)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO sessions (token, expire, created) VALUES (?, ?, ?)");
            foreach ($old_data as $token => $s) {
                if (($s['expire'] ?? 0) > time()) {
                    $stmt->execute([$token, $s['expire'], $s['created'] ?? time()]);
                }
            }
        }
        unlink($old_file);
    }
    // 清理过期会话
    $db->exec("DELETE FROM sessions WHERE expire < " . time());
}

function tm_create_session(): string {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (\Exception $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(32));
    }
    $db = tm_get_db();
    tm_init_auth_tables();
    $db->prepare("INSERT OR REPLACE INTO sessions (token, expire, created) VALUES (?, ?, ?)")
        ->execute([$token, time() + TM_SESSION_EXPIRE, time()]);
    return $token;
}

function tm_validate_session(string $token): bool {
    $db = tm_get_db();
    tm_init_auth_tables();
    $stmt = $db->prepare("SELECT 1 FROM sessions WHERE token = ? AND expire > ?");
    $stmt->execute([$token, time()]);
    return (bool)$stmt->fetch();
}

function tm_destroy_session(string $token) {
    $db = tm_get_db();
    tm_init_auth_tables();
    $db->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
}

function tm_destroy_all_sessions_except(string $except_token) {
    $db = tm_get_db();
    tm_init_auth_tables();
    $db->prepare("DELETE FROM sessions WHERE token != ?")->execute([$except_token]);
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

// ============ 登录限流（SQLite 原子更新） ============

function tm_init_rate_table() {
    $db = tm_get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS login_rate (
        ip TEXT PRIMARY KEY,
        count INTEGER DEFAULT 0,
        expire INTEGER NOT NULL
    )");
    // 迁移旧 login_rate.json → SQLite
    $old_file = TM_DATA_DIR . '/login_rate.json';
    if (file_exists($old_file)) {
        $old_data = json_decode(file_get_contents($old_file), true);
        if (is_array($old_data)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO login_rate (ip, count, expire) VALUES (?, ?, ?)");
            foreach ($old_data as $ip => $r) {
                if (($r['expire'] ?? 0) > time()) {
                    $stmt->execute([$ip, $r['count'] ?? 0, $r['expire']]);
                }
            }
        }
        unlink($old_file);
    }
}

function tm_check_login_rate(string $ip): bool {
    $db = tm_get_db();
    tm_init_rate_table();
    $now = time();
    
    // 清理过期记录
    $db->exec("DELETE FROM login_rate WHERE expire < $now");
    
    // 原子更新：INSERT OR REPLACE + 只在 count < 阈值时递增
    // 先检查当前值
    $stmt = $db->prepare("SELECT count, expire FROM login_rate WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    
    if ($row) {
        if ($row['count'] >= TM_LOGIN_MAX_ATTEMPTS) {
            return false;
        }
        // 原子递增 count
        $db->prepare("UPDATE login_rate SET count = count + 1 WHERE ip = ? AND count < ?")
            ->execute([$ip, TM_LOGIN_MAX_ATTEMPTS]);
    } else {
        // 新记录
        $db->prepare("INSERT INTO login_rate (ip, count, expire) VALUES (?, 1, ?)")
            ->execute([$ip, $now + TM_LOGIN_WINDOW]);
    }
    
    // 验证是否真被限制了（double-check）
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    return $row && $row['count'] <= TM_LOGIN_MAX_ATTEMPTS;
}

function tm_get_remaining_attempts(string $ip): int {
    $db = tm_get_db();
    tm_init_rate_table();
    $stmt = $db->prepare("SELECT count FROM login_rate WHERE ip = ? AND expire > ?");
    $stmt->execute([$ip, time()]);
    $row = $stmt->fetch();
    $count = $row ? (int)$row['count'] : 0;
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
    // 1. 检查 TOTP 两步验证（插件用）
    $totp_key = $_SERVER['HTTP_X_TOTP_KEY'] ?? '';
    if ($totp_key) {
        $key_name = tm_verify_api_totp();
        if ($key_name) return "totp:$key_name";
    }
    // 2. 检查 X-Internal-Key
    $internal_key = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    if ($internal_key && tm_verify_internal_key($internal_key)) {
        return 'internal';
    }
    // 3. 检查 Bearer Token
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
