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
const statusText = document.getElementById('status-text');
const statusAuth = document.getElementById('status-auth');
const toastEl = document.getElementById('toast');
const logPanel = document.getElementById('log-panel');
const toggleLog = document.getElementById('toggle-log');
let toastTimer = null;

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

// ============ TOTP（使用浏览器 crypto.subtle） ============
function base32Decode(str) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  str = str.replace(/\s/g, '').toUpperCase();
  let bits = 0, bitLen = 0, bytes = [];
  for (let i = 0; i < str.length; i++) {
    const pos = chars.indexOf(str[i]);
    if (pos === -1) continue;
    bits = (bits << 5) | pos;
    bitLen += 5;
    if (bitLen >= 8) { bitLen -= 8; bytes.push((bits >> bitLen) & 0xff); }
  }
  return new Uint8Array(bytes);
}

async function generateTotp(secret) {
  const key = base32Decode(secret);
  const counter = Math.floor(Date.now() / 30000);
  const counterBuf = new ArrayBuffer(8);
  new DataView(counterBuf).setBigUint64(0, BigInt(counter), false);
  const cryptoKey = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
  const hmac = new Uint8Array(await crypto.subtle.sign('HMAC', cryptoKey, new Uint8Array(counterBuf)));
  const offset = hmac[19] & 0xf;
  const code = ((hmac[offset] & 0x7f) << 24) | ((hmac[offset + 1] & 0xff) << 16) | ((hmac[offset + 2] & 0xff) << 8) | (hmac[offset + 3] & 0xff);
  return String(code % 1000000).padStart(6, '0');
}

