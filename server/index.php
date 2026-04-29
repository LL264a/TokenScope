<?php
/**
 * Token Monitor - 服务器展示页
 * 纯静态渲染，读本地 JSON 文件，不依赖本地后端在线
 */
$statsFile = __DIR__ . '/data/stats.json';
$cookieFile = __DIR__ . '/data/cookie_status.json';

$stats = null;
$cookieStatus = null;

if (file_exists($statsFile)) {
    $raw = json_decode(file_get_contents($statsFile), true);
    $stats = $raw['data'] ?? null;
    $statsUpdated = $raw['updated_at'] ?? '';
    $statsAge = time() - ($raw['updated_ts'] ?? 0);
}
if (file_exists($cookieFile)) {
    $raw = json_decode(file_get_contents($cookieFile), true);
    $cookieStatus = $raw['data'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Token 监控面板</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: #0a0e1a;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* ====== 顶栏 ====== */
        .top-bar {
            background: linear-gradient(135deg, #1a1f35, #0f172a);
            border-bottom: 1px solid #1e293b;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .top-bar h1 {
            font-size: 20px;
            background: linear-gradient(135deg, #60a5fa, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .top-bar .meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .live-dot {
            width: 6px; height: 6px;
            background: #22c55e;
            border-radius: 50%;
            animation: blink 2s infinite;
            display: inline-block;
        }
        .live-dot.offline { background: #6b7280; animation: none; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }

        /* ====== 数据状态栏 ====== */
        .data-bar {
            background: #111827;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }
        .data-bar .fresh { color: #4ade80; }
        .data-bar .stale { color: #fbbf24; }
        .data-bar .old { color: #f87171; }

        /* ====== Cookie 失效警告横幅 ====== */
        .cookie-alert {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            border: 1px solid #dc2626;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            animation: alertPulse 2s ease-in-out infinite;
        }
        .cookie-alert .alert-icon { font-size: 28px; }
        .cookie-alert .alert-body { flex: 1; }
        .cookie-alert .alert-title { font-size: 15px; font-weight: 700; color: #fca5a5; }
        .cookie-alert .alert-platforms { font-size: 12px; color: #fecaca; margin-top: 4px; }
        .cookie-alert .alert-time { font-size: 11px; color: #991b1b; margin-top: 2px; }
        @keyframes alertPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.3); }
            50% { box-shadow: 0 0 20px 4px rgba(220, 38, 38, 0.2); }
        }

        /* ====== 平台卡片网格 ====== */
        .platform-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        /* ====== 卡片基础 ====== */
        .plan-card {
            background: #111827;
            border: 1px solid #1e293b;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }
        .card-header {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #1e293b;
        }
        .card-header .title { font-size: 18px; font-weight: 700; color: #f1f5f9; }
        .card-header .subtitle { font-size: 13px; color: #64748b; margin-top: 2px; }
        .status-badge { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
        .badge-available { background: rgba(34,197,94,0.15); color: #4ade80; }
        .badge-warning   { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-exhausted { background: rgba(239,68,68,0.15); color: #f87171; }

        /* ====== Coding Plan: 三层配额 ====== */
        .quota-body { padding: 20px 24px; }
        .quota-item { margin-bottom: 16px; }
        .quota-item:last-child { margin-bottom: 0; }
        .quota-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .quota-label { font-size: 14px; font-weight: 500; color: #cbd5e1; display: flex; align-items: center; gap: 6px; }
        .quota-total-hint { font-size: 12px; color: #64748b; font-weight: 400; }
        .quota-remaining { font-size: 14px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .progress-track { width: 100%; height: 10px; background: #1e293b; border-radius: 5px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 5px; transition: width 0.8s cubic-bezier(0.16, 1, 0.3, 1); }
        .fill-green { background: linear-gradient(90deg, #22c55e, #4ade80); }
        .fill-yellow { background: linear-gradient(90deg, #eab308, #fbbf24); }
        .fill-red { background: linear-gradient(90deg, #dc2626, #f87171); }
        .quota-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
        .quota-pct { font-size: 12px; font-weight: 600; }
        .quota-refresh { font-size: 11px; color: #4b5563; }

        /* ====== 传统计划 ====== */
        .card-footer {
            padding: 12px 24px;
            background: #0d1117;
            font-size: 11px;
            color: #4b5563;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* ====== 腾讯子服务 ====== */
        .sub-service { border-bottom: 1px solid #1e293b; }
        .sub-service:last-of-type { border-bottom: none; }
        .sub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 24px;
            background: #0d1117;
            font-size: 15px;
            font-weight: 600;
            color: #cbd5e1;
        }

        /* ====== 蓝色遮罩：额度已用尽 ====== */
        .sub-exhausted-overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(30, 64, 175, 0.25);
            backdrop-filter: blur(4px);
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            z-index: 10; animation: fadeIn 0.5s ease;
        }
        .exhausted-text { font-size: 20px; font-weight: 800; color: #ef4444; text-shadow: 0 0 20px rgba(239,68,68,0.5); letter-spacing: 3px; margin-bottom: 8px; }
        .exhausted-recovery { font-size: 12px; color: #93c5fd; display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .exhausted-recovery .time { font-size: 14px; font-weight: 600; color: #bfdbfe; }
        .exhausted-recovery .countdown { font-size: 12px; color: #60a5fa; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* ====== 颜色工具 ====== */
        .green { color: #4ade80; }
        .yellow { color: #fbbf24; }
        .red { color: #f87171; }
        .blue { color: #60a5fa; }

        .empty-state { text-align: center; padding: 40px; color: #4b5563; }
        .empty-state .icon { font-size: 40px; margin-bottom: 12px; }
        .empty-state .msg { font-size: 14px; margin-bottom: 8px; }
        .empty-state .hint { font-size: 12px; color: #64748b; }

        @media (max-width: 768px) {
            .platform-grid { grid-template-columns: 1fr; }
            .top-bar { padding: 12px 16px; }
            .container { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Token 监控面板</h1>
        <div class="meta">
            <span><span class="live-dot <?php echo empty($stats) ? 'offline' : ''; ?>"></span>
            <span><?php echo empty($stats) ? '无数据' : '在线'; ?></span></span>
            <span>自动刷新 <span id="cd">60</span>s</span>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($statsUpdated)): ?>
        <div class="data-bar">
            <span>数据更新：<?php echo htmlspecialchars($statsUpdated); ?></span>
            <span class="<?php echo $statsAge < 600 ? 'fresh' : ($statsAge < 3600 ? 'stale' : 'old'); ?>">
                <?php echo $statsAge < 60 ? '刚刚' : ($statsAge < 3600 ? floor($statsAge/60) . '分钟前' : floor($statsAge/3600) . '小时前'); ?>
            </span>
        </div>
        <?php endif; ?>

        <div id="cookie-alert-container"></div>
        <div class="platform-grid" id="platforms"></div>
    </div>

<script>
/* ====== 配置 ====== */
const NAMES = {
    tencent:'腾讯混元', tencent_codingplan:'Coding Plan',
    tencent_hy_tokenplan:'Hy Token Plan', tencent_tokenplan:'Token Plan（通用）',
    volcano:'火山引擎', xiaomi:'小米 MiMo'
};

/* ====== 从 PHP 注入数据 ====== */
const STATS = <?php echo json_encode($stats, JSON_UNESCAPED_UNICODE); ?>;
const COOKIE_STATUS = <?php echo json_encode($cookieStatus, JSON_UNESCAPED_UNICODE); ?>;

/* ====== 颜色规则 ====== */
function getColorClass(pct) {
    if (pct >= 80) return { fill: 'fill-red', text: 'red' };
    if (pct >= 50) return { fill: 'fill-yellow', text: 'yellow' };
    return { fill: 'fill-green', text: 'green' };
}
function getStatusBadge(usedPct) {
    if (usedPct >= 100) return { cls: 'badge-exhausted', text: '额度已用尽' };
    if (usedPct >= 80)  return { cls: 'badge-warning', text: '额度紧张' };
    return { cls: 'badge-available', text: '可用' };
}
function fmt(n) {
    if (!n) return '0';
    if (n >= 1e8) return (n/1e8).toFixed(1) + ' 亿';
    if (n >= 1e4) return (n/1e4).toFixed(1) + ' 万';
    if (n >= 1e3) return (n/1e3).toFixed(1) + 'K';
    return n.toLocaleString();
}

/* ====== Cookie 失效警告 ====== */
function renderCookieAlert(statusList) {
    const container = document.getElementById('cookie-alert-container');
    if (!statusList || !statusList.length) { container.innerHTML = ''; return; }
    const failed = statusList.filter(s => !s.healthy);
    const cookieExpired = failed.filter(s => s.cookie_expired);
    const otherFailed = failed.filter(s => !s.cookie_expired);
    if (!failed.length) { container.innerHTML = ''; return; }
    let html = '';
    if (cookieExpired.length) {
        const names = cookieExpired.map(s => NAMES[s.platform] || s.platform).join('、');
        const lastCheck = cookieExpired[0].last_check_fmt || '';
        html += '<div class="cookie-alert"><span class="alert-icon">🍪</span><div class="alert-body">' +
            '<div class="alert-title">Cookie 已失效，数据无法更新</div>' +
            '<div class="alert-platforms">受影响：' + names + '</div>' +
            '<div class="alert-time">最后检测：' + lastCheck + ' · 请重新获取 Cookie</div></div></div>';
    }
    if (otherFailed.length) {
        const names = otherFailed.map(s => NAMES[s.platform] || s.platform).join('、');
        const msgs = [...new Set(otherFailed.map(s => s.message))].join('；');
        html += '<div class="cookie-alert" style="background:linear-gradient(135deg,#78350f,#92400e);border-color:#d97706">' +
            '<span class="alert-icon">⚠️</span><div class="alert-body">' +
            '<div class="alert-title" style="color:#fde68a">数据采集异常</div>' +
            '<div class="alert-platforms" style="color:#fbbf24">受影响：' + names + '</div>' +
            '<div class="alert-time" style="color:#78350f">' + msgs + '</div></div></div>';
    }
    container.innerHTML = html;
}

/* ====== 渲染 ====== */
function renderPlatforms(platforms) {
    const el = document.getElementById('platforms');
    if (!platforms || !platforms.length) {
        el.innerHTML = '<div class="empty-state"><div class="icon">📊</div><div class="msg">暂无平台数据</div><div class="hint">等待本地推送数据...</div></div>';
        return;
    }
    el.innerHTML = platforms.map(p => {
        if (p.platform === 'tencent' && p.services) return renderTencentCard(p);
        if (p.quotas) return renderCodingPlan(p);
        if (p.remaining_pct !== undefined) return renderTokenPlan(p);
        return renderFallback(p);
    }).join('');
}

function renderTencentCard(p) {
    const services = p.services || [];
    let worstPct = 0;
    services.forEach(s => {
        let usedPct = 0;
        if (s.quotas) usedPct = Math.max(...Object.values(s.quotas).map(q => q.used_pct || 0));
        else if (s.remaining_pct !== undefined) usedPct = 100 - s.remaining_pct;
        worstPct = Math.max(worstPct, usedPct);
    });
    const anyExhausted = services.some(s => s.remaining_pct !== undefined && s.remaining_pct <= 0);
    const badge = getStatusBadge(anyExhausted ? 100 : worstPct);

    let servicesHtml = services.map(s => {
        const subName = NAMES[s.platform] || s.platform;
        if (s.quotas) return renderSubCodingPlan(s, subName);
        if (s.remaining_pct !== undefined) return renderSubTokenPlan(s, subName);
        return '<div class="sub-service"><div class="sub-header">' + subName + '</div><div style="padding:12px;color:#4b5563">数据格式异常</div></div>';
    }).join('');

    return '<div class="plan-card" style="grid-column: 1 / -1"><div class="card-header"><div><div class="title" style="font-size:22px">🔷 腾讯混元</div><div class="subtitle">' + services.length + ' 个服务 · 广州区域</div></div><span class="status-badge ' + badge.cls + '">' + badge.text + '</span></div><div style="padding:0">' + servicesHtml + '</div><div class="card-footer"><span>腾讯混元 · ' + services.length + ' 个服务</span><span>' + (p.last_updated ? new Date(p.last_updated).toLocaleTimeString() : '-') + '</span></div></div>';
}

function renderSubCodingPlan(s, name) {
    const q = s.quotas || {};
    const quotaLabels = { '5h': {name:'每5小时',icon:'⚡'}, 'weekly': {name:'每周',icon:'📅'}, 'monthly': {name:'每订阅月',icon:'📆'} };
    const anyExhausted = Object.values(q).some(v => v.used_pct >= 100);
    const worstPct = Math.max(...Object.values(q).map(v => v.used_pct || 0));
    const badge = anyExhausted ? getStatusBadge(100) : getStatusBadge(worstPct);

    let recoveryTime = '';
    for (const [key, info] of Object.entries(q)) {
        if (info.used_pct >= 100 && info.refresh_at && (!recoveryTime || info.refresh_at < recoveryTime)) recoveryTime = info.refresh_at;
    }

    let quotaHtml = '';
    for (const [key, info] of Object.entries(q)) {
        const label = quotaLabels[key] || {name:key,icon:'📊'};
        const pct = info.used_pct || 0;
        const remaining = info.total - Math.round(info.total * pct / 100);
        const c = getColorClass(pct);
        const isBlocked = pct >= 100;
        quotaHtml += '<div class="quota-item"><div class="quota-top"><span class="quota-label">' + label.icon + ' ' + label.name + ' <span class="quota-total-hint">(总量 ' + info.total.toLocaleString() + ')</span>' + (isBlocked ? '<span style="color:#f87171;font-size:12px;font-weight:700">⛔ 已用尽</span>' : '') + '</span><span class="quota-remaining ' + c.text + '">剩余 ' + remaining.toLocaleString() + '</span></div><div class="progress-track"><div class="progress-fill ' + c.fill + '" style="width:' + Math.min(pct,100) + '%"></div></div><div class="quota-footer"><span class="quota-pct ' + c.text + '">' + pct.toFixed(1) + '% 已用</span><span class="quota-refresh">' + (isBlocked ? '🔄 ' + (info.refresh_at || '') + ' 恢复' : '🔄 ' + (info.refresh_at || '') + ' 刷新') + '</span></div></div>';
    }

    const overlayHtml = anyExhausted && recoveryTime
        ? '<div class="sub-exhausted-overlay"><div class="exhausted-text">额度已用尽</div><div class="exhausted-recovery"><span>恢复时间</span><span class="time">' + recoveryTime + '</span><span class="countdown" data-recovery="' + recoveryTime + '">计算中...</span></div></div>'
        : '';

    return '<div class="sub-service" style="position:relative"><div class="sub-header"><span>' + name + '</span><span style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;color:#64748b">' + (s.plan_type||'Lite') + ' · ' + (s.cost?s.cost+'元/月':'') + '</span><span class="status-badge ' + badge.cls + '" style="font-size:11px;padding:2px 8px">' + badge.text + '</span></span></div><div class="quota-body">' + quotaHtml + '</div>' + overlayHtml + '</div>';
}

function renderSubTokenPlan(s, name) {
    const usedPct = 100 - (s.remaining_pct || 0);
    const c = getColorClass(usedPct);
    const badge = getStatusBadge(usedPct);
    const total = s.total_tokens || 0, used = s.input_tokens || 0, remaining = s.output_tokens || 0;
    return '<div class="sub-service"><div class="sub-header"><span>' + name + '</span><span style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;color:#64748b">' + (s.plan_type||'') + ' · ' + (s.remaining_days?s.remaining_days+'天':'') + '</span><span class="status-badge ' + badge.cls + '" style="font-size:11px;padding:2px 8px">' + badge.text + '</span></span></div><div style="padding:16px 24px"><div style="display:flex;align-items:baseline;gap:12px;margin-bottom:14px"><span style="font-size:13px;color:#64748b">剩余</span><span style="font-size:28px;font-weight:800" class="' + c.text + '">' + fmt(remaining) + '</span><span style="font-size:16px;font-weight:600" class="' + c.text + '">(' + (s.remaining_pct||0).toFixed(1) + '%)</span><span style="font-size:12px;color:#4b5563">/ 套餐 ' + fmt(total) + '</span></div><div class="progress-track" style="margin-bottom:8px"><div class="progress-fill ' + c.fill + '" style="width:' + usedPct.toFixed(1) + '%"></div></div><div style="display:flex;justify-content:space-between;font-size:12px"><span class="' + c.text + '">已用 ' + usedPct.toFixed(1) + '%</span><span class="' + c.text + '">剩余 ' + (s.remaining_pct||0).toFixed(1) + '%</span></div></div></div>';
}

function renderCodingPlan(p) {
    const q = p.quotas || {};
    const labels = { '5h':{name:'每5小时',icon:'⚡'}, 'weekly':{name:'每周',icon:'📅'}, 'monthly':{name:'每订阅月',icon:'📆'} };
    const isExhausted = Object.values(q).some(v => v.used_pct >= 100);
    const badge = getStatusBadge(isExhausted ? 100 : 0);
    let quotaHtml = '';
    for (const [key, info] of Object.entries(q)) {
        const label = labels[key]||{name:key,icon:'📊'};
        const pct = info.used_pct||0, remaining = info.total - Math.round(info.total*pct/100);
        const c = getColorClass(pct), isBlocked = pct>=100;
        quotaHtml += '<div class="quota-item"><div class="quota-top"><span class="quota-label">' + label.icon + ' ' + label.name + ' <span class="quota-total-hint">(总量 ' + info.total.toLocaleString() + ')</span>' + (isBlocked?'<span style="color:#f87171;font-size:12px;font-weight:700">⛔</span>':'') + '</span><span class="quota-remaining '+c.text+'">剩余 '+remaining.toLocaleString()+'</span></div><div class="progress-track"><div class="progress-fill '+c.fill+'" style="width:'+Math.min(pct,100)+'%"></div></div><div class="quota-footer"><span class="quota-pct '+c.text+'">'+pct.toFixed(1)+'% 已用</span><span class="quota-refresh">🔄 '+(info.refresh_at||'')+' 刷新</span></div></div>';
    }
    return '<div class="plan-card"><div class="card-header"><div><div class="title">'+(NAMES[p.platform]||p.platform)+'</div><div class="subtitle">广州 · '+(p.plan_type||'Lite')+' 套餐</div></div><span class="status-badge '+badge.cls+'">'+badge.text+'</span></div><div class="quota-body">'+quotaHtml+'</div><div class="card-footer"><span>'+(p.plan_type||'Coding Plan')+'</span><span>'+(p.last_updated?new Date(p.last_updated).toLocaleTimeString():'-')+'</span></div></div>';
}

function renderTokenPlan(p) {
    const usedPct = 100-(p.remaining_pct||0), c=getColorClass(usedPct), badge=getStatusBadge(usedPct);
    const total=p.total_tokens||0, used=p.input_tokens||0, remaining=p.output_tokens||0;
    return '<div class="plan-card"><div class="card-header"><div><div class="title">'+(NAMES[p.platform]||p.platform)+'</div><div class="subtitle">广州 · '+(p.plan_type||'')+'</div></div><span class="status-badge '+badge.cls+'">'+badge.text+'</span></div><div style="padding:24px"><div style="text-align:center;margin-bottom:20px"><div style="font-size:13px;color:#64748b;margin-bottom:4px">剩余额度</div><div style="font-size:36px;font-weight:800;letter-spacing:-1px" class="'+c.text+'">'+fmt(remaining)+'</div><div style="font-size:12px;color:#4b5563;margin-top:4px">套餐额度 '+fmt(total)+' · 已使用 '+fmt(used)+'</div></div><div style="margin-bottom:20px"><div class="progress-track"><div class="progress-fill '+c.fill+'" style="width:'+usedPct.toFixed(1)+'%"></div></div><div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px"><span class="'+c.text+'">已用 '+usedPct.toFixed(1)+'%</span><span class="'+c.text+'">剩余 '+(p.remaining_pct||0).toFixed(1)+'%</span></div></div></div><div class="card-footer"><span>'+(p.plan_type||p.platform)+'</span><span>'+(p.last_updated?new Date(p.last_updated).toLocaleTimeString():'-')+'</span></div></div>';
}

function renderFallback(p) {
    return '<div class="plan-card"><div class="card-header"><div><div class="title">'+(NAMES[p.platform]||p.platform)+'</div></div><span class="status-badge badge-available">'+(p.source||'unknown')+'</span></div><div style="padding:24px"><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div style="background:#0d1117;border-radius:10px;padding:14px 16px"><div style="font-size:11px;color:#64748b;margin-bottom:4px">总量</div><div style="font-size:18px;font-weight:600">'+fmt(p.total_tokens)+'</div></div><div style="background:#0d1117;border-radius:10px;padding:14px 16px"><div style="font-size:11px;color:#64748b;margin-bottom:4px">剩余</div><div style="font-size:18px;font-weight:600">'+(p.remaining||'-')+'</div></div></div></div><div class="card-footer"><span>'+(p.source||'unknown')+'</span><span>'+(p.last_updated?new Date(p.last_updated).toLocaleTimeString():'-')+'</span></div></div>';
}

function updateCountdowns() {
    document.querySelectorAll('.countdown[data-recovery]').forEach(el => {
        const recovery = new Date(el.dataset.recovery);
        if (isNaN(recovery)) { el.textContent = ''; return; }
        const diff = recovery - new Date();
        if (diff <= 0) { el.textContent = '已到刷新时间'; return; }
        const d = Math.floor(diff / 86400000), h = Math.floor((diff % 86400000) / 3600000), m = Math.floor((diff % 3600000) / 60000);
        el.textContent = '还剩 ' + d + '天 ' + h + '小时 ' + m + '分钟';
    });
}

/* ====== 初始化 ====== */
renderPlatforms(STATS ? STATS.platforms : null);
renderCookieAlert(COOKIE_STATUS);
updateCountdowns();

// 自动刷新（60秒）
let cd = 60;
setInterval(() => {
    cd--;
    document.getElementById('cd').textContent = cd;
    if (cd <= 0) location.reload();
}, 1000);
setInterval(updateCountdowns, 60000);
</script>
</body>
</html>
