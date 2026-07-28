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
            duration_ms INTEGER DEFAULT 0,
            level TEXT DEFAULT 'info'
        );
        CREATE TABLE IF NOT EXISTS refresh_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");
    // 自动迁移：旧表可能缺少 level 列
    try {
        $db->exec("ALTER TABLE refresh_log ADD COLUMN level TEXT DEFAULT 'info'");
    } catch (PDOException $e) {
        // 列已存在，忽略
    }
}

// ============ 平台用量 ============

function tm_save_usage(string $platform, int $total_tokens=0, int $input_tokens=0, int $output_tokens=0,
                       float $cost=0.0, string $remaining='', array $raw=[], $ts=null) {
    // 跳过隐藏平台（不在前端显示的也不写入DB）
    $hidden = tm_get_hidden_services();
    if (in_array($platform, $hidden)) return;
    $db = tm_get_db();
    $ts = $ts ? intval($ts) : time();
    try {
        $stmt = $db->prepare("INSERT INTO platform_usage (timestamp, platform, total_tokens, input_tokens, output_tokens, cost, remaining, raw_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$ts, $platform, $total_tokens, $input_tokens, $output_tokens, $cost, $remaining, json_encode($raw, JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) {
        error_log("tm_save_usage: " . $e->getMessage());
    }
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
        'month_used','month_limit','month_pct','plan_pct','comp_total','comp_used','comp_pct','auto_renew','plan_name',
        'cost_total','monthly_cost','model_usages','granted_balance','topped_up_balance','daily_counts',
        'afp_quotas','afp_cost','afp_plan_type','afp_remaining_days','afp_valid_to','unit',
        'account_name','remaining_credits','total_requests'];

    foreach ($rows as $row) {
        $item = $row;
        $item['last_updated'] = date('Y-m-d\TH:i:s', (int)$row['timestamp']);
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

// ============ 数据保留 / 清理 ============

/** 当前库文件体积（含 -wal / -shm 附属文件），单位字节 */
function tm_db_size_bytes(): int {
    if (!file_exists(TM_DB_PATH)) return 0;
    $s = filesize(TM_DB_PATH);
    foreach (['-wal', '-shm'] as $suf) {
        if (file_exists(TM_DB_PATH . $suf)) $s += filesize(TM_DB_PATH . $suf);
    }
    return $s;
}

/**
 * 清理超过保留期的历史用量数据，并 VACUUM 回收空间。
 * platform_usage 仅前端取最新一条，历史行全部冗余；refresh_log 保留 30 天用于排查。
 * @param int|null $days 保留天数，null 时取 TM_DATA_RETENTION_DAYS
 * @return array {retention_days, platform_usage_removed, refresh_log_removed, freed_mb, db_size_mb}
 */
function tm_prune_old_usage(?int $days = null): array {
    $days = $days !== null ? max(1, intval($days)) : TM_DATA_RETENTION_DAYS;
    $cutoff = time() - $days * 86400;
    $db = tm_get_db();

    $before = tm_db_size_bytes();

    // 安全网：即使某平台数据已超保留期，也永远保留其最新一条快照，
    // 避免采集中断(如凭证丢失/进程挂掉)超过保留期后看板被彻底清空。
    $pu = $db->prepare(
        "DELETE FROM platform_usage
         WHERE timestamp < ?
           AND (platform, timestamp) NOT IN (
             SELECT platform, MAX(timestamp) FROM platform_usage GROUP BY platform
           )"
    );
    $pu->execute([$cutoff]);
    $removed_pu = $pu->rowCount();

    $log_cutoff = time() - 30 * 86400; // refresh_log 保留更久，便于排查失败
    $rl = $db->prepare("DELETE FROM refresh_log WHERE timestamp < ?");
    $rl->execute([$log_cutoff]);
    $removed_rl = $rl->rowCount();

    // 回收空闲页（WAL 模式下 VACUUM 会重写库文件并释放磁盘空间）
    try {
        $db->exec('VACUUM');
    } catch (PDOException $e) {
        error_log('tm_prune_old_usage VACUUM: ' . $e->getMessage());
    }

    $after = tm_db_size_bytes();
    $freed = max(0, $before - $after);

    return [
        'retention_days'           => $days,
        'platform_usage_removed'   => $removed_pu,
        'refresh_log_removed'      => $removed_rl,
        'freed_mb'                 => round($freed / 1048576, 2),
        'db_size_mb'               => round($after / 1048576, 2),
    ];
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
        // token 类型不做 JSON 展开，保持原值
        if ($cred['credential_type'] === 'token') {
            $merged['token'] = $cred['credential_data'];
            continue;
        }
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
        if ($r['updated_at']) $r['updated_at'] = date('Y-m-d H:i:s', (int)$r['updated_at']);
    }
    unset($r);
    return $rows;
}

function tm_delete_credential(string $platform, ?string $cred_type=null): bool {
    $db = tm_get_db();
    if (!$cred_type) {
        // 安全：不指定类型时只返回 false，防止误删所有类型（如 api_data）
        error_log("tm_delete_credential: cred_type is null for $platform, denied");
        return false;
    }
    $stmt = $db->prepare("DELETE FROM credentials WHERE platform = ? AND credential_type = ?");
    $stmt->execute([$platform, $cred_type]);
    // 不级联删除 usage 数据（防止误操作丢失历史快照）
    return $stmt->rowCount() > 0;
}

// ============ 刷新日志 ============

function tm_add_refresh_log(string $platform, string $status, string $message, int $duration_ms=0) {
    $db = tm_get_db();
    $level = match($status) {
        'success' => 'INFO',
        'failed' => 'WARN',
        'error' => 'ERROR',
        default => 'INFO',
    };
    $stmt = $db->prepare("INSERT INTO refresh_log (timestamp, platform, status, message, duration_ms, level) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([time(), $platform, $status, $message, $duration_ms, $level]);
    $db->exec("DELETE FROM refresh_log WHERE id NOT IN (SELECT id FROM refresh_log ORDER BY id DESC LIMIT 1000)");
}

// 取某平台"当前仍然有效"的采集失败错误信息（用于前端失败卡展示真实原因）
// 关键：仅当最近一次失败的时间晚于最近一次成功时，该错误才算"当前有效"。
// 否则（失败后已成功采集）返回 null，避免 cookie 更新成功后仍残留"已失效"提示。
// volcano/tencent 的错误记录在子服务 key 下（如 volcano_codingplan），需一并匹配；
// 其余平台（含 minimax 与兄弟平台 minimax_gateway）仅精确匹配，避免误报
function tm_get_last_error(string $platform): ?array {
    $db = tm_get_db();
    $subParents = ['volcano', 'tencent'];
    if (in_array($platform, $subParents, true)) {
        $pattern = $platform . '_%';
        $stmt = $db->prepare(
            "SELECT message, timestamp FROM refresh_log
             WHERE (platform = ? OR platform LIKE ?) AND status = 'failed'
               AND timestamp > (SELECT COALESCE(MAX(timestamp), 0) FROM refresh_log WHERE (platform = ? OR platform LIKE ?) AND status = 'success')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$platform, $pattern, $platform, $pattern]);
    } else {
        $stmt = $db->prepare(
            "SELECT message, timestamp FROM refresh_log
             WHERE platform = ? AND status = 'failed'
               AND timestamp > (SELECT COALESCE(MAX(timestamp), 0) FROM refresh_log WHERE platform = ? AND status = 'success')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$platform, $platform]);
    }
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['message' => $row['message'], 'at' => date('Y-m-d H:i', (int)$row['timestamp'])];
}

