import json, sqlite3, time, traceback
from playwright.sync_api import sync_playwright

CRED = "/root/.volcano_cred.json"
DB = "/data/wwwroot/ait.ll264a.cn/data/token_monitor.db"
URL = "https://console.volcengine.com/auth/login"

def to_netscape(cookies):
    lines = ["# Netscape HTTP Cookie File"]
    for c in cookies:
        domain = c.get("domain", "")
        flag = "TRUE" if domain.startswith(".") else "FALSE"
        path = c.get("path", "/") or "/"
        secure = "TRUE" if c.get("secure") else "FALSE"
        exp = c.get("expires")
        if not exp or exp <= 0:
            exp = 4102444800  # 2100-01-01, session cookie -> far future
        name = c.get("name", "")
        value = c.get("value", "")
        lines.append("\t".join([domain, flag, path, secure, str(int(exp)), name, value]))
    return "\n".join(lines) + "\n"

def visible(page, sel):
    try:
        el = page.query_selector(sel)
        return el is not None and el.is_visible()
    except Exception:
        return False

def main():
    cred = json.load(open(CRED))
    user, pwd = cred["user"], cred["pass"]
    log = {}
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="chrome", headless=True,
                                    args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"])
        ctx = browser.new_context(user_agent=("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                                              "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"))
        page = ctx.new_page()
        page.goto(URL, wait_until="domcontentloaded", timeout=30000)
        page.wait_for_timeout(4000)
        page.fill("#Identity_input", user, timeout=8000)
        page.fill("#Password_input", pwd, timeout=8000)
        try:
            page.get_by_text("登录", exact=True).first.click(timeout=4000)
        except Exception:
            page.locator("button[type='submit']").first.click(timeout=3000)
        page.wait_for_timeout(8000)
        log["url_after"] = page.url
        log["mfa_visible"] = visible(page, "#mfaCode_input")
        log["verify_visible"] = visible(page, "#VerificatonCodeInput")
        cookies = ctx.cookies()
        browser.close()
    if "console.volcengine.com/home" not in log["url_after"]:
        log["ERROR"] = "login did not reach home; url=" + log["url_after"]
        print(json.dumps(log, ensure_ascii=False, indent=2), flush=True)
        return
    netscape = to_netscape(cookies)
    json.dump(cookies, open("/tmp/volcano_cookies.json", "w"))
    # 写库 (UPSERT)
    con = sqlite3.connect(DB)
    con.execute("INSERT OR REPLACE INTO credentials (platform, credential_type, credential_data, note, updated_at) VALUES (?,?,?,?,?)",
                ("volcano", "cookie", netscape, "auto-headless", int(time.time())))
    con.commit()
    con.close()
    log["cookie_count"] = len(cookies)
    log["has_csrf"] = any(c["name"] == "csrfToken" for c in cookies)
    log["saved_to_db"] = True
    print(json.dumps(log, ensure_ascii=False, indent=2), flush=True)

if __name__ == "__main__":
    try:
        main()
    except Exception:
        traceback.print_exc()
