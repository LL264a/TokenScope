"""统计和公共 API 测试"""

import pytest


class TestPublicEndpoints:
    """公开端点"""

    def test_get_stats(self, client):
        resp = client.get("/api/stats")
        assert resp.status_code == 200
        data = resp.json()
        assert "platforms" in data
        assert "version" in data
        assert data["version"]  # 应该有版本号

    def test_get_scrape_status(self, client):
        resp = client.get("/api/scrape-status")
        assert resp.status_code == 200
        assert isinstance(resp.json(), list)

    def test_get_cookie_status(self, client):
        resp = client.get("/api/cookie-status")
        assert resp.status_code == 200
        assert isinstance(resp.json(), list)


class TestSortMode:
    """排序模式"""

    def test_get_default_sort_mode(self, client, auth_headers):
        resp = client.get("/api/admin/sort-mode", headers=auth_headers)
        assert resp.status_code == 200
        assert resp.json()["mode"] in ("realtime", "weight")

    def test_set_sort_mode(self, client, auth_headers):
        resp = client.post(
            "/api/admin/sort-mode",
            json={"mode": "realtime"},
            headers=auth_headers,
        )
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

        # 验证已保存
        resp = client.get("/api/admin/sort-mode", headers=auth_headers)
        assert resp.json()["mode"] == "realtime"

    def test_set_sort_mode_invalid(self, client, auth_headers):
        resp = client.post(
            "/api/admin/sort-mode",
            json={"mode": "random"},
            headers=auth_headers,
        )
        assert resp.status_code == 422  # Pydantic 验证失败


class TestSortWeights:
    """排序权重"""

    def test_set_and_get_weights(self, client, auth_headers):
        # 设置权重
        resp = client.post(
            "/api/admin/sort-weights",
            json={"tencent": 100, "deepseek": 50},
            headers=auth_headers,
        )
        assert resp.status_code == 200

        # 读取权重
        resp = client.get("/api/admin/sort-weights", headers=auth_headers)
        weights = resp.json()
        assert weights.get("tencent") == 100
        assert weights.get("deepseek") == 50


class TestHiddenServices:
    """隐藏服务"""

    def test_hide_and_show_service(self, client, auth_headers):
        # 隐藏
        resp = client.post(
            "/api/admin/hidden-services/hide",
            json={"sub_platform": "tencent_codingplan"},
            headers=auth_headers,
        )
        assert resp.status_code == 200

        # 验证已隐藏
        resp = client.get("/api/admin/hidden-services", headers=auth_headers)
        assert "tencent_codingplan" in resp.json()["hidden"]

        # 恢复
        resp = client.post(
            "/api/admin/hidden-services/show",
            json={"sub_platform": "tencent_codingplan"},
            headers=auth_headers,
        )
        assert resp.status_code == 200

        # 验证已恢复
        resp = client.get("/api/admin/hidden-services", headers=auth_headers)
        assert "tencent_codingplan" not in resp.json()["hidden"]

    def test_hide_without_subplatform(self, client, auth_headers):
        resp = client.post(
            "/api/admin/hidden-services/hide",
            json={},
            headers=auth_headers,
        )
        assert resp.status_code == 422


class TestRefreshLog:
    """刷新日志"""

    def test_refresh_log_empty(self, client, auth_headers):
        resp = client.get("/api/admin/refresh-log", headers=auth_headers)
        assert resp.status_code == 200
        assert resp.json() == []

    def test_refresh_log_with_limit(self, client, auth_headers):
        resp = client.get("/api/admin/refresh-log?limit=5", headers=auth_headers)
        assert resp.status_code == 200


class TestSchedulerAPI:
    """调度器 API"""

    def test_scheduler_status(self, client, auth_headers):
        resp = client.get("/api/admin/scheduler", headers=auth_headers)
        assert resp.status_code == 200
        data = resp.json()
        assert "running" in data

    def test_scheduler_set_interval(self, client, auth_headers):
        resp = client.post(
            "/api/admin/scheduler",
            json={"interval": 120},
            headers=auth_headers,
        )
        assert resp.status_code == 200

    def test_scheduler_invalid_action(self, client, auth_headers):
        resp = client.post(
            "/api/admin/scheduler",
            json={"action": "invalid_action"},
            headers=auth_headers,
        )
        assert resp.status_code == 422

    def test_scheduler_interval_out_of_range(self, client, auth_headers):
        resp = client.post(
            "/api/admin/scheduler",
            json={"interval": 5},
            headers=auth_headers,
        )
        assert resp.status_code == 422


class TestPlatformsEndpoint:
    """平台配置"""

    def test_platforms_list(self, client, auth_headers):
        resp = client.get("/api/admin/platforms", headers=auth_headers)
        assert resp.status_code == 200
        data = resp.json()
        assert "tencent" in data
        assert "volcano" in data
