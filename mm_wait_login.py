from playwright.sync_api import sync_playwright
import time, json, urllib.request, ssl

SERVER = 'https://ait.ll264a.cn/receive.php'
TOKEN = 'tm_2026_change_me'
PLATFORM = 'minimax'

def log(*a):
    print('[MM]', *a, flush=True)

# 反自动化检测：抹掉 webdriver 等特征，尽量让腾讯验证码当真人浏览器处理
INIT_JS = """
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
try { window.navigator.chrome = { runtime: { onConnect: undefined, onMessage: undefined } }; } catch(e){}
Object.defineProperty(navigator, 'plugins', { get: () => [1,2,3,4,5] });
Object.defineProperty(navigator, 'languages', { get: () => ['zh-CN','zh','en'] });
"""

def push_cookie(cookie_str):
    payload = json.dumps({"type": "cookie_push", "payload": {"platform": PLATFORM, "cookie": cookie_str}}).encode()
    req = urllib.request.Request(SERVER, data=payload, headers={
        'Content-Type': 'application/json', 'X-Token': TOKEN, 'Host': 'ait.ll264a.cn'})
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with urllib.request.urlopen(req, context=ctx, timeout=20) as r:
        return r.read().decode()

with sync_playwright() as p:
    browser = p.chromium.launch(
        headless=False, channel='chrome',
        args=['--disable-blink-features=AutomationControlled', '--no-sandbox', '--disable-infobars'])
    ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
    ctx.add_init_script(INIT_JS)
    page = ctx.new_page()
    log('打开登录页（不自动操作，请自行登录）...')
    # 用控制台页作为入口：它会自动带上正确的 redirect_uri 跳去登录页（直接敲 unified-login 会报“缺少重定向URL”）
    page.goto('https://platform.minimaxi.com/console/usage', wait_until='domcontentloaded', timeout=30000)
    log('>> 浏览器窗口已打开，请自行：点「密码登录」→ 填账号密码 → 点立即登录 → 拖拼图 <<')
    log('>> 我在这里监控，你一登录成功我就自动推送 cookie <<')
    page.wait_for_timeout(2500)
    log('当前页面 URL:', page.url)

    deadline = time.time() + 900  # 最多等 15 分钟
    while time.time() < deadline:
        try:
            cookies = ctx.cookies()
        except Exception:
            break
        sid = next((c for c in cookies if c['name'] == '_sid'), None)
        tok = next((c for c in cookies if c['name'] == '_token'), None)
        if sid and tok:
            log('检测到登录态 cookie，开始推送...')
            mm = [c for c in cookies if 'minimaxi' in c.get('domain', '')]
            cookie_str = '; '.join(f"{c['name']}={c['value']}" for c in mm)
            try:
                resp = push_cookie(cookie_str)
                log('PUSH RESPONSE:', resp)
            except Exception as e:
                log('PUSH ERROR:', repr(e))
                with open('e:/Learn/WorkBuddy/Token/mm_cookie_dump.txt', 'w', encoding='utf-8') as f:
                    f.write(cookie_str)
                log('已把 cookie 存到 mm_cookie_dump.txt 备用')
            break
        page.wait_for_timeout(3000)

    log('监控结束，关闭浏览器')
    browser.close()
    log('DONE')
