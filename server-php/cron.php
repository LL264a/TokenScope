<?php
/**
 * Token Monitor - 定时采集入口
 * 由宝塔 cron 调用: php /www/wwwroot/token-monitor/cron.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/collectors.php';

// 检查是否启用调度器
if (tm_get_setting('scheduler_running', '0') !== '1') {
    echo "[CRON] 调度器未启动，跳过\n";
    exit(0);
}

// 检查采集间隔
$interval = intval(tm_get_setting('scheduler_interval', '300'));
$last_run = intval(tm_get_setting('scheduler_last_run', '0'));
if ($last_run && (time() - $last_run) < $interval) {
    $remaining = $interval - (time() - $last_run);
    echo "[CRON] 间隔未到，剩余 {$remaining} 秒\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] 开始采集\n";
$results = tm_do_refresh_all();

// 更新最后运行时间
tm_set_setting('scheduler_last_run', strval(time()));

$ok = 0;
$fail = 0;
foreach ($results as $platform => $r) {
    $status = $r['status'] ?? 'unknown';
    if ($status === 'success') {
        $ok++;
        echo "  ✅ $platform: {$r['duration_ms']}ms\n";
    } else {
        $fail++;
        $err = $r['error'] ?? '';
        echo "  ❌ $platform: $err\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] 完成: {$ok}成功 {$fail}失败\n";
