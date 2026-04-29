<?php
/**
 * Token Monitor - 数据库层 (SQLite + PDO)
 */
require_once __DIR__ . '/config.php';

function tm_get_db(): PDO {
    static $db = null;
    if ($db === null) {
        if (!is_dir(TM_DATA_DIR)) {
            mkdir(TM_DATA_DIR, 0755, true);
        }
        $db = new PDO('sqlite:' . TM_DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
    }
    return $db;
}

function tm_init_tables() {
    $db = tm_get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS platform_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL,
            platform TEXT,
            total_tokens INTEGER DEFAULT 0,
            input_tokens INTEGER DEFAULT 0,
            output_tokens INTEGER DEFAULT 0,
            cost REAL DEFAULT 0,
            remaining TEXT DEFAULT '',
            raw_json TEXT DEFAULT '{}'
        );
        CREATE TABLE IF NOT EXISTS credentials (
            platform TEXT,
            credential_type TEXT DEFAULT 'cookie',
            credential_data TEXT NOT NULL,
            note TEXT DEFAULT '',
            updated_at REAL,
            PRIMARY KEY (platform, credential_type)
        );
        CREATE TABLE IF NOT EXISTS refresh_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL,
            platform TEXT,
            status TEXT,
            message TEXT,
            duration_ms INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS refresh_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");
}

// ============ 平台用量 ============

