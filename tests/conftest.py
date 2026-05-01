"""测试夹具 — 内存 SQLite + FastAPI TestClient"""

import os
import sys
import tempfile
import atexit
import pytest

# Ensure project root in path
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


@pytest.fixture(scope="function")
def temp_db(monkeypatch):
    """每个测试函数独立使用临时 SQLite 数据库"""
    import config
    import db
    import auth

    tmp_dir = tempfile.mkdtemp(prefix="tokentest_")
    tmp_path = os.path.join(tmp_dir, "test.db")

    # 替换 config 和 db 模块的 DB_PATH（解决模块级 import 绑定）
    monkeypatch.setattr(config, "DB_PATH", type(config.DB_PATH)(tmp_path))
    monkeypatch.setattr(db, "DB_PATH", type(db.DB_PATH)(tmp_path))

    # 重置全局状态
    db._conn = None
    auth._sessions.clear()
    auth._login_attempts.clear()

    from db import init_tables
    init_tables()

    yield

    db._conn = None
    try:
        os.unlink(tmp_path)
        os.rmdir(tmp_dir)
    except OSError:
        pass


@pytest.fixture(scope="function")
def client(temp_db):
    """FastAPI TestClient，用临时 DB"""
    from fastapi.testclient import TestClient
    from main import app as fastapi_app
    with TestClient(fastapi_app) as tc:
        yield tc


@pytest.fixture(scope="function")
def setup_password(client):
    """先设置初始密码，返回密码字符串"""
    resp = client.post("/api/auth/setup", json={"password": "test1234"})
    assert resp.status_code == 200, f"Setup failed: {resp.status_code} {resp.text}"
    return "test1234"


@pytest.fixture(scope="function")
def admin_token(client, setup_password):
    """登录并获取 Bearer token"""
    resp = client.post("/api/auth/login", json={"password": setup_password})
    assert resp.status_code == 200, f"Login failed: {resp.status_code} {resp.text}"
    return resp.json()["token"]


@pytest.fixture(scope="function")
def auth_headers(admin_token):
    """已认证的请求头"""
    return {"Authorization": f"Bearer {admin_token}"}
