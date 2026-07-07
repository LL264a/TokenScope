"""从Chrome CDP获取多平台Cookie并更新数据库凭证

⚠️ 此脚本依赖 Playwright 和本地 Chrome，仅在本地 Windows 环境使用。
服务器部署时无需安装 Playwright，通过 Web UI 手动粘贴 Cookie 即可。
"""
import asyncio, json, sys, re
sys.stdout.reconfigure(encoding='utf-8')
from db import save_credential, get_credential_data

try:
    from playwright.async_api import async_playwright
    _HAS_PLAYWRIGHT = True
except ImportError:
    _HAS_PLAYWRIGHT = False


# 域名映射
COOKIE_DOMAINS = {
    'tencent': ['.cloud.tencent.com', '.tencent.com'],
    'volcano': ['.volcengine.com'],
    'xiaomi': ['.xiaomimimo.com'],
    'deepseek': ['.deepseek.com'],
}

# DeepSeek: 需要从 localStorage 提取 userToken
DEEPSEEK_LS_KEYS = ['userToken']


async def fetch_cookies(platforms=None):
    if not _HAS_PLAYWRIGHT:
        print("[ERROR] Playwright 未安装，无法使用 CDP 自动获取 Cookie")
        print("  解决方案: pip install playwright && playwright install chromium")
        print("  或通过 Web UI 管理页面手动粘贴 Cookie")
        return

    if platforms is None:
        platforms = list(COOKIE_DOMAINS.keys())

    async with async_playwright() as p:
        browser = await p.chromium.connect_over_cdp("http://localhost:9223")
        context = browser.contexts[0]

        # 获取所有 Cookie
        all_cookies = await context.cookies()
        print(f"Chrome中共有 {len(all_cookies)} 个Cookie")

        for platform in platforms:
            domains = COOKIE_DOMAINS.get(platform, [])
            if not domains:
                print(f"未知平台: {platform}")
                continue

            # 过滤该平台相关 Cookie
            platform_cookies = [c for c in all_cookies
                               if any(d in c.get('domain', '') for d in domains)]

            if not platform_cookies:
                print(f"\n{platform}: 未找到Cookie（可能未登录）")
                continue

            # 构建 Cookie 字符串
            cookie_str = '; '.join(f"{c['name']}={c['value']}" for c in platform_cookies)
            print(f"\n{platform}: 找到 {len(platform_cookies)} 个Cookie, 总长度={len(cookie_str)}")

            # 平台特殊处理
            cred_data = {"cookie": cookie_str}

            if platform == 'tencent':
                uin = ''
                ownerUin = ''
                for c in platform_cookies:
                    if c['name'] == 'uin':
                        uin = c['value'].lstrip('oO')
                    elif c['name'] == 'ownerUin':
                        nums = re.findall(r'\d+', c['value'])
                        ownerUin = nums[0] if nums else ''
                if not ownerUin:
                    ownerUin = uin
                csrfCode = '940711892'
                cred_data.update({"uin": uin, "ownerUin": ownerUin, "csrfCode": csrfCode})
                print(f"  uin={uin}, ownerUin={ownerUin}")

            elif platform == 'volcano':
                # 提取 csrfToken（Coding Plan API 需要 X-CSRF-Token header）
                csrf_token = ''
                for c in platform_cookies:
                    if c['name'] == 'csrfToken':
                        csrf_token = c['value']
                if csrf_token:
                    cred_data["csrfToken"] = csrf_token
                    print(f"  csrfToken={csrf_token}")
                else:
                    print(f"  ⚠️ 未找到csrfToken，Coding Plan查询可能失败")

            # 保存到数据库
            cred_json = json.dumps(cred_data, ensure_ascii=False)
            note = f"CDP自动获取 cookie_len={len(cookie_str)}"
            save_credential(platform, 'cookie', cred_json, note)
            print(f"  已保存到数据库")

        # DeepSeek: 额外提取 localStorage 中的 userToken
        if 'deepseek' in platforms:
            try:
                pages = context.pages
                ds_page = None
                # 查找已打开的 DeepSeek 页面，或新开一个
                for page in pages:
                    if 'deepseek.com' in page.url:
                        ds_page = page
                        break

                if not ds_page:
                    # 没有已打开的页面，新开标签
                    ds_page = await context.new_page()
                    await ds_page.goto('https://platform.deepseek.com/usage', wait_until='domcontentloaded', timeout=15000)
                    await ds_page.wait_for_timeout(3000)

                # 提取 localStorage
                user_token = await ds_page.evaluate("() => localStorage.getItem('userToken')")
                if user_token:
                    cred_data = {"token": user_token}
                    cred_json = json.dumps(cred_data, ensure_ascii=False)
                    save_credential('deepseek', 'token', cred_json, f"CDP自动获取 userToken len={len(user_token)}")
                    print(f"\ndeepseek: userToken已提取并保存 (len={len(user_token)})")
                else:
                    print(f"\ndeepseek: localStorage中未找到userToken（可能未登录）")

            except Exception as e:
                print(f"\ndeepseek: 提取userToken失败: {e}")

        await browser.close()


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('platforms', nargs='*', default=None,
                       help='要获取Cookie的平台(空=全部): tencent volcano xiaomi deepseek')
    args = parser.parse_args()

    asyncio.run(fetch_cookies(args.platforms or None))
