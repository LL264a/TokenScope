// TokenScope Chrome 插件 - 自动检测 + 自动填登录 + 自动采集推送
const API_BASE = 'https://ait.ll264a.cn';

(async () => {
  // ============ 从服务器拉取平台列表 ============
  let platforms;
  try {
    const resp = await fetch(API_BASE + '/api/platforms');
    platforms = await resp.json();
  } catch (e) {
    platforms = [
      { id: 'tencent', name: '腾讯云', url: 'https://console.cloud.tencent.com/tokenhub/codingplan', domains: ['.cloud.tencent.com', '.tencent.com'] },
      { id: 'volcano', name: '火山引擎', url: 'https://console.volcengine.com/ark/region:ark+cn-beijing/plan', domains: ['.volcengine.com'] },
      { id: 'deepseek', name: 'DeepSeek', url: 'https://platform.deepseek.com/usage', domains: ['.deepseek.com'] },
      { id: 'xiaomi', name: '小米 MIMO', url: 'https://platform.xiaomimimo.com/console/plan-manage', domains: ['.xiaomimimo.com'] },
    ];
  }

  // ============ 匹配当前页面域名 ============
  const hostname = window.location.hostname;
  let matched = null;
  for (const p of platforms) {
    for (const domain of p.domains) {
      const clean = domain.startsWith('.') ? domain.slice(1) : domain;
      if (hostname === clean || hostname.endsWith('.' + clean)) {
        matched = { id: p.id, domain: domain };
        break;
      }
    }
    if (matched) break;
  }
  if (!matched) return;

  const platformId = matched.id;
  const cookieDomain = matched.domain;

  // ============ 浮窗 ============
  function showBadge(text, permanent, style) {
    let badge = document.getElementById('tokenscope-badge');
    if (!badge) {
      badge = document.createElement('div');
      badge.id = 'tokenscope-badge';
      Object.assign(badge.style, {
        position: 'fixed', top: '12px', right: '12px', zIndex: '2147483647',
        background: '#0f172a', padding: '10px 16px', borderRadius: '8px',
        fontSize: '13px', fontWeight: '600',
        fontFamily: '-apple-system, BlinkMacSystemFont, sans-serif',
        border: '1px solid #334155',
        boxShadow: '0 4px 12px rgba(0,0,0,0.4)',
      });
      document.body.appendChild(badge);
    }
    badge.textContent = text || '';
    if (style?.borderColor) badge.style.borderColor = style.borderColor;
    if (style?.color) badge.style.color = style.color;
    badge.style.display = 'block';
    if (!permanent) setTimeout(() => badge.style.display = 'none', 8000);
  }

  // ============ 采集 + 推送 ============
  async function collectAndPush() {
    showBadge('✅ 采集中...', true, { borderColor: '#059669', color: '#4ade80' });
    await new Promise(r => setTimeout(r, 3000));

    chrome.runtime.sendMessage({ action: 'collect', platform: platformId, domain: cookieDomain }, async (collectResp) => {
      if (collectResp.status === 'ok' && collectResp.netscape) {
        const pushResp = await chrome.runtime.sendMessage({ action: 'push', platform: platformId, netscape: collectResp.netscape });
        if (pushResp.success) {
          showBadge('✅ ' + collectResp.count + ' Cookie 已推送', true, { borderColor: '#059669', color: '#4ade80' });
          chrome.runtime.sendMessage({ action: 'clear_login_cred', platform: platformId });
        } else {
          showBadge('❌ 推送失败: ' + (pushResp.error || ''), true, { borderColor: '#b91c1c', color: '#ef4444' });
        }
      } else {
        showBadge('❌ 采集失败', true, { borderColor: '#b91c1c', color: '#ef4444' });
      }
    });
  }

  // ============ 先检查是否已登录 ============
  chrome.runtime.sendMessage({ action: 'count_cookies', domain: cookieDomain }, async (resp) => {
    const cookieCount = resp?.count || 0;

    // 已登录 → 直接采集
    if (cookieCount > 3) {
      showBadge('🔭 已登录, 采集中...', true, { borderColor: '#059669', color: '#4ade80' });
      await collectAndPush();
      return;
    }

    // 未登录 → 尝试自动填
    chrome.runtime.sendMessage({ action: 'get_login_cred', platform: platformId }, async (saved) => {
      if (!saved || !saved.password) {
        showBadge('⏳ 请登录', true, { borderColor: '#d97706', color: '#f59e0b' });
        waitForLogin();
        return;
      }

      const { account, password } = saved;
      showBadge('🔭 自动填登录...');
      await new Promise(r => setTimeout(r, 2000));

      // 找登录表单
      const pwField = document.querySelector('input[type="password"]');
      if (!pwField) {
        chrome.runtime.sendMessage({ action: 'count_cookies', domain: cookieDomain }, (r2) => {
          if (r2?.count > 3) collectAndPush();
          else { showBadge('⚠️ 未检测到登录表单'); waitForLogin(); }
        });
        return;
      }

      // 找账号框
      let acctField = null;
      for (const inp of document.querySelectorAll('input:not([type="hidden"]):not([type="password"])')) {
        const t = (inp.type || 'text').toLowerCase();
        if (['text', 'email', 'tel', 'number'].includes(t)) { acctField = inp; break; }
      }

      // 找登录按钮
      let submitBtn = null;
      const forms = pwField.closest('form');
      if (forms) {
        submitBtn = forms.querySelector('button[type="submit"], button.btn-primary, button:not([type="button"])');
        if (!submitBtn) forms.querySelectorAll('button').forEach(b => { if (b.textContent.includes('登录') || b.textContent.includes('Sign')) submitBtn = b; });
      }
      if (!submitBtn) document.querySelectorAll('button').forEach(b => { if (b.textContent.includes('登录') || b.textContent.includes('Sign')) submitBtn = b; });

      // 填账号
      if (account && acctField) {
        acctField.value = account;
        acctField.dispatchEvent(new Event('input', { bubbles: true }));
        acctField.dispatchEvent(new Event('change', { bubbles: true }));
      }

      // 填密码
      pwField.value = password;
      pwField.dispatchEvent(new Event('input', { bubbles: true }));
      pwField.dispatchEvent(new Event('change', { bubbles: true }));

      showBadge('🔐 已填入, 请处理验证码', false, { borderColor: '#059669', color: '#4ade80' });

      // 自动点登录
      if (submitBtn) {
        setTimeout(() => {
          submitBtn.click();
          showBadge('👆 已点击登录', true, { borderColor: '#059669', color: '#4ade80' });
        }, 800);
      } else {
        showBadge('✋ 请手动点击登录', true, { borderColor: '#d97706', color: '#f59e0b' });
      }

      waitForLogin();
    });
  });

  // ============ 轮询登录 ============
  let checkInterval = null;
  const startTime = Date.now();
  const TIMEOUT = 10 * 60 * 1000;
  let initialCookies = 0;

  function waitForLogin() {
    chrome.runtime.sendMessage({ action: 'count_cookies', domain: cookieDomain }, (resp) => {
      initialCookies = resp?.count || 0;
    });
    setTimeout(() => {
      checkInterval = setInterval(checkLoop, 2000);
      checkLoop();
    }, 5000);
  }

  function checkLoop() {
    if (Date.now() - startTime > TIMEOUT) {
      showBadge('⏰ 10分钟超时', true, { borderColor: '#b91c1c', color: '#ef4444' });
      if (checkInterval) clearInterval(checkInterval);
      return;
    }
    chrome.runtime.sendMessage({ action: 'count_cookies', domain: cookieDomain }, async (resp) => {
      const n = resp?.count || 0;
      const urlOk = !/login|sign/i.test(window.location.href);
      if (n > initialCookies + 2 || (n > 3 && urlOk)) {
        if (checkInterval) clearInterval(checkInterval);
        await collectAndPush();
      }
    });
  }
})();
