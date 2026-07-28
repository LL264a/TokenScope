#!/usr/bin/env python3
"""服务器端无头浏览器采集器 — 腾讯云 CAPI 自采集
依赖: pip3 install playwright; python3 -m playwright install chromium

原理：从本地 DB 直接读 Cookie → Chromium 无头 → 自动采集 CAPI → push 到 receive.php

用法：
  python3 /root/tencent_auto_collect.py          # 单次采集
  python3 /root/tencent_auto_collect.py --daemon  # 持续运行（每5分钟）
"""
import json, urllib.request, time, sys, asyncio, sqlite3

SERVER = "https://ait.ll264a.cn"
TOKEN = "tm_2026_change_me"
DB_PATH = "/var/lib/token-monitor/data/token_monitor.db"


def load_cookies():
    """从本地 DB 直接读取腾讯云 Cookie"""
    try:
        conn = sqlite3.connect(DB_PATH)
        cur = conn.cursor()
        cur.execute("SELECT credential_data FROM credentials WHERE platform='tencent' AND credential_type='cookie'")
        row = cur.fetchone()
        conn.close()
        if not row or not row[0]:
            return None

        raw = row[0].strip()
        cookies = []
        for item in raw.split(";"):
            item = item.strip()
            if "=" in item:
                name, _, value = item.partition("=")
                name = name.strip()
                value = value.strip()
                if name:
                    cookies.append({
                        "name": name,
                        "value": value,
                        "domain": ".cloud.tencent.com",
                        "path": "/",
                        "secure": True,
                        "httpOnly": False,
                        "sameSite": "Lax",
                    })
        return cookies
    except Exception as e:
        print(f"[tencent_auto] ⚠️ 读取 Cookie 失败: {e}")
        return None


def push_capi(all_data):
    """通过 receive.php 推送 CAPI 数据"""
    payload = json.dumps({"type": "capi_data", "payload": {"all": all_data, "platform": "tencent"}}).encode()
    req = urllib.request.Request(
        f"{SERVER}/receive.php",
        data=payload,
        headers={"Content-Type": "application/json", "X-Token": TOKEN}
    )
    resp = urllib.request.urlopen(req, timeout=15).read().decode()
    return json.loads(resp)


def extract_response_body(d):
    """从 CAPI 响应中提取内部 Response 数据
    响应格式: {"code":0, "data":{"data":{"Response":{...}}}}
    """
    if not isinstance(d, dict) or d.get("code") != 0:
        return None
    data_root = d.get("data", {})
    if not isinstance(data_root, dict):
        return None
    # 可能有双层嵌套
    inner = data_root.get("data", data_root)
    if isinstance(inner, dict) and "Response" in inner:
        return inner["Response"]
    return None


async def collect_once():
    """单次采集"""
    print(f"[tencent_auto] [{time.strftime('%H:%M:%S')}] 开始采集...")

    cookies = load_cookies()
    if not cookies:
        print("[tencent_auto] ❌ 无 Cookie")
        return False

    from playwright.async_api import async_playwright

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-gpu",
                "--disable-dev-shm-usage",
                "--disable-setuid-sandbox",
            ]
        )
        context = await browser.new_context(
            viewport={"width": 1920, "height": 1080},
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            locale="zh-CN", timezone_id="Asia/Shanghai",
        )
        await context.add_cookies(cookies)

        page = await context.new_page()

        all_capi = []
        seen = set()

        async def on_response(resp):
            if "cgi/capi" not in resp.url:
                return
            try:
                # 用 body() 而非 text() — text() 在某些 Playwright 版本可能失败
                body = await resp.body()
                text = body.decode("utf-8", errors="replace")
                d = json.loads(text)
                inner = extract_response_body(d)
                if not inner:
                    return
                cmd = ""
                if "cmd=" in resp.url:
                    cmd = resp.url.split("cmd=")[-1].split("&")[0]
                if cmd not in ("DescribePkg",):
                    return
                if cmd in seen:
                    return
                seen.add(cmd)
                all_capi.append({"cmd": cmd, "data": inner, "url": resp.url})
                print(f"  📥 {cmd}")
            except Exception as e:
                pass

        page.on("response", on_response)

        url = "https://console.cloud.tencent.com/tokenhub/codingplan"
        print(f"  🌐 打开 codingplan...")
        try:
            await page.goto(url, wait_until="domcontentloaded", timeout=30000)
            # 等待页面加载完成后的 CAPI 响应
            await asyncio.sleep(10)
        except Exception as e:
            print(f"  ⚠️ goto: {e}")
            await asyncio.sleep(5)

        await browser.close()

        if all_capi:
            cmds = ", ".join(d["cmd"] for d in all_capi)
            print(f"  📤 推送 {len(all_capi)} API: {cmds}")
            try:
                resp = push_capi(all_capi)
                print(f"  ✅ {resp}")
            except Exception as e:
                print(f"  ❌ 推送失败: {e}")
            return True
        else:
            print("  ❌ 无 CAPI 数据")
            return False


async def daemon():
    """持续运行模式"""
    print("[tencent_auto] 🚀 启动守护模式 (每300秒)")
    while True:
        await collect_once()
        await asyncio.sleep(300)


if __name__ == "__main__":
    if "--daemon" in sys.argv:
        asyncio.run(daemon())
    else:
        asyncio.run(collect_once())