function tm_save_usage(string $platform, int $total_tokens=0, int $input_tokens=0, int $output_tokens=0,
                       float $cost=0.0, string $remaining='', array $raw=[]) {
    $db = tm_get_db();
    $stmt = $db->prepare("INSERT INTO platform_usage (timestamp, platform, total_tokens, input_tokens, output_tokens, cost, remaining, raw_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([time(), $platform, $total_tokens, $input_tokens, $output_tokens, $cost, $remaining, json_encode($raw, JSON_UNESCAPED_UNICODE)]);
}

function tm_get_latest_usage(): array {
    $db = tm_get_db();
    $rows = $db->query("
        SELECT platform, total_tokens, input_tokens, output_tokens, cost, remaining, timestamp, raw_json
        FROM platform_usage
        WHERE id IN (SELECT MAX(id) FROM platform_usage GROUP BY platform)
        ORDER BY platform
    ")->fetchAll();

    $results = [];
    $extra_keys = ['quotas','plan_type','plan_code','remaining_days','valid_from','valid_to','plan_status',
        'remaining_pct','balance_available','balance_cash','balance_credit','balance_frozen','balance_arrears',
        'balance','gift_balance','cash_balance','frozen_balance','cache_tokens','tpm','rpm','current_month_cost',
        'month_used','month_limit','month_pct','plan_pct','comp_total','comp_used','comp_pct','auto_renew','plan_name'];

    foreach ($rows as $row) {
        $item = $row;
        $item['last_updated'] = date('Y-m-d\TH:i:s', $row['timestamp']);
        try {
            $raw = json_decode($row['raw_json'], true) ?: [];
            foreach ($extra_keys as $key) {
                if (isset($raw[$key])) $item[$key] = $raw[$key];
            }
            if (isset($raw['quotas']) && !isset($raw['plan_type'])) $item['plan_type'] = 'Coding Plan';
        } catch (Exception $e) {}
        $results[] = $item;
    }
    return $results;
}

// ============ 凭证 ============

function tm_save_credential(string $platform, string $cred_type, string $data, string $note='') {
    $db = tm_get_db();
    $stmt = $db->prepare("INSERT OR REPLACE INTO credentials (platform, credential_type, credential_data, note, updated_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$platform, $cred_type, $data, $note, time()]);
}

function tm_get_credential(string $platform, ?string $cred_type=null): ?array {
    $db = tm_get_db();
    if ($cred_type) {
        $stmt = $db->prepare("SELECT * FROM credentials WHERE platform = ? AND credential_type = ?");
        $stmt->execute([$platform, $cred_type]);
    } else {
        $stmt = $db->prepare("SELECT * FROM credentials WHERE platform = ?");
        $stmt->execute([$platform]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function tm_get_all_credentials(string $platform): array {
    $db = tm_get_db();
    $stmt = $db->prepare("SELECT * FROM credentials WHERE platform = ?");
    $stmt->execute([$platform]);
    return $stmt->fetchAll();
}

function tm_get_credential_data(string $platform, ?string $cred_type=null): ?array {
    $cred = tm_get_credential($platform, $cred_type);
    if (!$cred) return null;
    $decoded = json_decode($cred['credential_data'], true);
    return is_array($decoded) ? $decoded : ['raw' => $cred['credential_data']];
}

function tm_get_merged_credential_data(string $platform): ?array {
    $creds = tm_get_all_credentials($platform);
    if (!$creds) return null;
    $merged = [];
    foreach ($creds as $cred) {
        $decoded = json_decode($cred['credential_data'], true);
        if (is_array($decoded)) {
            $merged = array_merge($merged, $decoded);
        } else {
            $merged[$cred['credential_type']] = $cred['credential_data'];
        }
    }
    return $merged;
}

function tm_list_credentials(): array {
    $db = tm_get_db();
    $rows = $db->query("SELECT platform, credential_type, note, updated_at FROM credentials ORDER BY platform")->fetchAll();
    foreach ($rows as &$r) {
        if ($r['updated_at']) $r['updated_at'] = date('Y-m-d H:i:s', $r['updated_at']);
    }
    return $rows;
}

function tm_delete_credential(string $platform, ?string $cred_type=null): bool {
    $db = tm_get_db();
    if ($cred_type) {
        $stmt = $db->prepare("DELETE FROM credentials WHERE platform = ? AND credential_type = ?");
        $stmt->execute([$platform, $cred_type]);
    } else {
        $stmt = $db->prepare("DELETE FROM credentials WHERE platform = ?");
        $stmt->execute([$platform]);
    }
    // 同时清理该平台及子平台的 usage 数据和刷新日志
    $sub_map = [
        'tencent' => ['tencent_codingplan', 'tencent_hy_tokenplan', 'tencent_tokenplan'],
        'volcano' => ['volcano_codingplan', 'volcano_ark', 'volcano_balance'],
    ];
    $all = array_merge([$platform], $sub_map[$platform] ?? []);
    foreach ($all as $p) {
        $db->prepare("DELETE FROM platform_usage WHERE platform = ?")->execute([$p]);
        $db->prepare("DELETE FROM refresh_log WHERE platform = ?")->execute([$p]);
    }
    return $stmt->rowCount() > 0;
}

// ============ 刷新日志 ============

function tm_add_refresh_log(string $platform, string $status, string $message, int $duration_ms=0) {
    $db = tm_get_db();
    $stmt = $db->prepare("INSERT INTO refresh_log (timestamp, platform, status, message, duration_ms) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([time(), $platform, $status, $message, $duration_ms]);
    $db->exec("DELETE FROM refresh_log WHERE id NOT IN (SELECT id FROM refresh_log ORDER BY id DESC LIMIT 100)");
}

function tm_get_refresh_log(int $limit=30): array {
    $db = tm_get_db();
    $stmt = $db->prepare("SELECT * FROM refresh_log ORDER BY id DESC LIMIT ?");
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if ($r['timestamp']) $r['timestamp_fmt'] = date('Y-m-d H:i:s', $r['timestamp']);
    }
    return $rows;
}

// ============ 设置 ============

function tm_get_setting(string $key, string $default=''): string {
    $db = tm_get_db();
    $stmt = $db->prepare("SELECT value FROM refresh_settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function tm_set_setting(string $key, string $value) {
    $db = tm_get_db();
    $stmt = $db->prepare("INSERT OR REPLACE INTO refresh_settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

// ============ 排序权重 ============

function tm_get_sort_weights(): array {
    $db = tm_get_db();
    $rows = $db->query("SELECT key, value FROM refresh_settings WHERE key LIKE 'sort_weight_%'")->fetchAll();
    $weights = [];
    foreach ($rows as $row) {
        $platform = preg_replace('/^sort_weight_/', '', $row['key']);
        $weights[$platform] = intval($row['value']);
    }
    return $weights;
}

function tm_set_sort_weight(string $platform, int $weight) {
    tm_set_setting("sort_weight_{$platform}", strval($weight));
}

// ============ 隐藏子服务 ============

function tm_get_hidden_services(): array {
    $val = tm_get_setting('hidden_services', '[]');
    $decoded = json_decode($val, true);
    return is_array($decoded) ? $decoded : [];
}

function tm_hide_service(string $sub_platform) {
    $hidden = tm_get_hidden_services();
    if (!in_array($sub_platform, $hidden)) {
        $hidden[] = $sub_platform;
        tm_set_setting('hidden_services', json_encode($hidden, JSON_UNESCAPED_UNICODE));
    }
}

function tm_show_service(string $sub_platform) {
    $hidden = tm_get_hidden_services();
    $key = array_search($sub_platform, $hidden);
    if ($key !== false) {
        unset($hidden[$key]);
        tm_set_setting('hidden_services', json_encode(array_values($hidden), JSON_UNESCAPED_UNICODE));
    }
}

// ============ 管理员密码 ============

function tm_has_password(): bool {
    return !empty(tm_get_setting('admin_password_hash', ''));
}

function tm_get_password_hash(): string {
    return tm_get_setting('admin_password_hash', '');
}

function tm_set_password_hash(string $hash) {
    tm_set_setting('admin_password_hash', $hash);
}
