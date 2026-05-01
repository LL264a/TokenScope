"""
Token Monitor - 数据推送到服务器
从本地 FastAPI 获取数据，推送到服务器 receive.php

用法:
  手动推送:   python push_to_server.py
  定时推送:   python push_to_server.py --daemon 300   (每300秒推送一次)
"""

import sys
import time
import json
import argparse
import urllib.request
import urllib.error

# ============ 配置 ============
LOCAL_API = "http://localhost:8765"
SERVER_URL = "https://ait.ll264a.cn/receive.php"
TOKEN = "tm_2026_change_me"  # 必须和 receive.php 中一致


def _get_internal_key() -> str:
    """从数据库获取内部 API Key"""
    # 导入项目模块（需要能找到 db.py）
    from db import get_setting
    return get_setting("internal_api_key", "")


def _make_request(url: str, headers: dict = None, data: bytes = None, method: str = "GET", timeout: int = 10):
    """创建 HTTP 请求，自动附加内部 API Key"""
    if headers is None:
        headers = {}
    # 对本地 API 请求自动附加 X-Internal-Key（用于认证）
    if url.startswith(LOCAL_API):
        key = _get_internal_key()
        if key:
            headers["X-Internal-Key"] = key
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    return urllib.request.urlopen(req, timeout=timeout)


def fetch_local(path: str) -> dict | None:
    """从本地 FastAPI 获取数据"""
    try:
        url = f"{LOCAL_API}{path}"
        with _make_request(url) as resp:
            return json.loads(resp.read())
    except Exception as e:
        print(f"[ERROR] 获取 {path} 失败: {e}")
        return None


def push_to_server(data: dict) -> bool:
    """推送数据到服务器"""
    try:
        payload = json.dumps(data, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            SERVER_URL,
            data=payload,
            headers={
                "Content-Type": "application/json",
                "X-Token": TOKEN,
            },
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=15) as resp:
            result = json.loads(resp.read())
            if result.get("status") == "ok":
                print(f"[OK] 推送成功: {result.get('type', '?')} ({result.get('size', 0)} bytes)")
                return True
            else:
                print(f"[FAIL] 服务器返回: {result}")
                return False
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        print(f"[FAIL] HTTP {e.code}: {body}")
        return False
    except Exception as e:
        print(f"[FAIL] 推送失败: {e}")
        return False


def do_push():
    """执行一次完整推送"""
    print(f"\n--- {time.strftime('%Y-%m-%d %H:%M:%S')} 推送开始 ---")

    # 先刷新本地数据
    try:
        with _make_request(f"{LOCAL_API}/api/admin/refresh", method="POST", timeout=30) as resp:
            result = json.loads(resp.read())
            if result.get("status") == "ok":
                ok_count = sum(1 for v in result.get("results", {}).values() if v.get("status") == "success")
                total = len(result.get("results", {}))
                print(f"[INFO] 本地刷新: {ok_count}/{total} 成功")
            else:
                print(f"[WARN] 本地刷新异常: {result}")
    except Exception as e:
        print(f"[WARN] 本地刷新失败: {e}")

    # 推送 stats
    stats = fetch_local("/api/stats")
    if stats:
        push_to_server({"type": "stats", "payload": stats})

    # 推送 cookie_status
    cookie_status = fetch_local("/api/cookie-status")
    if cookie_status:
        push_to_server({"type": "cookie_status", "payload": cookie_status})


def main():
    parser = argparse.ArgumentParser(description="Token Monitor 数据推送")
    parser.add_argument("--daemon", type=int, metavar="SECONDS",
                        help="守护模式，每 N 秒推送一次")
    parser.add_argument("--url", type=str, help="覆盖服务器 URL")
    parser.add_argument("--token", type=str, help="覆盖推送令牌")
    args = parser.parse_args()

    if args.url:
        global SERVER_URL
        SERVER_URL = args.url
    if args.token:
        global TOKEN
        TOKEN = args.token

    if args.daemon:
        interval = max(args.daemon, 60)
        print(f"守护模式: 每 {interval} 秒推送一次 (Ctrl+C 退出)")
        while True:
            try:
                do_push()
            except KeyboardInterrupt:
                print("\n退出")
                break
            except Exception as e:
                print(f"[ERROR] {e}")
            time.sleep(interval)
    else:
        do_push()


if __name__ == "__main__":
    main()
