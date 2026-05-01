"""SQLite 数据层 - 只管存取，不关心数据从哪来"""

import json
import time
import sqlite3
from typing import Optional
from config import DB_PATH


_conn: sqlite3.Connection | None = None

def get_conn() -> sqlite3.Connection:
    global _conn
    if _conn is not None:
        try:
            _conn.execute("SELECT 1")
        except sqlite3.ProgrammingError:
            _conn = None
    if _conn is None:
        _conn = sqlite3.connect(DB_PATH)
        _conn.row_factory = sqlite3.Row
    return _conn


# ============ 初始化 ============

def init_tables():
    """创建所有表（兼容旧数据库）"""
    conn = get_conn()
    conn.executescript("""
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
    """)
    conn.commit()

    # 迁移：将旧的 platform 单主键凭证表迁移为 (platform, credential_type) 复合主键
    _migrate_credentials_table(conn)


def _migrate_credentials_table(conn):
    """迁移凭证表：platform单主键 → (platform, credential_type)复合主键"""
    # 检查是否需要迁移
    try:
        cols = conn.execute("PRAGMA table_info(credentials)").fetchall()
        pk_cols = [c for c in cols if c[5] > 0]  # pk > 0 的列
        if len(pk_cols) == 2:
            return  # 已经是复合主键，无需迁移
    except Exception:
        # 表不存在或损坏，跳过迁移
        return

    # 迁移：创建新表、复制数据、替换
    try:
        old_data = conn.execute("SELECT platform, credential_type, credential_data, note, updated_at FROM credentials").fetchall()
        conn.execute("DROP TABLE credentials")
        conn.execute("""
            CREATE TABLE credentials (
                platform TEXT,
                credential_type TEXT DEFAULT 'cookie',
                credential_data TEXT NOT NULL,
                note TEXT DEFAULT '',
                updated_at REAL,
                PRIMARY KEY (platform, credential_type)
            )
        """)
        for row in old_data:
            conn.execute(
                "INSERT OR IGNORE INTO credentials (platform, credential_type, credential_data, note, updated_at) VALUES (?, ?, ?, ?, ?)",
                row
            )
        conn.commit()
        print("[DB] 凭证表迁移完成：platform单主键 → (platform, credential_type)复合主键")
    except Exception as e:
        print(f"[DB] 凭证表迁移失败: {e}")


# ============ 平台用量 ============

def save_usage(platform: str, total_tokens=0, input_tokens=0, output_tokens=0,
               cost=0.0, remaining="", raw=None, **kwargs):
    """保存平台用量数据。kwargs 中的额外字段合并到 raw_json。"""
    raw_data = raw or {}
    for k, v in kwargs.items():
        if k not in ("total_tokens", "input_tokens", "output_tokens",
                      "cost", "remaining", "plan_name"):
            raw_data[k] = v

    conn = get_conn()
    conn.execute("""
        INSERT INTO platform_usage (timestamp, platform, total_tokens,
            input_tokens, output_tokens, cost, remaining, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    """, (time.time(), platform, total_tokens, input_tokens,
          output_tokens, cost, remaining,
          json.dumps(raw_data, ensure_ascii=False)))
    conn.commit()


