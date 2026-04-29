"""认证模块 - 简单 Token 认证 + 登录限流"""

import secrets
import hashlib
import time

# ============ 密码哈希 ============

_SALT = "token_monitor_2026_production_salt"
_ITERATIONS = 200_000  # PBKDF2 迭代次数


def hash_password(password: str) -> str:
    """对密码进行 PBKDF2-SHA256 哈希"""
    dk = hashlib.pbkdf2_hmac('sha256', password.encode('utf-8'), _SALT.encode('utf-8'), _ITERATIONS)
    return dk.hex()


def verify_password(password: str, password_hash: str) -> bool:
    """验证密码（恒定时间比较，防止计时攻击）"""
    computed = hash_password(password)
    return secrets.compare_digest(computed, password_hash)


# ============ 会话管理 ============

# 内存中的会话存储: {token: expiry_timestamp}
_sessions: dict[str, float] = {}

# 会话有效期
SESSION_EXPIRY = 86400  # 24小时


def create_session() -> str:
    """创建新会话，返回 Bearer token"""
    token = secrets.token_hex(32)
    _sessions[token] = time.time() + SESSION_EXPIRY
    # 顺便清理过期会话
    _cleanup_sessions()
    return token


def validate_session(token: str) -> bool:
    """验证会话是否有效"""
    if token not in _sessions:
        return False
    if _sessions[token] < time.time():
        del _sessions[token]
        return False
    return True


def destroy_session(token: str):
    """销毁会话"""
    _sessions.pop(token, None)


def destroy_all_sessions_except(token: str):
    """销毁除指定 token 外的所有会话（用于修改密码后踢出其他设备）"""
    to_remove = [t for t in _sessions if t != token]
    for t in to_remove:
        del _sessions[t]


def _cleanup_sessions():
    """清理过期会话"""
    now = time.time()
    expired = [t for t, exp in _sessions.items() if exp < now]
    for t in expired:
        del _sessions[t]


# ============ 登录限流 ============

# IP -> [登录时间戳列表]
_login_attempts: dict[str, list[float]] = {}
MAX_LOGIN_ATTEMPTS = 5      # 最大尝试次数
LOGIN_WINDOW = 300           # 窗口期（秒）= 5分钟


def check_login_rate(ip: str) -> bool:
    """检查登录频率，返回 True 表示允许登录"""
    now = time.time()
    if ip not in _login_attempts:
        _login_attempts[ip] = []

    # 清理窗口外的记录
    _login_attempts[ip] = [t for t in _login_attempts[ip] if now - t < LOGIN_WINDOW]

    if len(_login_attempts[ip]) >= MAX_LOGIN_ATTEMPTS:
        return False

    _login_attempts[ip].append(now)
    return True


def get_login_remaining_attempts(ip: str) -> int:
    """获取剩余登录尝试次数"""
    now = time.time()
    if ip not in _login_attempts:
        return MAX_LOGIN_ATTEMPTS
    attempts = [t for t in _login_attempts[ip] if now - t < LOGIN_WINDOW]
    return max(0, MAX_LOGIN_ATTEMPTS - len(attempts))


# ============ 内部 API Key（服务间调用） ============

# 内部密钥，用于 push_to_server.py 等内部服务间调用
# 不走 Bearer Token 认证，而是 X-Internal-Key 头
# 首次启动时自动生成，存入数据库
_INTERNAL_KEY_SETTING = "internal_api_key"


def get_internal_key() -> str:
    """获取内部 API Key，不存在则生成一个"""
    from db import get_setting, set_setting
    key = get_setting(_INTERNAL_KEY_SETTING)
    if not key:
        key = secrets.token_hex(16)  # 32字符
        set_setting(_INTERNAL_KEY_SETTING, key)
    return key


def verify_internal_key(key: str) -> bool:
    """验证内部 API Key"""
    expected = get_internal_key()
    return secrets.compare_digest(key, expected)
