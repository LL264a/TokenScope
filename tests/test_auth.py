"""认证流程测试"""

import pytest


class TestAuthSetup:
    """首次设置密码 — 不依赖任何 auth 夹具"""

    def test_setup_creates_password(self, client):
        resp = client.post("/api/auth/setup", json={"password": "mypassword"})
        assert resp.status_code == 200, resp.text
        data = resp.json()
        assert data["status"] == "ok"
        assert "token" in data

    def test_setup_rejects_short_password(self, client):
        resp = client.post("/api/auth/setup", json={"password": "ab"})
        assert resp.status_code == 400, resp.text  # App 层校验（<4位）

    def test_setup_rejects_empty_password(self, client):
        resp = client.post("/api/auth/setup", json={"password": ""})
        assert resp.status_code == 422

    def test_cannot_setup_twice(self, client):
        r1 = client.post("/api/auth/setup", json={"password": "first123"})
        assert r1.status_code == 200
        r2 = client.post("/api/auth/setup", json={"password": "second456"})
        assert r2.status_code == 400
        assert "已设置" in r2.json()["detail"]


class TestAuthLogin:
    """登录流程 — 自行设置密码"""

    def test_login_success(self, client):
        client.post("/api/auth/setup", json={"password": "login1234"})
        resp = client.post("/api/auth/login", json={"password": "login1234"})
        assert resp.status_code == 200
        assert "token" in resp.json()

    def test_login_wrong_password(self, client):
        client.post("/api/auth/setup", json={"password": "login1234"})
        resp = client.post("/api/auth/login", json={"password": "wrongpass"})
        assert resp.status_code == 401

    def test_login_empty_password(self, client):
        client.post("/api/auth/setup", json={"password": "login1234"})
        resp = client.post("/api/auth/login", json={"password": ""})
        assert resp.status_code == 422

    def test_auth_status_shows_authenticated(self, client):
        client.post("/api/auth/setup", json={"password": "login1234"})
        login_resp = client.post("/api/auth/login", json={"password": "login1234"})
        token = login_resp.json()["token"]
        resp = client.get("/api/auth/status", headers={"Authorization": f"Bearer {token}"})
        assert resp.json()["authenticated"] is True

    def test_auth_status_unauthenticated(self, client):
        resp = client.get("/api/auth/status")
        assert resp.json()["authenticated"] is False


class TestAuthChangePassword:
    """修改密码"""

    def test_change_password_success(self, client, auth_headers):
        resp = client.post(
            "/api/auth/change-password",
            json={"old_password": "test1234", "new_password": "newpass456"},
            headers=auth_headers,
        )
        assert resp.status_code == 200

        # 新密码可登录
        r = client.post("/api/auth/login", json={"password": "newpass456"})
        assert r.status_code == 200

    def test_change_password_requires_auth(self, client):
        resp = client.post(
            "/api/auth/change-password",
            json={"old_password": "test1234", "new_password": "newpass456"},
        )
        assert resp.status_code == 401

    def test_change_password_wrong_old(self, client, auth_headers):
        resp = client.post(
            "/api/auth/change-password",
            json={"old_password": "wrong", "new_password": "newpass456"},
            headers=auth_headers,
        )
        assert resp.status_code == 401


class TestAuthLogout:
    """登出"""

    def test_logout(self, client, admin_token):
        resp = client.post(
            "/api/auth/logout",
            headers={"Authorization": f"Bearer {admin_token}"},
        )
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

        # Token 失效
        resp = client.get("/api/auth/status", headers={"Authorization": f"Bearer {admin_token}"})
        assert resp.json()["authenticated"] is False


class TestRateLimit:
    """登录限流"""

    def test_rate_limit_blocks_after_5_attempts(self, client):
        client.post("/api/auth/setup", json={"password": "realpass123"})
        for _ in range(5):
            resp = client.post("/api/auth/login", json={"password": "wrong"})
            assert resp.status_code in (401, 429)
        resp = client.post("/api/auth/login", json={"password": "wrong"})
        assert resp.status_code == 429