def get_latest_usage() -> list[dict]:
    """获取每个平台的最新一条数据"""
    conn = get_conn()
    rows = conn.execute("""
        SELECT platform, total_tokens, input_tokens, output_tokens,
               cost, remaining, timestamp, raw_json
        FROM platform_usage
        WHERE id IN (SELECT MAX(id) FROM platform_usage GROUP BY platform)
        ORDER BY platform
    """).fetchall()

    results = []
    for row in rows:
        item = dict(row)
        item["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime(row["timestamp"]))
        try:
            raw = json.loads(row["raw_json"]) if row["raw_json"] else {}
            for key in ("quotas", "plan_type", "plan_code", "remaining_days", "valid_from",
                        "valid_to", "plan_status", "remaining_pct", "daily_requests",
                        "balance_available", "balance_cash", "balance_credit",
                        "balance_frozen", "balance_arrears",
                        "balance", "gift_balance", "cash_balance", "frozen_balance",
                        "cache_tokens", "tpm", "rpm", "current_month_cost",
                        "month_used", "month_limit", "month_pct", "plan_pct",
                        "comp_total", "comp_used", "comp_pct", "auto_renew"):
                if key in raw:
                    item[key] = raw[key]
            if "quotas" in raw and "plan_type" not in raw:
                item["plan_type"] = "Coding Plan"
        except (json.JSONDecodeError, TypeError):
            pass
        results.append(item)
    return results


# ============ 凭证 ============

def save_credential(platform: str, cred_type: str, data: str, note: str = ""):
    conn = get_conn()
    conn.execute("""
        INSERT OR REPLACE INTO credentials (platform, credential_type, credential_data, note, updated_at)
        VALUES (?, ?, ?, ?, ?)
    """, (platform, cred_type, data, note, time.time()))
    conn.commit()


def get_credential(platform: str, cred_type: str = None) -> Optional[dict]:
    conn = get_conn()
    if cred_type:
        row = conn.execute("SELECT * FROM credentials WHERE platform = ? AND credential_type = ?", (platform, cred_type)).fetchone()
    else:
        row = conn.execute("SELECT * FROM credentials WHERE platform = ?", (platform,)).fetchone()
    return dict(row) if row else None


def get_all_credentials(platform: str) -> list[dict]:
    """获取指定平台的所有凭证"""
    conn = get_conn()
    rows = conn.execute("SELECT * FROM credentials WHERE platform = ?", (platform,)).fetchall()
    return [dict(r) for r in rows]


def get_credential_data(platform: str, cred_type: str = None) -> Optional[dict]:
    """获取凭证数据（解析JSON，失败则返回原始值）"""
    cred = get_credential(platform, cred_type)
    if not cred:
        return None
    try:
        return json.loads(cred["credential_data"])
    except (json.JSONDecodeError, TypeError):
        return {"raw": cred["credential_data"]}


def get_merged_credential_data(platform: str) -> Optional[dict]:
    """获取平台所有凭证并合并为一个dict（用于火山等需要多种凭证的平台）"""
    creds = get_all_credentials(platform)
    if not creds:
        return None
    merged = {}
    for cred in creds:
        try:
            data = json.loads(cred["credential_data"])
            if isinstance(data, dict):
                merged.update(data)
            else:
                # 非dict数据用credential_type作为key
                merged[cred["credential_type"]] = data
        except (json.JSONDecodeError, TypeError):
            merged[cred["credential_type"]] = cred["credential_data"]
    return merged


def list_credentials() -> list[dict]:
    """列出所有凭证（脱敏）"""
    conn = get_conn()
    rows = conn.execute(
        "SELECT platform, credential_type, note, updated_at FROM credentials ORDER BY platform"
    ).fetchall()
    results = []
    for row in rows:
        r = dict(row)
        if r["updated_at"]:
            r["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(r["updated_at"]))
        results.append(r)
    return results


def delete_credential(platform: str, cred_type: str = None) -> bool:
    conn = get_conn()
    if cred_type:
        cursor = conn.execute("DELETE FROM credentials WHERE platform = ? AND credential_type = ?", (platform, cred_type))
    else:
        cursor = conn.execute("DELETE FROM credentials WHERE platform = ?", (platform,))
    # 同时清理该平台关联的 usage 数据和刷新日志
    from config import TENCENT_SUB_TO_PARENT, VOLCANO_SUB_TO_PARENT
    sub_platforms = []
    for sub, parent in {**TENCENT_SUB_TO_PARENT, **VOLCANO_SUB_TO_PARENT}.items():
        if parent == platform:
            sub_platforms.append(sub)
    all_platforms = [platform] + sub_platforms
    for p in all_platforms:
        conn.execute("DELETE FROM platform_usage WHERE platform = ?", (p,))
        conn.execute("DELETE FROM refresh_log WHERE platform = ?", (p,))
    conn.commit()
    return cursor.rowcount > 0


# ============ 刷新日志 ============

def add_refresh_log(platform: str, status: str, message: str, duration_ms: int = 0):
    conn = get_conn()
    conn.execute("""
        INSERT INTO refresh_log (timestamp, platform, status, message, duration_ms)
        VALUES (?, ?, ?, ?, ?)
    """, (time.time(), platform, status, message, duration_ms))
    conn.execute("""
        DELETE FROM refresh_log WHERE id NOT IN (
            SELECT id FROM refresh_log ORDER BY id DESC LIMIT 100
        )
    """)
    conn.commit()


def get_refresh_log(limit: int = 30) -> list[dict]:
    conn = get_conn()
    rows = conn.execute("SELECT * FROM refresh_log ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
    results = []
    for row in rows:
        r = dict(row)
        if r["timestamp"]:
            r["timestamp_fmt"] = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(r["timestamp"]))
        results.append(r)
    return results


# ============ 设置 ============

def get_setting(key: str, default: str = "") -> str:
    conn = get_conn()
    row = conn.execute("SELECT value FROM refresh_settings WHERE key = ?", (key,)).fetchone()
    return row[0] if row else default


def set_setting(key: str, value: str):
    conn = get_conn()
    conn.execute("INSERT OR REPLACE INTO refresh_settings (key, value) VALUES (?, ?)", (key, value))
    conn.commit()


# ============ 平台排序权重 ============

def get_sort_weights() -> dict[str, int]:
    """获取所有平台的排序权重，返回 {platform: weight}，默认 0"""
    conn = get_conn()
    rows = conn.execute(
        "SELECT key, value FROM refresh_settings WHERE key LIKE 'sort_weight_%'"
    ).fetchall()
    weights = {}
    for row in rows:
        platform = row["key"].replace("sort_weight_", "", 1)
        try:
            weights[platform] = int(row["value"])
        except (ValueError, TypeError):
            weights[platform] = 0
    return weights


def set_sort_weight(platform: str, weight: int):
    """设置平台排序权重"""
    set_setting(f"sort_weight_{platform}", str(weight))


# ============ 隐藏子服务 ============

def get_hidden_services() -> list[str]:
    """获取已隐藏的子服务列表"""
    val = get_setting("hidden_services", "[]")
    try:
        return json.loads(val)
    except (json.JSONDecodeError, TypeError):
        return []


def set_hidden_services(services: list[str]):
    """设置已隐藏的子服务列表"""
    set_setting("hidden_services", json.dumps(services, ensure_ascii=False))


def hide_service(sub_platform: str):
    """隐藏一个子服务"""
    hidden = get_hidden_services()
    if sub_platform not in hidden:
        hidden.append(sub_platform)
        set_hidden_services(hidden)


def show_service(sub_platform: str):
    """恢复显示一个子服务"""
    hidden = get_hidden_services()
    if sub_platform in hidden:
        hidden.remove(sub_platform)
        set_hidden_services(hidden)


# ============ 管理员密码 ============

def get_admin_password_hash() -> str:
    """获取管理员密码哈希，未设置时返回空字符串"""
    return get_setting("admin_password_hash", "")


def set_admin_password_hash(password_hash: str):
    """设置管理员密码哈希"""
    set_setting("admin_password_hash", password_hash)


def has_admin_password() -> bool:
    """检查是否已设置管理员密码"""
    return bool(get_admin_password_hash())