function tm_get_refresh_log(int $limit=50, ?string $level=null, ?string $platform=null, int $offset=0, ?string $search=null): array {
    $db = tm_get_db();
    $sql = "SELECT * FROM refresh_log WHERE 1=1";
    $params = [];
    if ($level) { $sql .= " AND level=?"; $params[] = $level; }
    if ($platform) { $sql .= " AND platform=?"; $params[] = $platform; }
    if ($search) { $sql .= " AND message LIKE ?"; $params[] = "%{$search}%"; }
    $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if ($r['timestamp']) $r['timestamp_fmt'] = date('Y-m-d H:i:s', (int)$r['timestamp']);
    }
    unset($r);
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

// ============ api_data 保鲜检查 ============

function tm_check_api_data_age(string $platform): array {
    $cred = tm_get_credential($platform, 'api_data');
    if (!$cred || empty($cred['updated_at'])) {
        return ['status' => 'missing', 'message' => '无 api_data 数据', 'hours' => null];
    }
    $updated = floatval($cred['updated_at']);
    $now = time();
    $hours = ($now - $updated) / 3600;
    if ($hours > 24) {
        return ['status' => 'critical', 'message' => sprintf('api_data 已过期 %.1f 小时（超过24h）', $hours), 'hours' => $hours];
    }
    if ($hours > 12) {
        return ['status' => 'stale', 'message' => sprintf('api_data 已过期 %.1f 小时（超过12h）', $hours), 'hours' => $hours];
    }
    return ['status' => 'fresh', 'message' => sprintf('api_data 新鲜（%.1f 小时）', $hours), 'hours' => $hours];
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

// ============ API 密钥（TOTP 两步验证） ============

function tm_init_api_keys_table() {
    $db = tm_get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        secret TEXT NOT NULL,
        last_used INTEGER DEFAULT 0,
        last_totp INTEGER DEFAULT 0,
        created_at INTEGER DEFAULT (strftime('%s','now')),
        revoked INTEGER DEFAULT 0
    )");
}