async function totpApi(method, path, body) {
  if (!totpSecret) return { error: '未配置密钥' };
  try {
    const totp = await generateTotp(totpSecret);
    const headers = { 'Content-Type': 'application/json', 'X-TOTP-Key': totpKeyName, 'X-TOTP-Code': totp };
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
  renderPlatforms();
}

// ============ UI ============
function renderPlatforms() {
  platformsEl.innerHTML = '';
  const cookiePlatforms = PLATFORMS.filter(p => credPlatforms[p.id]);
  const otherPlatforms = PLATFORMS.filter(p => !credPlatforms[p.id]);

  if (cookiePlatforms.length === 0 && otherPlatforms.length === 0) {
    platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">暂无数据</div>';
    launchAllBtn.disabled = true; return;
  }

  cookiePlatforms.forEach((p, idx) => {
    const cred = credPlatforms[p.id];
    const saved = accounts[p.id] || {};
    const color = PLATFORM_COLORS[idx % PLATFORM_COLORS.length];
    const card = document.createElement('div');
    card.className = 'platform-card';
    card.innerHTML =
      '<div class="platform-header">' +
      '<span class="status-dot dot-green"></span>' +
      '<span class="name" style="border-left:3px solid ' + color + ';padding-left:8px">' + escHtml(p.name) + '</span>' +
      '<span style="font-size:11px;color:#94a3b8">🍪 ' + (escHtml(cred.note) || '已采集') + '</span></div>' +
      '<div class="platform-body">' +
      '<input type="text" class="pf-account" placeholder="账号（可选）" value="' + escHtml(saved.account||'') + '">' +
      '<div class="row2"><input type="password" class="pf-password" placeholder="密码" value="' + escHtml(saved.password||'') + '">' +
      '<button class="btn-sm btn-primary pf-save">保存</button></div></div>';
    const header = card.querySelector('.platform-header');
    const body = card.querySelector('.platform-body');
    header.addEventListener('click', () => body.classList.toggle('open'));
    card.querySelector('.pf-save').addEventListener('click', async () => {
      const account = card.querySelector('.pf-account').value.trim();
      const password = card.querySelector('.pf-password').value.trim();
      accounts[p.id] = { account, password };
      await chrome.storage.local.set({ encrypted_accounts: btoa(JSON.stringify(accounts)) });
      toast(p.name + ' 已保存', 'success');
    });
    platformsEl.appendChild(card);
  });

  if (otherPlatforms.length > 0) {
    const link = document.createElement('div');
    link.style.cssText = 'font-size:11px;color:#64748b;text-align:center;padding:6px;cursor:pointer;';
    link.textContent = '+ ' + otherPlatforms.length + ' 个未采集平台 (展开)';
    link.addEventListener('click', () => {
      link.style.display = 'none';
      otherPlatforms.forEach((p, idx) => {
        const saved = accounts[p.id] || {};
        const color = PLATFORM_COLORS[(cookiePlatforms.length + idx) % PLATFORM_COLORS.length];
        const card = document.createElement('div');
        card.className = 'platform-card';
        card.innerHTML =
          '<div class="platform-header">' +
          '<span class="status-dot dot-gray"></span>' +
          '<span class="name" style="border-left:3px solid ' + color + ';padding-left:8px">' + escHtml(p.name) + '</span>' +
          '<span style="font-size:11px;color:#94a3b8">❌ 无 Cookie</span></div>' +
          '<div class="platform-body">' +
          '<input type="text" class="pf-account" placeholder="账号（可选）" value="' + escHtml(saved.account||'') + '">' +
          '<div class="row2"><input type="password" class="pf-password" placeholder="密码" value="' + escHtml(saved.password||'') + '">' +
          '<button class="btn-sm btn-primary pf-save">保存</button></div></div>';
        card.querySelector('.platform-header').addEventListener('click', () => card.querySelector('.platform-body').classList.toggle('open'));
        card.querySelector('.pf-save').addEventListener('click', async () => {
          const account = card.querySelector('.pf-account').value.trim();
          const password = card.querySelector('.pf-password').value.trim();
          accounts[p.id] = { account, password };
          await chrome.storage.local.set({ encrypted_accounts: btoa(JSON.stringify(accounts)) });
          toast(p.name + ' 已保存', 'success');
        });
        platformsEl.appendChild(card);
      });
    });
    platformsEl.appendChild(link);
  }

  const active = cookiePlatforms.length;
  launchAllBtn.textContent = active > 0 ? ('⚡ 一键打开 (' + active + ')') : '⚡ 暂无已采集平台';
  launchAllBtn.disabled = active === 0;
}

async function launchAll() {
  // 打开所有平台，不管有没有 Cookie
  const targets = PLATFORMS;
  if (targets.length === 0) { toast('没有可打开的平台', 'error'); return; }
  launchAllBtn.disabled = true;
  launchAllBtn.textContent = '⏳ 打开 ' + targets.length + ' 个...';
  for (const p of targets) {
    const saved = accounts[p.id] || {};
    chrome.storage.session.set({ ['login_' + p.id]: saved });
    chrome.tabs.create({ url: p.url });
    await new Promise(r => setTimeout(r, 800));
  }
  launchAllBtn.textContent = '✅ 已全部打开';
  toast('已打开 ' + targets.length + ' 个平台', 'success');
  setTimeout(() => { launchAllBtn.textContent = '⚡ 一键打开全部平台'; launchAllBtn.disabled = false; }, 10 * 60 * 1000);
}

// ============ 初始化 ============
async function init() {
  saveKeyBtn.addEventListener('click', saveTotpKey);
  changeKeyBtn.addEventListener('click', changeKeyMode);
  keyInput.addEventListener('keydown', e => { if (e.key === 'Enter') saveTotpKey(); });
  clearKeyBtn.addEventListener('click', clearTotpKey);
  launchAllBtn.addEventListener('click', launchAll);
  if (toggleLog) toggleLog.addEventListener('click', () => logPanel.classList.toggle('open'));

  const savedLogs = await chrome.storage.session.get('plugin_logs');
  if (savedLogs.plugin_logs) { logs.push(...savedLogs.plugin_logs.slice(-30)); }
  const result = await chrome.storage.local.get('encrypted_accounts');
  if (result.encrypted_accounts) { try { accounts = JSON.parse(atob(result.encrypted_accounts)); } catch(e) {} }

  const hasKey = await loadTotpKey();
  if (hasKey) {
    await loadData();
  } else {
    platformsEl.innerHTML = '<div style="text-align:center;color:#475569;padding:20px;font-size:13px">请先配置 API 密钥</div>';
  }
  statusText.textContent = '就绪';
}

document.addEventListener('DOMContentLoaded', init);
