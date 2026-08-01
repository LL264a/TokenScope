// TokenScope Chrome 插件 - 弹出窗口逻辑
const API_BASE = 'https://ait.ll264a.cn';
let PLATFORMS = [];
let credPlatforms = {};
let accounts = {};
let totpKeyName = '';
let totpSecret = '';
const PLATFORM_COLORS = ['#3b82f6', '#f59e0b', '#6366f1', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

const keyInput = document.getElementById('api-key-input');
const saveKeyBtn = document.getElementById('save-key-btn');
const changeKeyBtn = document.getElementById('change-key-btn');
const clearKeyBtn = document.getElementById('clear-key-btn');
const keyStatusEl = document.getElementById('key-status');
const platformsEl = document.getElementById('platforms');
const launchAllBtn = document.getElementById('launch-all');
const syncAllBtn = document.getElementById('sync-all');
const statusText = document.getElementById('status-text');
const statusAuth = document.getElementById('status-auth');
const toastEl = document.getElementById('toast');
const logPanel = document.getElementById('log-panel');
const toggleLog = document.getElementById('toggle-log');
let toastTimer = null;

// 平台 → Cookie 域名映射（只有 cookie 类平台才有，API key 类平台不在此列）
const COOKIE_DOMAINS = {
  volcano: '.volcengine.com',
  xiaomi: '.xiaomimimo.com',
  deepseek: '.deepseek.com',
  minimax: '.minimaxi.com',
  tencent: '.cloud.tencent.com',  // background.js 内部会特殊处理腾讯多域名
};

const logs = [];
function log(type, msg) {
  const time = new Date().toLocaleTimeString();
  logs.push({ time, type, msg });
  if (logPanel) logPanel.innerHTML = logs.slice(-30).map(e =>
    '<div class="log-entry"><span class="log-time">[' + e.time + ']</span><span class="log-' + e.type + '">' + escHtml(e.msg) + '</span></div>'
  ).join('');
  chrome.storage.session.set({ plugin_logs: logs.slice(-50) });
}
function escHtml(s) { if (!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function toast(msg, type) {
  toastEl.textContent = msg;
  toastEl.className = 'toast ' + (type || '') + ' show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.className = 'toast', 3000);
  log(type || 'info', msg);
}

// ============ TOTP API（走 background 计算，传密钥参数） ============
async function totpApi(method, path, body) {
  if (!totpSecret) return { error: '未配置密钥' };
  try {
    const respTotp = await chrome.runtime.sendMessage({ action: 'generate_totp', secret: totpSecret, keyName: totpKeyName });
    if (respTotp.error) return respTotp;
    const headers = { 'Content-Type': 'application/json', 'X-TOTP-Key': totpKeyName, 'X-TOTP-Code': respTotp.code };
    const resp = await fetch(API_BASE + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
    if (resp.status === 401) return { error: '密钥无效或已吊销' };
    const data = await resp.json();
    if (!resp.ok) return { error: data.detail || data.error || 'HTTP ' + resp.status };
    return data;
  } catch (e) {
    return { error: '网络错误: ' + e.message };
  }
}

// ============ 密钥管理 ============
async function loadTotpKey() {
  const result = await chrome.storage.local.get(['totpKeyName', 'totpSecret']);
  totpKeyName = result.totpKeyName || '';
  totpSecret = result.totpSecret || '';
  if (totpSecret) {
    keyStatusEl.textContent = '✅ 密钥 (' + totpKeyName + ')';
    keyStatusEl.style.color = '#4ade80';
    keyInput.style.display = 'none';
    saveKeyBtn.style.display = 'none';
    changeKeyBtn.style.display = 'inline-block';
    clearKeyBtn.style.display = 'inline-block';
    statusAuth.textContent = '✅ 已认证';
    statusAuth.className = 'logged-in';
    log('ok', '密钥已加载: ' + totpKeyName);
    return true;
  }
  changeKeyBtn.style.display = 'none';
  clearKeyBtn.style.display = 'none';
  return false;
}

async function saveTotpKey() {
  const val = keyInput.value.trim();
  if (!val) { toast('请粘贴 API 密钥', 'error'); return; }
  let name = 'default';
  let secret = val;
  if (val.includes('|')) {
    name = val.split('|')[0].trim();
    secret = val.split('|')[1].trim();
    secret = secret.replace(/\s/g, '');
  }
  // 先设内存变量 + 存 storage
  totpKeyName = name;
  totpSecret = secret;
  await chrome.storage.local.set({ totpKeyName: name, totpSecret: secret });

  // 验证：调服务器
  const testResp = await totpApi('GET', '/api/stats');
  if (testResp.error) {
    toast('密钥无效: ' + testResp.error, 'error');
    log('err', '密钥验证失败: ' + testResp.error);
    // 清除
    await chrome.storage.local.remove(['totpKeyName', 'totpSecret']);
    totpKeyName = '';
    totpSecret = '';
    return;
  }
  await loadTotpKey();
  toast('密钥已保存，验证通过', 'success');
  log('ok', '密钥验证通过');
  await loadData();
}

async function clearTotpKey() {
  await chrome.storage.local.remove(['totpKeyName', 'totpSecret']);
  totpKeyName = ''; totpSecret = '';
  keyStatusEl.textContent = '未配置'; keyStatusEl.style.color = '#94a3b8';
  keyInput.style.display = 'block'; keyInput.value = '';
  saveKeyBtn.style.display = 'inline-block'; changeKeyBtn.style.display = 'none'; clearKeyBtn.style.display = 'none';
  statusAuth.textContent = '未认证'; statusAuth.className = '';
  launchAllBtn.disabled = true; launchAllBtn.textContent = '⚡ 一键打开全部平台';
  platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">请先配置 API 密钥</div>';
  toast('密钥已清除', 'success');
}

async function changeKeyMode() {
  keyInput.style.display = 'block';
  keyInput.value = totpKeyName + '|' + totpSecret;
  changeKeyBtn.style.display = 'none'; clearKeyBtn.style.display = 'none';
  saveKeyBtn.style.display = 'inline-block';
  keyInput.focus();
}

// ============ 加载数据 ============
async function loadData() {
  if (!totpSecret) return;
  platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">加载中...</div>';
  log('info', '拉取平台数据...');

  try {
    const resp = await fetch(API_BASE + '/api/platforms');
    PLATFORMS = await resp.json();
  } catch (e) {
    PLATFORMS = [];
    log('err', '平台列表失败: ' + e.message);
  }

  const credResp = await totpApi('GET', '/api/admin/credentials');
  credPlatforms = {};
  if (credResp && !credResp.error) {
    credResp.forEach(c => { credPlatforms[c.platform] = c; });
    log('ok', '有 Cookie 的平台: ' + Object.keys(credPlatforms).join(', '));
  } else {
    const errMsg = credResp?.error || '';
    log('err', '凭证获取: ' + errMsg);
    if (errMsg.includes('吊销') || errMsg.includes('无效')) {
      toast('密钥已失效，清除旧密钥', 'error');
      await chrome.storage.local.remove(['totpKeyName', 'totpSecret']);
      totpKeyName = ''; totpSecret = '';
      keyStatusEl.textContent = '❌ 密钥已失效'; keyStatusEl.style.color = '#ef4444';
      keyInput.style.display = 'block'; saveKeyBtn.style.display = 'inline-block';
      changeKeyBtn.style.display = 'none'; clearKeyBtn.style.display = 'none';
      statusAuth.textContent = '未认证'; statusAuth.className = '';
      platformsEl.innerHTML = '<div style="text-align:center;color:#ef4444;padding:20px;font-size:13px">密钥已失效，请重新生成</div>';
      launchAllBtn.disabled = true;
      return;
    }
  }

  // 第一次打开：无凭证自动隐藏，有凭证自动取消隐藏
  if (!window._autoHidden && Object.keys(credPlatforms).length > 0) {
    window._autoHidden = true;
    let changed = false;
    for (const p of PLATFORMS) {
      const hasCred = credPlatforms[p.id] !== undefined;
      if (hasCred && hiddenPlatforms.includes(p.id)) {
        hiddenPlatforms = hiddenPlatforms.filter(h => h !== p.id);
        changed = true;
      }
      if (!hasCred && !hiddenPlatforms.includes(p.id)) {
        hiddenPlatforms.push(p.id);
        changed = true;
      }
    }
    if (changed) saveHidden();
  }

  renderPlatforms();
}

// ============ UI ============
let hiddenPlatforms = [];

async function loadHidden() {
  const r = await chrome.storage.local.get('hidden_platforms');
  hiddenPlatforms = r.hidden_platforms || [];
}

async function saveHidden() {
  await chrome.storage.local.set({ hidden_platforms: hiddenPlatforms });
}

function renderPlatforms() {
  platformsEl.innerHTML = '';
  const allPlatforms = PLATFORMS;

  if (allPlatforms.length === 0) {
    platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">暂无已采集平台</div>';
    launchAllBtn.disabled = true; return;
  }

  // 先显示可见的平台
  let visiblePlatforms = allPlatforms.filter(p => !hiddenPlatforms.includes(p.id));
  let hiddenPlatformsList = allPlatforms.filter(p => hiddenPlatforms.includes(p.id));

  if (visiblePlatforms.length === 0) {
    platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">所有平台已隐藏，点击下方显示</div>';
  }

  visiblePlatforms.forEach((p, idx) => {
    const cred = credPlatforms[p.id];
    const saved = accounts[p.id] || {};
    const color = PLATFORM_COLORS[idx % PLATFORM_COLORS.length];
    const chkId = 'chk-' + p.id;
    const card = document.createElement('div');
    card.className = 'platform-card';
    card.dataset.pid = p.id;
    card.innerHTML =
      '<div class="platform-header">' +
      '<label class="pf-checkbox" style="display:flex;align-items:center;gap:6px;cursor:pointer" data-action="">' +
      '<input type="checkbox" id="' + chkId + '" class="pf-chk" checked>' +
      '<span class="status-dot dot-green"></span>' +
      '<span class="name" style="border-left:3px solid ' + color + ';padding-left:8px">' + escHtml(p.name) + '</span>' +
      '</label>' +
      (COOKIE_DOMAINS[p.id] ? '<span class="sync-cookie-btn" title="一键同步Cookie到服务器">🔄</span>' : '') +
      '<span style="font-size:11px;color:#94a3b8">' + (escHtml(cred && cred.note) || '已采集') + '</span>' +
      '<span class="hide-btn" style="font-size:11px;color:#64748b;flex-shrink:0;cursor:pointer">🙈 隐藏</span></div>' +
      '<div class="platform-body">' +
      '<input type="text" class="pf-account" placeholder="账号（可选）" value="' + escHtml(saved.account||'') + '">' +
      '<div class="row2"><input type="password" class="pf-password" placeholder="密码" value="' + escHtml(saved.password||'') + '">' +
      '<button class="btn-sm btn-primary pf-save">保存</button></div></div>';
    // 用事件委托：直接绑定在 card 上
    card.addEventListener('click', function(e) {
      const target = e.target;
      if (target.classList.contains('sync-cookie-btn')) {
        const pid = this.dataset.pid;
        const domain = COOKIE_DOMAINS[pid];
        if (!domain) return;
        const pName = (PLATFORMS.find(p => p.id === pid) || {}).name || pid;
        const origText = target.textContent;
        target.textContent = '⏳';
        target.style.pointerEvents = 'none';
        log('info', '开始同步 ' + pName + ' Cookie (domain=' + domain + ')');
        chrome.runtime.sendMessage({action: 'collect_and_push_cookie', platform: pid, domain: domain}, (resp) => {
          target.style.pointerEvents = '';
          if (resp && resp.success) {
            target.textContent = '✅';
            toast(pName + ' Cookie 同步成功', 'success');
            log('ok', pName + ' Cookie 同步成功');
          } else {
            target.textContent = '❌';
            const errMsg = (resp && resp.error) || '未知错误';
            toast(pName + ' 同步失败: ' + errMsg, 'error');
            log('err', pName + ' 同步失败: ' + errMsg);
          }
          setTimeout(() => { target.textContent = origText; }, 3000);
        });
        return;
      }
      if (target.classList.contains('hide-btn')) {
        const pid = this.dataset.pid;
        hiddenPlatforms.push(pid);
        saveHidden();
        renderPlatforms();
        toast((PLATFORMS.find(p => p.id === pid) || {}).name + ' 已隐藏', 'info');
        return;
      }
      if (target.classList.contains('pf-save')) {
        const acct = this.querySelector('.pf-account').value.trim();
        const pw = this.querySelector('.pf-password').value.trim();
        accounts[p.id] = { account: acct, password: pw };
        chrome.storage.local.set({ encrypted_accounts: btoa(JSON.stringify(accounts)) });
        toast(p.name + ' 已保存', 'success');
        return;
      }
      // 点 header 展开/收起
      if (target.tagName !== 'INPUT' && !target.closest('.platform-body')) {
        const body = this.querySelector('.platform-body');
        if (body) body.classList.toggle('open');
      }
    });
    platformsEl.appendChild(card);
  });

  // 如果已隐藏的平台 > 0，显示"显示已隐藏"
  if (hiddenPlatformsList.length > 0) {
    const showAllBtn = document.createElement('div');
    showAllBtn.style.cssText = 'text-align:center;padding:8px;font-size:12px';
    const showLink = document.createElement('span');
    showLink.className = 'toggle-link';
    showLink.textContent = '📂 显示已隐藏的 ' + hiddenPlatformsList.length + ' 个平台';
    showLink.addEventListener('click', async () => {
      hiddenPlatforms = [];
      await saveHidden();
      renderPlatforms();
      toast('已显示所有平台', 'info');
    });
    showAllBtn.appendChild(showLink);
    platformsEl.appendChild(showAllBtn);
  }

  updateLaunchBtn();
}

function updateLaunchBtn() {
  const checked = document.querySelectorAll('.pf-chk:checked').length;
  launchAllBtn.textContent = checked > 0 ? ('⚡ 一键打开 (' + checked + ')') : '⚡ 选择平台';
  launchAllBtn.disabled = checked === 0;
}

async function launchAll() {
  const checked = [...document.querySelectorAll('.pf-chk:checked')];
  if (checked.length === 0) { toast('请勾选要打开的平台', 'error'); return; }
  const checkedIds = checked.map(c => c.id.replace('chk-', ''));
  const targets = PLATFORMS.filter(p => checkedIds.includes(p.id));

  launchAllBtn.disabled = true;
  launchAllBtn.textContent = '⏳ 打开 ' + targets.length + ' 个...';

  for (const p of targets) {
    const saved = accounts[p.id] || {};
    await chrome.storage.session.set({ ['login_' + p.id]: saved });
  }

  const urls = targets.map(p => p.url);
  chrome.windows.create({ url: urls, focused: true, type: 'normal' }, () => {
    launchAllBtn.textContent = '✅ 已全部打开';
    toast('已打开 ' + targets.length + ' 个平台', 'success');
    setTimeout(() => updateLaunchBtn(), 10 * 60 * 1000);
  });
}

async function syncAll() {
  const syncable = PLATFORMS.filter(p => COOKIE_DOMAINS[p.id] && !hiddenPlatforms.includes(p.id));
  if (syncable.length === 0) { toast('没有可同步 Cookie 的平台', 'error'); return; }
  syncAllBtn.disabled = true;
  syncAllBtn.textContent = '⏳ 同步中 (' + syncable.length + ')...';
  log('info', '开始同步全部 Cookie: ' + syncable.map(p => p.name).join(', '));
  let ok = 0, fail = 0;
  for (const p of syncable) {
    const domain = COOKIE_DOMAINS[p.id];
    const result = await chrome.runtime.sendMessage({action: 'collect_and_push_cookie', platform: p.id, domain: domain});
    if (result && result.success) { ok++; log('ok', p.name + ' ✅'); }
    else { fail++; log('err', p.name + ' ❌ ' + (result?.error || '')); }
  }
  syncAllBtn.textContent = ok > 0 && fail === 0 ? '✅ 全部同步成功' : '🔄 同步全部 Cookie';
  syncAllBtn.disabled = false;
  toast('同步完成: ' + ok + ' 成功' + (fail > 0 ? ', ' + fail + ' 失败' : ''), ok > 0 && fail === 0 ? 'success' : 'error');
  setTimeout(() => { syncAllBtn.textContent = '🔄 同步全部 Cookie'; }, 5000);
}

// ============ 初始化 ============
async function init() {
  saveKeyBtn.addEventListener('click', saveTotpKey);
  changeKeyBtn.addEventListener('click', changeKeyMode);
  keyInput.addEventListener('keydown', e => { if (e.key === 'Enter') saveTotpKey(); });
  clearKeyBtn.addEventListener('click', clearTotpKey);
  launchAllBtn.addEventListener('click', launchAll);
  if (syncAllBtn) syncAllBtn.addEventListener('click', syncAll);
  if (toggleLog) toggleLog.addEventListener('click', () => logPanel.classList.toggle('open'));

  const savedLogs = await chrome.storage.session.get('plugin_logs');
  if (savedLogs.plugin_logs) { logs.push(...savedLogs.plugin_logs.slice(-30)); }
  const result = await chrome.storage.local.get('encrypted_accounts');
  if (result.encrypted_accounts) { try { accounts = JSON.parse(atob(result.encrypted_accounts)); } catch(e) {} }

  const hasKey = await loadTotpKey();
  if (hasKey) {
    await loadHidden();
    await loadData();
  } else {
    platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">请先配置 API 密钥</div>';
  }
  statusText.textContent = '就绪';
}

document.addEventListener('DOMContentLoaded', init);