function tm_create_api_key(string $name): array {
    $db = tm_get_db();
    tm_init_api_keys_table();
    $random = random_bytes(20);
    $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $secret = "";
    for ($i = 0; $i < 20; $i++) {
        $secret .= $chars[ord($random[$i]) & 31];
    }
    $formatted = chunk_split($secret, 4, " ");
    $db->prepare("INSERT INTO api_keys (name, secret) VALUES (?, ?)")->execute([$name, $secret]);
    $id = $db->lastInsertId();
    return ["id" => $id, "name" => $name, "secret" => $secret, "formatted" => trim($formatted)];
}

function tm_list_api_keys(): array {
    $db = tm_get_db();
    tm_init_api_keys_table();
    $rows = $db->query("SELECT id, name, last_used, created_at, revoked FROM api_keys ORDER BY id")->fetchAll();
    foreach ($rows as &$r) {
        if ($r["last_used"]) $r["last_used_fmt"] = date("Y-m-d H:i:s", (int)$r["last_used"]);
        $r["created_at_fmt"] = date("Y-m-d H:i:s", (int)$r["created_at"]);
    }
    return $rows;
}

function tm_revoke_api_key(int $id): bool {
    $db = tm_get_db();
    tm_init_api_keys_table();
    $stmt = $db->prepare("UPDATE api_keys SET revoked=1 WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function tm_verify_totp(string $secret, string $totp_str): bool {
    $secret = str_replace(" ", "", strtoupper(trim($secret)));
    $totp_str = trim($totp_str);
    if (!preg_match("/^\d{6}$/", $totp_str)) return false;

    $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bits = "";
    for ($i = 0; $i < strlen($secret); $i++) {
        $pos = strpos($chars, $secret[$i]);
        if ($pos === false) return false;
        $bits .= str_pad(decbin($pos), 5, "0", STR_PAD_LEFT);
    }
    $bytes = "";
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $byte = substr($bits, $i, 8);
        if (strlen($byte) < 8) break;
        $bytes .= chr(bindec($byte));
    }

    $window = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $counter = $window + $offset;
        $high = ($counter >> 32) & 0xFFFFFFFF;
        $low = $counter & 0xFFFFFFFF;
        $packed = pack("NN", $high, $low);  // big-endian 64bit per RFC 6238
        $hmac = hash_hmac("sha1", $packed, $bytes, true);
        $offset4 = ord($hmac[19]) & 0xf;
        $code = ((ord($hmac[$offset4]) & 0x7f) << 24)
              | ((ord($hmac[$offset4+1]) & 0xff) << 16)
              | ((ord($hmac[$offset4+2]) & 0xff) << 8)
              | (ord($hmac[$offset4+3]) & 0xff);
        if (str_pad($code % 1000000, 6, "0", STR_PAD_LEFT) === $totp_str) {
            return true;
        }
    }
    return false;
}

function tm_verify_api_totp(): ?string {
    $key_name = $_SERVER["HTTP_X_TOTP_KEY"] ?? "";
    $totp_code = $_SERVER["HTTP_X_TOTP_CODE"] ?? "";
    if ($key_name === "" || $totp_code === "") return null;

    $db = tm_get_db();
    tm_init_api_keys_table();
    $stmt = $db->prepare("SELECT id, secret, last_totp, revoked FROM api_keys WHERE name=? AND revoked=0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$key_name]);
    $row = $stmt->fetch();
    if (!$row || $row["revoked"]) return null;

    if (!tm_verify_totp($row["secret"], $totp_code)) return null;

    $current_window = intdiv(time(), 30);
    $db->prepare("UPDATE api_keys SET last_used=?, last_totp=? WHERE id=?")->execute([time(), $current_window, $row["id"]]);
    return $key_name;
}
