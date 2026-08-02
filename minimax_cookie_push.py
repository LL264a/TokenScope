"""
MiniMax 官方 Cookie 手动推送 (Windows)

背景:
  MiniMax 官方登录有腾讯拼图验证码, 无头浏览器无法自动过 (验证码 iframe 被
  移到屏幕外 + opacity:0, 三层防线把视觉/拼图/手柄/ticket 全锁在真人交互状态机)。
  所以走 "cookie 复用" 路线:
    1) 你在本机浏览器手动登录 platform.minimaxi.com (自己过验证码)
    2) 复制 Cookie (最省事: 整条 cURL 命令)
    3) 跑本脚本推送到服务器 -> 服务器自动复用, 直到 cookie 过期

怎么拿 Cookie (必须含 _sid / _token 这两个 HttpOnly 会话 cookie):
  [最省事] DevTools -> Network -> 任意 platform.minimaxi.com 请求 ->
           右键 "Copy as cURL (bash)" -> 整条命令贴来即可 (脚本会自动提取 cookie)。
  [或] DevTools -> Network -> 同请求 -> 右键 "Copy as cURL" 后只取 -b 后面那段。
  [或] DevTools -> Application -> Cookies -> 复制全部 (需保证 _sid/_token 在内)。

用法:
  python minimax_cookie_push.py --curl "curl 'https://...' -H ... -b '_sid=...; _token=...' ..."
  python minimax_cookie_push.py --curl-file mm_curl.txt   # 整条 cURL 存文件
  python minimax_cookie_push.py --cookie "_sid=...; _token=...; ..."   # 直接传 cookie 串
  python minimax_cookie_push.py                 # 读同目录 mm_cookie.txt
  python minimax_cookie_push.py --file path.txt # 指定纯 cookie 文件

推送成功后, 服务器会在下一个采集周期 (约 1 分钟) 自动复用, 无需每天操作。
cookie 过期后 (看板 MiniMax 卡报错/变灰) 再重新走一遍即可。
"""

import sys
import re
import json
import argparse
import urllib.request
import urllib.error

SERVER_URL = "https://ait.ll264a.cn/receive.php"
TOKEN = "tm_2026_change_me"
PLATFORM = "minimax"


def extract_cookie_from_curl(text: str) -> str:
    """从整条 cURL 命令中提取 cookie 字符串。支持 -b/--cookie 及 -H 'Cookie: ...'。"""
    # 去掉续行符 (cURL 有时用 \ 换行)
    text = text.replace("\\\n", " ").replace("\\\r\n", " ")
    # -b '...' / --cookie "..." / -b name=val
    m = re.search(
        r"(?:--cookie|-b)\s+(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"|(\S+))",
        text,
    )
    if m:
        val = m.group(1) or m.group(2) or m.group(3) or ""
        if val.strip():
            return val.strip()
    # -H 'Cookie: ...' / -H "Cookie: ..."
    m = re.search(r"-H\s+['\"](?:Cookie|cookie):\s*([^'\"]*)['\"]", text)
    if m:
        return m.group(1).strip()
    return ""


def clean_cookie(raw: str) -> str:
    """去掉续行符/首尾引号/多余空白, 压成单行。"""
    raw = raw.replace("\\\n", " ").replace("\\\r\n", " ")
    raw = raw.strip().strip('"').strip("'")
    raw = " ".join(raw.split())
    return raw


def main():
    ap = argparse.ArgumentParser(description="MiniMax 官方 Cookie 手动推送")
    ap.add_argument("--curl", help="整条 cURL 命令 (自动提取 cookie)")
    ap.add_argument("--curl-file", help="存整条 cURL 命令的文件")
    ap.add_argument("--cookie", help="直接传 cookie 字符串")
    ap.add_argument("--url", default=SERVER_URL, help="覆盖服务器 URL")
    ap.add_argument("--token", default=TOKEN, help="覆盖推送令牌")
    ap.add_argument("--file", default="mm_cookie.txt", help="纯 cookie 文件路径")
    args = ap.parse_args()

    cookie = None
    src = None
    if args.curl:
        cookie = extract_cookie_from_curl(args.curl)
        src = "--curl"
    if (not cookie) and args.curl_file:
        try:
            with open(args.curl_file, encoding="utf-8") as f:
                cookie = extract_cookie_from_curl(f.read())
            src = "--curl-file"
        except FileNotFoundError:
            print(f"[FAIL] --curl-file 指定的文件不存在: {args.curl_file}")
            sys.exit(2)
    if (not cookie) and args.cookie:
        cookie = args.cookie
        src = "--cookie"
    if not cookie:
        try:
            with open(args.file, encoding="utf-8") as f:
                cookie = f.read()
            src = args.file
        except FileNotFoundError:
            print(f"[FAIL] 未提供 cookie 来源, 且 {args.file} 不存在。")
            print("       用法见文件顶部 docstring。")
            sys.exit(2)

    cookie = clean_cookie(cookie)
    if not cookie:
        print(f"[FAIL] 从 {src} 未解析到任何 cookie")
        sys.exit(2)

    if "_sid" not in cookie or "_token" not in cookie:
        print("[WARN] cookie 中未发现 _sid/_token (HttpOnly 会话 cookie)。")
        print("       后端会拒绝 (401)。请用 DevTools 复制包含 _sid/_token 的完整 cookie。")
        print("       仍要继续推送? (Ctrl+C 取消)")

    payload = json.dumps(
        {"type": "cookie_push", "payload": {"platform": PLATFORM, "cookie": cookie}},
        ensure_ascii=False,
    ).encode("utf-8")

    req = urllib.request.Request(
        args.url,
        data=payload,
        headers={"Content-Type": "application/json", "X-Token": args.token},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            res = json.loads(resp.read())
    except urllib.error.HTTPError as e:
        print(f"[FAIL] HTTP {e.code}: {e.read().decode('utf-8', errors='replace')}")
        sys.exit(1)
    except Exception as e:
        print(f"[FAIL] 推送失败: {e}")
        sys.exit(1)

    if res.get("status") == "ok":
        print(f"[OK] 推送成功: platform={res.get('platform')} cookie_len={res.get('cookie_len')}")
        print("     服务器将在下一个采集周期自动复用此 cookie (约 1 分钟内生效)。")
        print("     想立即生效可在看板点「刷新」, 或等 cron 自动触发。")
    else:
        print(f"[FAIL] 服务器返回: {res}")
        sys.exit(1)


if __name__ == "__main__":
    main()
