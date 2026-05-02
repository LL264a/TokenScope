<?php
/**
 * Token Monitor - 定时采集入口
 * 
 * crontab * * * * * 每分钟触发一次
 * 进入后内部循环，按面板设置的 interval（10-120s）反复采集
 * 这样面板设 10 秒就能每 10 秒采一次，设 60 秒就每分钟一次
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
        exit(0);
    }

    // 检查调度器是否启用
    if (tm_get_setting('scheduler_running', '0') !== '1') {
        echo "[CRON] 调度器未启动\n";
        exit(0);
    }

    // 检查间隔
    $interval = max(10, intval(tm_get_setting('scheduler_interval', '60')));
    $interval = min(120, $interval);
    $last_run = intval(tm_get_setting('scheduler_last_run', '0'));

    $now = time();
    $elapsed = $now - $last_run;

    if ($last_run && $elapsed < $interval) {
        $sleep = min($interval - $elapsed, $max_run - (time() - $start_time));
        if ($sleep <= 0) break;
        echo "[CRON] 距下次采集还有 {$sleep} 秒\n";
        sleep($sleep);
        continue;
    }

    // 执行采集
    echo "[" . date('Y-m-d H:i:s') . "] 开始采集\n";
    $results = tm_do_refresh_all();
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

    // 采完后等 interval 秒再采下一次（除非快超时了）
    $remaining = $max_run - (time() - $start_time);
    if ($remaining < $interval) break;
    sleep($interval);
}
