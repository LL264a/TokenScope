#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
volcano_cookie_sync.py — 火山引擎 Cookie 一键同步到 TokenScope 服务器

痛点：火山方舟的控制台 Cookie 每天过期，只能靠人工从浏览器复制后粘贴到后台。
本脚本直接从本机浏览器读取 console.volcengine.com 的 Cookie（含 HttpOnly 的
csrfToken），通过 TokenScope 的内部 API Key 推送到服务器，免去每天手动复制粘贴。

鉴权：使用服务器生成的 X-Internal-Key（写入本仓库 sync_config.json，已被 .gitignore 忽略）。
      也可通过环境变量 TOKENSCOPE_INTERNAL_KEY 覆盖。

用法：
  # 自动从本机 Chrome/Edge 读取火山 Cookie 并推送（推荐，零复制）
  python volcano_cookie_sync.py

  # 推送后顺带触发一次采集刷新
  python volcano_cookie_sync.py --refresh

  # 只提取 Cookie 看看，不推送
  python volcano_cookie_sync.py --dry-run

  # 兜底：从文件读取（把浏览器复制的 Cookie 存成 volcano_cookie.txt）
  python volcano_cookie_sync.py --cookie-file volcano_cookie.txt

依赖：
  pip install browser_cookie3
  （Chrome 127+ 若启用了 App-Bound Encryption 导致读取失败，请用 --cookie-file 兜底）
"""

import argparse
import json
import os
import sys
import urllib.request
import urllib.error
import ssl

# ============ 配置 ============
SERVER = "https://ait.ll264a.cn"
PLATFORM = "volcano"
CRED_TYPE = "cookie"
COOKIE_DOMAINS = ("volcengine.com",)  # 匹配的域名后缀

# 内部 Key：优先环境变量，其次本地 sync_config.json（gitignore）
def load_internal_key():
    env = os.environ.get("TOKENSCOPE_INTERNAL_KEY")
    if env:
        return env.strip()
    here = os.path.dirname(os.path.abspath(__file__))
    cfg = os.path.join(here, "sync_config.json")
    if os.path.exists(cfg):
        try:
            with open(cfg, encoding="utf-8") as f:
                data = json.load(f)
            return data.get("KEY", "").strip()
        except Exception:
            pass
    return ""

INTERNAL_KEY = load_internal_key()

CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE


def http_json(method, path, body=None, headers=None):
    url = SERVER + path
    data = json.dumps(body).encode("utf-8") if body is not None else None
    h = {"Content-Type": "application/json", "X-Internal-Key": INTERNAL_KEY}
    if headers:
        h.update(headers)
    req = urllib.request.Request(url, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=30) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            payload = json.loads(e.read().decode("utf-8"))
        except Exception:
            payload = {"detail": e.reason}
        return e.code, payload


# ============ Cookie 来源 ============
def read_from_browser():
    """通过 browser_cookie3 读取本机浏览器中 volcengine.com 的 Cookie。"""
    try:
        import browser_cookie3
    except ImportError:
        print("[!] 未安装 browser_cookie3，请执行: pip install browser_cookie3")
        print("    或改用 --cookie-file 兜底模式。")
        return None

    loaders = [
        ("Chrome", browser_cookie3.chrome),
        ("Edge", browser_cookie3.edge),
        ("Brave", browser_cookie3.brave),
        ("Chromium", browser_cookie3.chromium),
    ]
    for name, loader in loaders:
        try:
            cj = loader(domain_name=".volcengine.com")
            cookies = [(c.name, c.value) for c in cj if c.name]
            if cookies:
                print(f"[+] 从 {name} 读取到 {len(cookies)} 个火山 Cookie")
                return cookies
        except Exception as e:
            print(f"[-] {name} 读取失败: {e}")
    return None


def read_from_file(path):
    """从文件读取 Cookie（支持 Netscape 或 header 格式），归一化为 name=value 列表。"""
    with open(path, encoding="utf-8", errors="replace") as f:
        raw = f.read().strip()
    cookies = []
    if raw.startswith("# Netscape") or "\t" in raw:
        for line in raw.splitlines():
            if line.startswith("#") or not line.strip():
                continue
            parts = line.split("\t")
            if len(parts) >= 7 and parts[5]:
                cookies.append((parts[5], parts[6]))
    else:
        for kv in raw.split(";"):
            kv = kv.strip()
            if "=" in kv:
                k, v = kv.split("=", 1)
                cookies.append((k.strip(), v.strip()))
    return cookies


def assemble_header(cookies):
    return "; ".join(f"{k}={v}" for k, v in cookies)


def main():
    ap = argparse.ArgumentParser(description="火山引擎 Cookie 一键同步到 TokenScope")
    ap.add_argument("--cookie-file", help="从文件读取 Cookie（兜底模式）")
    ap.add_argument("--refresh", action="store_true", help="推送后触发一次采集刷新")
    ap.add_argument("--dry-run", action="store_true", help="只提取不推送")
    args = ap.parse_args()

    if not INTERNAL_KEY:
        print("[!] 缺少内部 API Key。请设置环境变量 TOKENSCOPE_INTERNAL_KEY，或确保 sync_config.json 存在。")
        sys.exit(1)

    # 1. 取 Cookie
    if args.cookie_file:
        print(f"[*] 从文件读取: {args.cookie_file}")
        cookies = read_from_file(args.cookie_file)
    else:
        print("[*] 尝试从本机浏览器读取火山 Cookie ...")
        cookies = read_from_browser()

    if not cookies:
        print("[!] 未获取到任何火山 Cookie。")
        print("    请确认：1) 本机浏览器已登录 console.volcengine.com；2) 今天访问过该站点。")
        print("    或改用 --cookie-file 把复制的 Cookie 存成文件后重试。")
        sys.exit(1)

    header = assemble_header(cookies)
    print(f"[+] Cookie 字符串长度: {len(header)}")
    # 打印键名（不打印值），便于确认关键字段齐全
    keys = [k for k, _ in cookies]
    print(f"[+] 包含键: {', '.join(keys[:15])}{' ...' if len(keys) > 15 else ''}")
    if "csrfToken" not in keys:
        print("[!] 警告: 未包含 csrfToken（HttpOnly）。火山 Coding/Agent Plan 查询可能失败。")

    if args.dry_run:
        print("[*] dry-run 模式，未推送。")
        return

    # 2. 推送
    status, resp = http_json("POST", "/api/admin/credentials", {
        "platform": PLATFORM,
        "credential_type": CRED_TYPE,
        "credential_data": header,
        "note": "auto-sync from local browser",
    })
    if status != 200:
        print(f"[!] 推送失败 HTTP {status}: {resp}")
        sys.exit(1)
    print(f"[+] 推送成功: {resp}")

    # 3. 可选刷新
    if args.refresh:
        s2, r2 = http_json("POST", f"/api/admin/refresh/{PLATFORM}")
        print(f"[+] 触发刷新 HTTP {s2}: {r2}")


if __name__ == "__main__":
    main()
