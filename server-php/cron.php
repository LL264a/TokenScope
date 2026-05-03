<?php
/**
 * Token Monitor - 定时采集入口
 *
 * crontab * * * * * 每分钟触发一次
 * 默认 30 秒采集一次，可从面板调整
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/collectors.php';

$max_run = 55; // 最多跑 55 秒，留 5 秒给下次 crontab 触发

$start_time = time();

while (true) {
    // 超时退出，等下次 crontab
    if (time() - $start_time >= $max_run) {
        echo "[CRON] 本轮超时，退出等下次触发\n";
        break;
    }

    // 检查调度器是否启用
    if (tm_get_setting('scheduler_running', '1') !== '1') {
        echo "[CRON] 调度器未启动\n";
        break;
    }

    // 检查间隔（默认 30 秒）
    $interval = max(10, intval(tm_get_setting('scheduler_interval', '30')));
    $interval = min(120, $interval);
    $last_run = intval(tm_get_setting('scheduler_last_run', '0'));

    $now = time();
    $elapsed = $now - $last_run;

    if ($last_run && $elapsed < $interval) {
        $sleep = min($interval - $elapsed, $max_run - ($now - $start_time));
        if ($sleep <= 0) break;
        echo "[CRON] 距下次采集还有 {$sleep} 秒\n";
        sleep($sleep);
        continue;
    }

    // 执行采集
    echo "[" . date('Y-m-d H:i:s') . "] 开始采集\n";
    $collect_start = time();
    $results = tm_do_refresh_all();
    $collect_end = time();
    tm_set_setting('scheduler_last_run', strval($collect_end));

    $ok = 0; $fail = 0;
    foreach ($results as $platform => $r) {
        $status = $r['status'] ?? 'unknown';
        if ($status === 'success') { $ok++; echo "  ✅ $platform: {$r['duration_ms']}ms\n"; }
        else { $fail++; echo "  ❌ $platform: " . ($r['error'] ?? '') . "\n"; }
    }
    echo "[" . date('Y-m-d H:i:s') . "] 完成: {$ok}成功 {$fail}失败\n";

    // 等下一次采集（扣除本次耗时，保证实际间隔 = interval）
    $collect_took = $collect_end - $collect_start;
    $sleep_time = max(0, $interval - $collect_took);
    $remaining = $max_run - ($collect_end - $start_time);
    if ($remaining < $sleep_time) break;
    if ($sleep_time > 0) sleep($sleep_time);
}

