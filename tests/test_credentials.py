"""凭证管理测试"""

import pytest


class TestCredentialCRUD:
    """凭证增删查"""

    def test_save_credential(self, client, auth_headers):
        resp = client.post(
            "/api/admin/credentials",
            json={
                "platform": "tencent",
                "credential_type": "cookie",
                "credential_data": "test_cookie=abc123",
                "note": "test note",
            },
            headers=auth_headers,
        )
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

    def test_save_credential_requires_auth(self, client):
        resp = client.post(
            "/api/admin/credentials",
            json={"platform": "tencent", "credential_data": "test"},
        )
        assert resp.status_code == 401

    def test_save_credential_rejects_invalid_platform(self, client, auth_headers):
        resp = client.post(
            "/api/admin/credentials",
            json={"platform": "nonexistent", "credential_data": "test"},
            headers=auth_headers,
        )
        assert resp.status_code == 422  # Pydantic 验证

    def test_save_credential_rejects_empty_data(self, client, auth_headers):
        resp = client.post(
            "/api/admin/credentials",
            json={"platform": "tencent", "credential_data": ""},
            headers=auth_headers,
        )
        assert resp.status_code == 400  # 凭证数据不能为空

    def test_list_credentials(self, client, auth_headers):
        # 先保存
        client.post(
            "/api/admin/credentials",
            json={"platform": "tencent", "credential_data": "cookie1"},
            headers=auth_headers,
        )
        client.post(
            "/api/admin/credentials",
            json={"platform": "deepseek", "credential_data": "sk-abc123"},
            headers=auth_headers,
        )

        resp = client.get("/api/admin/credentials", headers=auth_headers)
        assert resp.status_code == 200
        creds = resp.json()
        assert len(creds) == 2
        platforms = [c["platform"] for c in creds]
        assert "tencent" in platforms
        assert "deepseek" in platforms
        # 凭证数据不应该暴露在列表接口
        assert "credential_data" not in creds[0]

    def test_get_single_credential(self, client, auth_headers):
        client.post(
            "/api/admin/credentials",
            json={"platform": "tencent", "credential_data": "my_secret_cookie"},
            headers=auth_headers,
        )

        resp = client.get("/api/admin/credentials/tencent", headers=auth_headers)
        assert resp.status_code == 200
        data = resp.json()
        assert data["platform"] == "tencent"
        # 数据应该脱敏
        assert "credential_data_masked" in data

    def test_get_nonexistent_credential(self, client, auth_headers):
        resp = client.get("/api/admin/credentials/nonexistent", headers=auth_headers)
        assert resp.status_code == 404

    def test_delete_credential(self, client, auth_headers):
        client.post(
            "/api/admin/credentials",
            json={"platform": "xiaomi", "credential_data": "mi_cookie"},
            headers=auth_headers,
        )

        resp = client.delete("/api/admin/credentials/xiaomi", headers=auth_headers)
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

        # 确认已删除
        resp = client.get("/api/admin/credentials/xiaomi", headers=auth_headers)
        assert resp.status_code == 404

    def test_delete_nonexistent_credential(self, client, auth_headers):
        resp = client.delete("/api/admin/credentials/ghost", headers=auth_headers)
        assert resp.status_code == 404
