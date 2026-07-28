<?php
/**
 * Token Monitor - 数据采集器
 * 腾讯云 / 火山引擎 / 小米 MIMO
 */
require_once __DIR__ . '/config.php';

// ============ HTTP 请求封装 ============

function tm_http_get(string $url, array $headers=[], int $timeout=20): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => tm_build_curl_headers($headers),
    ]);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return null;
    return ['code' => $http_code, 'body' => $body];
}

function tm_http_post(string $url, array $headers=[], $body=null, int $timeout=20, array $query=[]): ?array {
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    $ch = curl_init($url);
    $curl_headers = tm_build_curl_headers($headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $curl_headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return null;
    return ['code' => $http_code, 'body' => $resp];
}

function tm_build_curl_headers(array $headers): array {
    $result = [];
    foreach ($headers as $k => $v) {
        $result[] = "$k: $v";
    }
    return $result;
}

function tm_extract_cookie_value(string $cookie_str, string $key): string {
    foreach (explode(';', $cookie_str) as $part) {
        $part = trim($part);
        if (strpos($part, "$key=") === 0) {
            return substr($part, strlen("$key="));
        }
    }
    return '';
}

function tm_parse_netscape(string $netscape): string {
    $pairs = [];
    foreach (explode("\n", $netscape) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = explode("\t", $line);
        if (count($parts) >= 7) {
            $name = trim($parts[5]);
            $value = trim($parts[6]);
            $value = trim($value, '"');
            $pairs[] = $name . '=' . $value;
        }
    }
    return implode('; ', $pairs);
}

function tm_fmt_tokens(int $n): string {
    if ($n >= 100000000) return number_format($n / 100000000, 1) . '亿';
    if ($n >= 10000) return number_format($n / 10000, 1) . '万';
    return strval($n);
}

// ============ 腾讯云采集 ============

function tm_collect_tencent_from_api_data(string $raw_json): array {
    $data = json_decode($raw_json, true);
    if (!is_array($data)) {
        return [tm_error_item('tencent_codingplan', 'CAPI数据格式错误')];
    }
    
    $results = [];
    foreach ($data as $entry) {
        // === 新格式：{all: [{cmd, data, url}]} ===
        if (isset($entry['all']) && is_array($entry['all'])) {
            foreach ($entry['all'] as $item) {
                $cmd = $item['cmd'] ?? '';
                $resp = $item['data'] ?? [];
                if ($cmd === 'DescribePkg') {
                    $pkg = $resp['PkgList'][0] ?? null;
                    if (!$pkg) continue;
                    $parsed = tm_tencent_parse_pkg_for_api($pkg);
                    if ($parsed) $results['tencent_codingplan'] = $parsed;
                } elseif ($cmd === 'DescribeTokenPlanUsage') {
                    $usage = $resp['TokenPlanUsageList'][0] ?? null;
                    if (!$usage) continue;
                    $parsed = tm_tencent_parse_plan_usage_for_api($usage);
                    if ($parsed) $results[$parsed['platform']] = $parsed;
                }
                // ListUserTokenPlans 本身不返回用量，忽略
            }
            continue;
        }
        
        // === 旧格式：{pkgs: [...]} ===
        $pkgs = $entry['pkgs'] ?? [];
        foreach ($pkgs as $pkg) {
            $name = $pkg['PkgName'] ?? '';
            $detail = $pkg['UsageDetail'] ?? [];
            
            $five_h = $detail['PerFiveHour'] ?? [];
            $week = $detail['PerWeek'] ?? [];
            $month = $detail['PerMonth'] ?? [];
            
            $calc_pct = function($d) { return ($d['Total'] ?? 0) > 0 ? round(($d['Used'] ?? 0) / $d['Total'] * 100, 1) : 0; };
            $f_pct = $calc_pct($five_h);
            $w_pct = $calc_pct($week);
            $m_pct = $calc_pct($month);
            $overall = max($f_pct, $w_pct, $m_pct);
            
            $refresh_at = '';
            if (!empty($five_h['EndTime'])) {
                $refresh_at = date('Y-m-d H:i:s', strtotime($five_h['EndTime']));
            }
            
            $quotas = [];
            if (isset($five_h['Total'])) {
                $quotas['5h'] = ['total'=>$five_h['Total'],'used'=>$five_h['Used']??0,'used_pct'=>$f_pct,'remaining'=>max(0,$five_h['Total']-($five_h['Used']??0)),'refresh_at'=>$refresh_at];
            }
            if (isset($week['Total'])) {
                $quotas['weekly'] = ['total'=>$week['Total'],'used'=>$week['Used']??0,'used_pct'=>$w_pct,'remaining'=>max(0,$week['Total']-($week['Used']??0))];
            }
            if (isset($month['Total'])) {
                $quotas['monthly'] = ['total'=>$month['Total'],'used'=>$month['Used']??0,'used_pct'=>$m_pct,'remaining'=>max(0,$month['Total']-($month['Used']??0))];
            }
            
            $results['tencent_codingplan'] = [
                'platform' => 'tencent_codingplan',
                'total_tokens' => $month['Total'] ?? 0,
                'input_tokens' => $month['Used'] ?? 0,
                'remaining' => "5h:{$f_pct}% | 周:{$w_pct}% | 月:{$m_pct}%",
                'remaining_pct' => 100 - $overall,
                'quotas' => $quotas,
                'plan_name' => $name ?: 'Coding Plan',
                'valid_from' => $pkg['StartTime'] ?? '',
                'valid_to' => $pkg['EndTime'] ?? '',
                'plan_status' => $pkg['Status'] ?? '',
                'plan_pct' => $pkg['UsagePercent'] ?? $f_pct,
                'month_used' => $month['Used'] ?? 0,
                'month_limit' => $month['Total'] ?? 0,
                'month_pct' => $m_pct,
                'plan_code' => $pkg['PkgType'] ?? '',
            ];
        }
    }
    
    return $results ? array_values($results) : [tm_error_item('tencent_codingplan', 'CAPI无套餐数据')];
}

/** 解析 DescribePkg 套餐数据为标准格式 */
function tm_tencent_parse_pkg_for_api(array $pkg): ?array {
    $name = $pkg['PkgName'] ?? '';
    $detail = $pkg['UsageDetail'] ?? [];
    $five_h = $detail['PerFiveHour'] ?? [];
    $week = $detail['PerWeek'] ?? [];
    $month = $detail['PerMonth'] ?? [];
    
    $calc_pct = function($d) { return ($d['Total'] ?? 0) > 0 ? round(($d['Used'] ?? 0) / $d['Total'] * 100, 1) : 0; };
    $f_pct = $calc_pct($five_h);
    $w_pct = $calc_pct($week);
    $m_pct = $calc_pct($month);
    $overall = max($f_pct, $w_pct, $m_pct);
    
    $refresh_at = '';
    if (!empty($five_h['EndTime'])) {
        $refresh_at = date('Y-m-d H:i:s', strtotime($five_h['EndTime']));
    }
    
    $quotas = [];
    if (isset($five_h['Total'])) {
        $quotas['5h'] = ['total'=>$five_h['Total'],'used'=>$five_h['Used']??0,'used_pct'=>$f_pct,'remaining'=>max(0,$five_h['Total']-($five_h['Used']??0)),'refresh_at'=>$refresh_at];
    }
    if (isset($week['Total'])) {
        $quotas['weekly'] = ['total'=>$week['Total'],'used'=>$week['Used']??0,'used_pct'=>$w_pct,'remaining'=>max(0,$week['Total']-($week['Used']??0))];
    }
    if (isset($month['Total'])) {
        $quotas['monthly'] = ['total'=>$month['Total'],'used'=>$month['Used']??0,'used_pct'=>$m_pct,'remaining'=>max(0,$month['Total']-($month['Used']??0))];
    }
    
    return [
        'platform' => 'tencent_codingplan',
        'total_tokens' => $month['Total'] ?? 0,
        'input_tokens' => $month['Used'] ?? 0,
        'remaining' => "5h:{$f_pct}% | 周:{$w_pct}% | 月:{$m_pct}%",
        'remaining_pct' => 100 - $overall,
        'quotas' => $quotas,
        'plan_name' => $name ?: 'Coding Plan',
        'valid_from' => $pkg['StartTime'] ?? '',
        'valid_to' => $pkg['EndTime'] ?? '',
        'plan_status' => $pkg['Status'] ?? '',
        'month_used' => $month['Used'] ?? 0,
        'month_limit' => $month['Total'] ?? 0,
        'month_pct' => $m_pct,
        'plan_code' => $pkg['PkgType'] ?? '',
    ];
}

/** 解析 DescribeTokenPlanUsage 数据为标准格式 */
function tm_tencent_parse_plan_usage_for_api(array $item): ?array {
    $pkg = $item['TokenPlanPackage'] ?? [];
    $res = $item['TokenPlanResource'] ?? [];
    
    $capacity = intval($res['CycleCapacity'] ?? 0);
    $total_usage = intval($res['CycleTotalUsage'] ?? 0);
    $input_usage = intval($res['CycleInputUsage'] ?? 0);
    $output_usage = intval($res['CycleOutputUsage'] ?? 0);
    $remain = intval($res['CycleRemain'] ?? 0);
    
    $plan_id = $pkg['Plan'] ?? '';
    $plan_names = ['tp_hy_standard' => 'Hy Standard', 'tp_lite' => 'Lite', 'tp_pro' => 'Pro'];
    $plan_name = $plan_names[$plan_id] ?? $plan_id;
    $is_hy = strpos($plan_id, 'hy') !== false || $plan_id === 'tp_hy_standard';
    
    $remaining_pct = $capacity > 0 ? round($remain / $capacity * 100, 1) : 0;
    $platform = $is_hy ? 'tencent_hy_tokenplan' : 'tencent_tokenplan';
    
    return [
        'platform' => $platform,
        'total_tokens' => $capacity,
        'input_tokens' => $total_usage,
        'output_tokens' => $remain,
        'cost' => 0,
        'remaining' => $remaining_pct . '% (' . tm_fmt_tokens($remain) . ')',
        'plan_name' => $is_hy ? 'hy_tokenplan' : 'tokenplan',
        'plan_type' => $plan_name,
        'remaining_pct' => $remaining_pct,
        'daily_usage' => $res['DailyUsageList'] ?? [],
        'start_time' => $pkg['StartTime'] ?? '',
        'expire_time' => $pkg['ExpireTime'] ?? '',
    ];
}

function tm_collect_tencent(string $cookie_str): array {
    // 解析凭证
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) $cred = ['cookie' => $cookie_str];

    $cookie = $cred['cookie'] ?? '';
    $uin = $cred['uin'] ?? tm_extract_cookie_value($cookie_str, 'uin');
    $ownerUin = $cred['ownerUin'] ?? $uin;
    $csrfCode = $cred['csrfCode'] ?? tm_extract_cookie_value($cookie_str, 'csrfCode');

    // 如果凭证里的 cookie 字段是 Netscape 格式，自行解析提取关键值
    $check_target = $cookie ?: $cookie_str;
    if (strpos($check_target, '# Netscape') !== false || strpos($check_target, "\t") !== false) {
        $lines = explode("\n", str_replace("\r\n", "\n", $check_target));
        $cookies = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || $line[0] === '#') continue;
            $parts = explode("\t", $line);
            if (count($parts) >= 7) {
                $name = $parts[5];
                $value = $parts[6];
                $cookies[$name] = $value;
            }
        }
        // 从 Netscape 中提取关键字段
        if (!$uin && isset($cookies['uin'])) $uin = ltrim($cookies['uin'], 'oO');
        if (!$ownerUin && isset($cookies['ownerUin'])) $ownerUin = ltrim($cookies['ownerUin'], 'oO');
        if (!$csrfCode && isset($cookies['qcmainCSRFToken'])) $csrfCode = $cookies['qcmainCSRFToken'];
        if (!$cookie) $cookie = implode('; ', array_map(fn($k,$v) => "$k=$v", array_keys($cookies), $cookies));
    }

    // 去除 uin 前缀的 o/O
    $uin = ltrim($uin, 'oO');

    if (!$cookie || !$uin || !$csrfCode) {
        $error = '凭证格式错误，需要Cookie+uin+ownerUin+csrfCode';
        return [
            tm_error_item('tencent_codingplan', $error),
        ];
    }

    $headers = [
        'Cookie' => $cookie,
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Referer' => 'https://console.cloud.tencent.com/tokenhub/codingplan',
        'Accept' => 'application/json, text/javascript, */*; q=0.01',
        'Content-Type' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
    $params_base = [
        'action' => 'delegate', 'secure' => '1', 'version' => '3',
        'json' => '1', 'dictId' => '3216', 'sts' => '1',
        'uin' => $uin, 'ownerUin' => $ownerUin, 'csrfCode' => $csrfCode,
    ];

    $results = [];
    $cookie_expired = false;

    // 1. Coding Plan (DescribePkg)
    $cp = tm_tencent_capi_call($headers, $params_base, 'DescribePkg');
    if ($cp && !isset($cp['_error'])) {
        $data = tm_tencent_parse_coding_plan($cp);
        if ($data) {
            $data['platform'] = 'tencent_codingplan';
            $results[] = $data;
        } else {
            $results[] = tm_error_item('tencent_codingplan', 'Coding Plan 数据为空');
        }
    } else {
        $expired = ($cp['_expired'] ?? false);
        $msg = $expired ? 'Cookie 已失效，请重新获取' : ($cp['_error'] ?? 'Coding Plan 查询失败');
        $results[] = tm_error_item('tencent_codingplan', $msg, $expired);
        if ($expired) $cookie_expired = true;
    }

    return $results;
}

function tm_tencent_capi_call(array $headers, array $params_base, string $cmd, array $extra_data=[]): array {
    $params = $params_base;
    $params['cmd'] = $cmd;
    $params['t'] = strval(intval(microtime(true) * 1000));

    $body = [
        'regionId' => 1,
        'serviceType' => 'hunyuan',
        'cmd' => $cmd,
        'data' => array_merge(['Version' => '2023-09-01', 'Language' => 'zh-CN'], $extra_data),
    ];

    $resp = tm_http_post(TENCENT_CAPI_URL, $headers, $body, 20, $params);
    if (!$resp) return ['_error' => 'HTTP请求失败'];

    if ($resp['code'] !== 200) return ['_error' => "HTTP {$resp['code']}"];

    $raw = json_decode($resp['body'], true);
    if (!is_array($raw)) return ['_error' => '响应非JSON'];

    $code = $raw['code'] ?? -1;
    if ($code != 0) {
        $msg = $raw['message'] ?? '';
        $expired = in_array($code, [9, 60001]) || stripos($msg, 'login') !== false || stripos($msg, 'csrf') !== false;
        return ['_error' => "API code=$code, message=$msg", '_expired' => $expired];
    }

    // 嵌套提取 Response
    $inner = $raw['data'] ?? [];
    if (is_array($inner)) {
        $inner = $inner['data'] ?? $inner;
        if (is_array($inner) && isset($inner['Response'])) {
            $inner = $inner['Response'];
        }
    }
    return is_array($inner) ? $inner : ['_error' => '数据格式异常'];
}

function tm_tencent_parse_coding_plan(array $resp): ?array {
    $pkg_list = $resp['PkgList'] ?? [];
    if (!$pkg_list) return null;
    $pkg = $pkg_list[0];

    $data = [
        'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => $pkg['Price'] ?? 40, 'remaining' => '', 'plan_name' => 'codingplan',
        'plan_type' => $pkg['PkgName'] ?? 'Lite',
    ];

    $remaining_days = $pkg['RemainingDays'] ?? 0;
    if ($remaining_days) $data['remaining_days'] = intval($remaining_days);

    $quotas = [];
    $usage_detail = $pkg['UsageDetail'] ?? [];
    $mapping = ['PerFiveHour' => '5h', 'PerWeek' => 'weekly', 'PerMonth' => 'monthly'];
    foreach ($mapping as $api_key => $internal_key) {
        $detail = $usage_detail[$api_key] ?? null;
        if ($detail) {
            $quotas[$internal_key] = [
                'total' => intval($detail['Total'] ?? 0),
                'used_pct' => floatval($detail['UsagePercent'] ?? 0),
                'refresh_at' => $detail['EndTime'] ?? '',
            ];
        }
    }
    $data['quotas'] = $quotas;

    if (isset($quotas['monthly'])) {
        $data['total_tokens'] = $quotas['monthly']['total'];
        $data['input_tokens'] = intval($quotas['monthly']['total'] * $quotas['monthly']['used_pct'] / 100);
        $data['output_tokens'] = $quotas['monthly']['total'] - $data['input_tokens'];
    }

    $parts = [];
    if (isset($quotas['5h'])) $parts[] = "5h:" . number_format(100 - $quotas['5h']['used_pct'], 1) . "%";
    if (isset($quotas['weekly'])) $parts[] = "周:" . number_format(100 - $quotas['weekly']['used_pct'], 1) . "%";
    if (isset($quotas['monthly'])) $parts[] = "月:" . number_format(100 - $quotas['monthly']['used_pct'], 1) . "%";
    $data['remaining'] = implode(' | ', $parts);

    return $data;
}

function tm_tencent_parse_plan_usage(array $resp, string $plan_id): ?array {
    $usage_list = $resp['TokenPlanUsageList'] ?? [];
    if (!$usage_list) return null;
    $item = $usage_list[0];
    $pkg = $item['TokenPlanPackage'] ?? [];
    $res = $item['TokenPlanResource'] ?? [];

    $capacity = intval($res['CycleCapacity'] ?? 0);
    $total_usage = intval($res['CycleTotalUsage'] ?? 0);
    $remain = intval($res['CycleRemain'] ?? 0);
    $input_usage = intval($res['CycleInputUsage'] ?? 0);
    $output_usage = intval($res['CycleOutputUsage'] ?? 0);

    $plan_names = ['tp_hy_standard' => 'Hy Standard', 'tp_lite' => 'Lite', 'tp_pro' => 'Pro'];
    $plan_name = $plan_names[$plan_id] ?? $plan_id;
    $is_hy = strpos($plan_id, 'hy') !== false || $plan_id === 'tp_hy_standard';
    $remaining_pct = $capacity > 0 ? ($remain / $capacity * 100) : 0;

    return [
        'total_tokens' => $capacity,
        'input_tokens' => $total_usage,
        'output_tokens' => $remain,
        'cost' => 0,
        'remaining' => number_format($remaining_pct, 1) . '% (' . tm_fmt_tokens($remain) . ')',
        'plan_name' => $is_hy ? 'hy_tokenplan' : 'tokenplan',
        'plan_type' => $plan_name,
        'remaining_pct' => round($remaining_pct, 1),
        'start_time' => $pkg['StartTime'] ?? '',
        'expire_time' => $pkg['ExpireTime'] ?? '',
    ];
}

// ============ 火山引擎采集 ============

function tm_collect_volcano(string $cookie_str): array {
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) $cred = ['cookie' => $cookie_str];

    $ak = $cred['ak'] ?? '';
    $sk = $cred['sk'] ?? '';
    $cookie = $cred['cookie'] ?? '';

    // 自动检测 Netscape 格式并转换 Cookie 为 key=value; 格式
    if ($cookie && (strpos($cookie, '# Netscape') === 0 || strpos($cookie, "\t") !== false)) {
        $parsed = tm_parse_netscape($cookie);
        if ($parsed) $cookie = $parsed;
    }

    $results = [];

    // 1. Coding Plan + Agent Plan 合并采集（共享同一 Cookie）
    if ($cookie) {
        $cp = tm_volcano_collect_coding_plan($cookie);
        $ap = tm_volcano_collect_agent_plan($cookie);

        if ($cp && $ap) {
            // 合并：把 Agent Plan 的 AFP 配额挂到 Coding Plan 结果上
            $cp['afp_quotas'] = $ap['quotas'] ?? [];
            $cp['afp_cost'] = $ap['cost'] ?? 0;
            $cp['afp_plan_type'] = $ap['plan_type'] ?? 'Agent Plan';
            if (isset($ap['remaining_days'])) $cp['afp_remaining_days'] = $ap['remaining_days'];
            if (isset($ap['valid_to'])) $cp['afp_valid_to'] = $ap['valid_to'];
            $results[] = $cp;
        } elseif ($cp) {
            $results[] = $cp;
        } elseif ($ap) {
            // 只有 Agent Plan，用 volcano_codingplan 平台键
            $ap['platform'] = 'volcano_codingplan';
            $ap['afp_quotas'] = $ap['quotas'] ?? [];
            $ap['afp_cost'] = $ap['cost'] ?? 0;
            $ap['afp_plan_type'] = $ap['plan_type'] ?? 'Agent Plan';
            $ap['quotas'] = [];  // 空 quotas 让前端走 renderSubCodingPlan 分支
            $results[] = $ap;
        }
    }

    // 2. 余额: AK/SK
    if ($ak && $sk) {
        $bal = tm_volcano_query_balance($ak, $sk);
        if ($bal) $results[] = $bal;
    }

    if (!$results) {
        if (!$cookie && !($ak && $sk)) {
            return [tm_error_item('volcano', '缺少凭证：需要 Cookie（查Coding Plan/Agent Plan）或 AK/SK（查余额）')];
        }
        if ($cookie && !($ak && $sk)) {
            $results[] = tm_error_item('volcano_codingplan', 'Coding Plan/Agent Plan 查询失败，Cookie可能已失效');
        }
    }

    return $results;
}

function tm_volcano_collect_agent_plan(string $cookie_str): ?array {
    $csrf_token = tm_extract_cookie_value($cookie_str, 'csrfToken');

    $headers = [
        'Cookie' => $cookie_str,
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Content-Type' => 'application/json',
        'Referer' => 'https://console.volcengine.com/ark/region:ark+cn-beijing/openManagement?advancedActiveKey=agentPlan',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
    if ($csrf_token) {
        $headers['X-CSRF-Token'] = $csrf_token;
        $headers['X-Volc-CSRF'] = $csrf_token;
    }

    // GetAgentPlanAFPUsage
    $resp = tm_http_post(VOLCANO_CONSOLE_API . '/GetAgentPlanAFPUsage?', $headers, new stdClass());
    if (!$resp || $resp['code'] === 401 || $resp['code'] === 403) return null;
    if ($resp['code'] !== 200) return null;

    $data = json_decode($resp['body'], true);
    if (!$data) return null;
    $meta = $data['ResponseMetadata'] ?? [];
    if (isset($meta['Error'])) return null;

    $result = $data['Result'] ?? [];
    $plan_type = $result['PlanType'] ?? 'unknown';

    $level_map = [
        'AFPFiveHour' => ['name' => '每5小时', 'key' => '5h'],
        'AFPWeekly' => ['name' => '每周', 'key' => 'weekly'],
        'AFPMonthly' => ['name' => '每订阅月', 'key' => 'monthly'],
    ];

    $quotas = [];
    foreach ($level_map as $field => $info) {
        $item = $result[$field] ?? null;
        if (!$item) continue;
        $quota = floatval($item['Quota'] ?? 0);
        $used = floatval($item['Used'] ?? 0);
        $used_pct = $quota > 0 ? round($used / $quota * 100, 1) : 0;
        $reset_ts = $item['ResetTime'] ?? 0;
        $reset_at = $reset_ts ? date('Y-m-d H:i:s', intval($reset_ts / 1000)) : '';
        $quotas[$info['key']] = [
            'total' => $quota,
            'used' => $used,
            'used_pct' => $used_pct,
            'refresh_at' => $reset_at,
            'unit' => 'AFP',
        ];
    }

    if (!$quotas) return null;

    $biz_map = [
        'small' => ['name' => 'Small', 'price' => 40],
        'medium' => ['name' => 'Medium', 'price' => 200],
        'large' => ['name' => 'Large', 'price' => 500],
        'max' => ['name' => 'Max', 'price' => 2000],
    ];

    $result_data = [
        'platform' => 'volcano_agentplan',
        'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => 0, 'remaining' => '', 'quotas' => $quotas,
        'plan_type' => "Agent Plan", 'plan_name' => "Agent Plan",
        'unit' => 'AFP',
    ];

    // 从 PlanType 回填价格（ListSubscribeTrade 从服务器调用可能返回 null）
    $biz_info = $biz_map[$plan_type] ?? ['name' => ucfirst($plan_type), 'price' => 0];
    $result_data['cost'] = $biz_info['price'];
    $result_data['plan_type'] = "Agent Plan {$biz_info['name']}";

    // 订阅信息（尝试获取到期时间等）
    $sub_resp = tm_http_post(VOLCANO_CONSOLE_API . '/ListSubscribeTrade?', $headers,
        ['ResourceTypes' => ['AgentPlan'], 'ResourceNames' => [''], 'BizInfos' => ['small', 'medium', 'large', 'max']]);
    if ($sub_resp && $sub_resp['code'] === 200) {
        $sub_data = json_decode($sub_resp['body'], true);
        if ($sub_data && !isset($sub_data['ResponseMetadata']['Error'])) {
            $info_list = $sub_data['Result']['InfoList'] ?? [];
            if ($info_list) {
                $sub = $info_list[0];
                $biz = $sub['BizInfo'] ?? $plan_type;
                $sub_biz_info = $biz_map[$biz] ?? ['name' => ucfirst($biz), 'price' => 0];
                $result_data['cost'] = $sub_biz_info['price'];
                $result_data['plan_type'] = "Agent Plan {$sub_biz_info['name']}";
                $end = $sub['EndTime'] ?? '';
                if ($end) {
                    $result_data['valid_to'] = substr($end, 0, 10);
                    try {
                        $end_ts = strtotime(substr($end, 0, 19));
                        if ($end_ts) {
                            $remaining_days = intval(($end_ts - time()) / 86400);
                            if ($remaining_days > 0) $result_data['remaining_days'] = $remaining_days;
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    }

    // remaining 摘要
    $parts = [];
    foreach (['5h', 'weekly', 'monthly'] as $key) {
        if (isset($quotas[$key])) $parts[] = "$key:" . number_format(100 - $quotas[$key]['used_pct'], 1) . "%";
    }
    $result_data['remaining'] = implode(' | ', $parts);

    return $result_data;
}

function tm_volcano_collect_coding_plan(string $cookie_str): ?array {
    $csrf_token = tm_extract_cookie_value($cookie_str, 'csrfToken');

    $headers = [
        'Cookie' => $cookie_str,
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Content-Type' => 'application/json',
        'Referer' => 'https://console.volcengine.com/ark/region:ark+cn-beijing/openManagement',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
    if ($csrf_token) {
        $headers['X-CSRF-Token'] = $csrf_token;
        $headers['X-Volc-CSRF'] = $csrf_token;
    }

    // GetCodingPlanUsage
    $resp = tm_http_post(VOLCANO_CONSOLE_API . '/GetCodingPlanUsage?', $headers, new stdClass());
    if (!$resp || $resp['code'] === 401 || $resp['code'] === 403) return null;
    if ($resp['code'] !== 200) return null;

    $data = json_decode($resp['body'], true);
    if (!$data) return null;
    $meta = $data['ResponseMetadata'] ?? [];
    if (isset($meta['Error'])) return null;

    $result = $data['Result'] ?? [];
    $quota_usage = $result['QuotaUsage'] ?? [];
    if (!$quota_usage) return null;

    $level_map = [
        'session' => ['name' => '每5小时', 'key' => '5h'],
        'weekly' => ['name' => '每周', 'key' => 'weekly'],
        'monthly' => ['name' => '每订阅月', 'key' => 'monthly'],
    ];

    $quotas = [];
    foreach ($quota_usage as $item) {
        $level = $item['Level'] ?? '';
        $m = $level_map[$level] ?? ['name' => $level, 'key' => $level];
        $pct = floatval($item['Percent'] ?? 0);
        $reset_ts = $item['ResetTimestamp'] ?? 0;
        $reset_at = $reset_ts ? date('Y-m-d H:i:s', $reset_ts) : '';
        $quotas[$m['key']] = [
            'total' => 0,
            'used_pct' => round($pct, 1),
            'refresh_at' => $reset_at,
        ];
    }

    $result_data = [
        'platform' => 'volcano_codingplan',
        'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => 0, 'remaining' => '', 'quotas' => $quotas,
        'plan_type' => 'Coding Plan', 'plan_name' => 'Coding Plan',
    ];

    // 订阅信息
    $sub_resp = tm_http_post(VOLCANO_CONSOLE_API . '/ListSubscribeTrade?', $headers,
        ['ResourceTypes' => ['CodingPlan'], 'ResourceNames' => [''], 'BizInfos' => ['lite', 'pro']]);
    if ($sub_resp && $sub_resp['code'] === 200) {
        $sub_data = json_decode($sub_resp['body'], true);
        if ($sub_data && !isset($sub_data['ResponseMetadata']['Error'])) {
            $info_list = $sub_data['Result']['InfoList'] ?? [];
            if ($info_list) {
                $sub = $info_list[0];
                $biz = $sub['BizInfo'] ?? 'lite';
                $biz_map = ['lite' => ['name' => 'Lite', 'price' => 40], 'pro' => ['name' => 'Pro', 'price' => 200]];
                $biz_info = $biz_map[$biz] ?? ['name' => $biz, 'price' => 0];
                $result_data['cost'] = $biz_info['price'];
                $result_data['plan_type'] = "Coding Plan {$biz_info['name']}";
                $end = $sub['EndTime'] ?? '';
                if ($end) {
                    $result_data['valid_to'] = substr($end, 0, 10);
                    try {
                        $end_ts = strtotime(substr($end, 0, 19));
                        if ($end_ts) {
                            $remaining_days = intval(($end_ts - time()) / 86400);
                            if ($remaining_days > 0) $result_data['remaining_days'] = $remaining_days;
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    }

    // remaining 摘要（改为剩余百分比，与字段名一致）
    $parts = [];
    foreach (['5h', 'weekly', 'monthly'] as $key) {
        if (isset($quotas[$key])) $parts[] = "$key:" . number_format(100 - $quotas[$key]['used_pct'], 1) . "%";
    }
    $result_data['remaining'] = implode(' | ', $parts);

    return $result_data;
}

function tm_volcano_query_balance(string $ak, string $sk): ?array {
    // 火山引擎 AK/SK 签名 (HMAC-SHA256)
    $host = VOLCANO_BILLING_HOST;
    $service = VOLCANO_BILLING_SERVICE;
    $region = VOLCANO_BILLING_REGION;
    $action = VOLCANO_BILLING_ACTION;
    $version = VOLCANO_BILLING_VERSION;

    $now = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');

    // 请求体
    $body_str = '{}';
    $content_sha256 = hash('sha256', $body_str);

    // Canonical request
    $canonical_uri = '/';
    $canonical_querystring = "Action={$action}&Version={$version}";
    $canonical_headers = "content-type:application/json\nhost:{$host}\nx-content-sha256:{$content_sha256}\nx-date:{$now}\n";
    $signed_headers = 'content-type;host;x-content-sha256;x-date';
    $canonical_request = "POST\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$content_sha256}";

    // String to sign
    $credential_scope = "{$date}/{$region}/{$service}/request";
    $string_to_sign = "HMAC-SHA256\n{$now}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

    // Signing key
    $k_date = hash_hmac('sha256', $date, $sk, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'request', $k_service, true);

    // Signature
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $auth_header = "HMAC-SHA256 Credential={$ak}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

    $headers = [
        'Host' => $host,
        'Content-Type' => 'application/json',
        'X-Date' => $now,
        'X-Content-Sha256' => $content_sha256,
        'Authorization' => $auth_header,
    ];

    $url = "https://{$host}/?Action={$action}&Version={$version}";
    $resp = tm_http_post($url, $headers, $body_str, 15);

    if (!$resp || $resp['code'] !== 200) return null;

    $data = json_decode($resp['body'], true);
    if (!$data) return null;

    // 检查错误
    $meta = $data['ResponseMetadata'] ?? [];
    if (isset($meta['Error'])) return null;

    $result = $data['Result'] ?? $data;

    $available = floatval($result['AvailableBalance'] ?? $result['available_balance'] ?? 0);
    $cash = floatval($result['CashBalance'] ?? $result['cash_balance'] ?? 0);
    $credit = floatval($result['CreditLimit'] ?? $result['credit_limit'] ?? 0);
    $frozen = floatval($result['FreezeAmount'] ?? $result['freeze_amount'] ?? 0);
    $arrears = floatval($result['ArrearsBalance'] ?? $result['arrears_balance'] ?? 0);

    return [
        'platform' => 'volcano',
        'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => 0,
        'remaining' => "可用 ¥" . number_format($available, 2),
        'balance_available' => $available,
        'balance_cash' => $cash,
        'balance_credit' => $credit,
        'balance_frozen' => $frozen,
        'balance_arrears' => $arrears,
        'plan_type' => 'pay_as_you_go',
        'plan_name' => '火山方舟',
    ];
}

// ============ 小米 MIMO 采集 ============

function tm_collect_xiaomi(string $cookie_str): array {
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) $cred = ['cookie' => $cookie_str];
    $cookie = $cred['cookie'] ?? '';
    // Netscape 格式转 Cookie 头
    if ($cookie && (strpos($cookie, "\t") !== false || strpos($cookie, '# Netscape') === 0)) {
        $parsed = tm_parse_netscape($cookie);
        if ($parsed) $cookie = $parsed;
    }
    if (!$cookie) return [tm_error_item('xiaomi', 'Cookie为空，请先登录小米MiMo平台')];

    $headers = [
        'Cookie' => $cookie,
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Language' => 'zh-CN,zh;q=0.9',
        'Referer' => 'https://platform.xiaomimimo.com/console/plan-manage',
        'Origin' => 'https://platform.xiaomimimo.com',
    ];

    $result = [
        'platform' => 'xiaomi',
        'total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
        'cost' => 0, 'remaining' => '-',
    ];

    // 1. Token Plan 详情（鉴权接口，失败即视为 Cookie 失效 → 返回错误，不覆盖历史好数据）
    $plan = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/tokenPlan/detail', $headers);
    if ($plan === null) {
        return [tm_error_item('xiaomi', 'Token Plan 详情接口网络请求失败，Cookie可能已失效')];
    }
    if (isset($plan['_expired'])) {
        return [tm_error_item('xiaomi', 'Cookie已失效，请重新登录小米平台', true)];
    }
    if (isset($plan['_fail'])) {
        return [tm_error_item('xiaomi', 'Token Plan 详情接口失败（' . $plan['_fail'] . '），Cookie可能已失效')];
    }
    // $plan 为真实数据数组
    $d = $plan['data'] ?? [];
    $result['plan_type'] = $d['planName'] ?? '';
    $result['plan_code'] = $d['planCode'] ?? '';
    $result['auto_renew'] = $d['enableAutoRenew'] ?? false;
    $end_str = $d['currentPeriodEnd'] ?? '';
    if ($end_str) {
        $result['valid_to'] = substr($end_str, 0, 10);
        $end_ts = strtotime(substr($end_str, 0, 19));
        if ($end_ts) {
            $remaining_days = intval(($end_ts - time()) / 86400);
            if ($remaining_days > 0) $result['remaining_days'] = $remaining_days;
        }
    }
    $plan_prices = ['lite' => 39, 'pro' => 199];
    $plan_code = $d['planCode'] ?? '';
    if (isset($plan_prices[$plan_code])) $result['cost'] = $plan_prices[$plan_code];

    $usage_failed = false;

    // 2. Token Plan 用量
    $usage = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/tokenPlan/usage', $headers);
    if ($usage && !isset($usage['_expired']) && !isset($usage['_fail'])) {
        $d = $usage['data'] ?? [];
        // 月度用量
        $month_items = $d['monthUsage']['items'] ?? [];
        if ($month_items) {
            $mi = $month_items[0];
            $result['month_used'] = $mi['used'] ?? 0;
            $result['month_limit'] = $mi['limit'] ?? 0;
            $result['month_pct'] = round(($mi['percent'] ?? 0) * 100, 1);
        }
        // 套餐+补偿分项
        $usage_items = $d['usage']['items'] ?? [];
        foreach ($usage_items as $item) {
            $name = $item['name'] ?? '';
            if ($name === 'plan_total_token') {
                $result['total_tokens'] = $item['limit'] ?? 0;
                $result['input_tokens'] = $item['used'] ?? 0;
                $pct = $item['percent'] ?? 0;
                $result['remaining_pct'] = round((1 - $pct) * 100, 1);
                $result['plan_pct'] = round($pct * 100, 1);
            } elseif ($name === 'compensation_total_token') {
                $result['comp_total'] = $item['limit'] ?? 0;
                $result['comp_used'] = $item['used'] ?? 0;
                $result['comp_pct'] = round(($item['percent'] ?? 0) * 100, 1);
            }
        }
        if (isset($result['remaining_pct'])) {
            $result['remaining'] = number_format($result['remaining_pct'], 1) . '%';
        }
    } else {
        $usage_failed = true;
    }

    // 3. 通用用量（速率限制）
    $gen_usage = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/usage', $headers);
    if ($gen_usage && !isset($gen_usage['_expired']) && !isset($gen_usage['_fail'])) {
        $d = $gen_usage['data'] ?? [];
        $result['tpm'] = $d['accountRateLimit']['tpm'] ?? 0;
        $result['rpm'] = $d['accountRateLimit']['rpm'] ?? 0;
        $result['cache_tokens'] = $d['tokenUsage']['cacheToken'] ?? 0;
        $cost_usage = $d['costUsage'] ?? [];
        if (!isset($result['current_month_cost'])) {
            $result['current_month_cost'] = floatval($cost_usage['currentMonthCost'] ?? 0);
        }
    }

    // 4. 余额
    $bal = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/balance', $headers);
    if ($bal && !isset($bal['_expired']) && !isset($bal['_fail'])) {
        $d = $bal['data'] ?? [];
        $result['balance'] = floatval($d['balance'] ?? 0);
        $result['gift_balance'] = floatval($d['giftBalance'] ?? 0);
        $result['cash_balance'] = floatval($d['cashBalance'] ?? 0);
        $result['frozen_balance'] = floatval($d['frozenBalance'] ?? 0);
    }

    // 若用量接口失败且未取到任何 Token 数据，视为采集不完整 → 返回错误，避免用 0 覆盖历史好数据
    if ($usage_failed && empty($result['total_tokens']) && !isset($result['remaining_pct'])) {
        return [tm_error_item('xiaomi', 'Token Plan 用量接口失败，Cookie可能已失效', true)];
    }

    return [$result];
}

function tm_xiaomi_api_get(string $url, array $headers): ?array {
    // 返回: 真实数据数组(含 data/code) / ['_expired'=>true](401 Cookie过期) / ['_fail'=>原因](其他失败) / null(网络失败)
    $resp = tm_http_get($url, $headers);
    if (!$resp) return null;
    if ($resp['code'] === 401) return ['_expired' => true];
    if ($resp['code'] !== 200) return ['_fail' => 'http_' . $resp['code']];
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || ($data['code'] ?? -1) !== 0) return ['_fail' => 'code_' . ($data['code'] ?? 'null')];
    return $data;
}

// ============ DeepSeek 采集 ============

function tm_collect_deepseek(string $cookie_str): array {
    // 解析凭证: JSON 格式、Netscape 格式、或纯文本
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) {
        $raw = trim($cookie_str);
        // DeepSeek 不支持 Netscape Cookie，只接受 sk- 或 token
        if (strpos($raw, 'sk-') === 0) {
            $cred = ['api_key' => $raw];
        } elseif (strlen($raw) > 20) {
            // 是 Token（userToken from localStorage）
            $cred = ['token' => $raw];
        } else {
            $cred = ['token' => $raw];
        }
    }

    $api_key = $cred['api_key'] ?? '';
    $token = $cred['token'] ?? '';
    $cookie = $cred['cookie'] ?? '';
    $raw = $cred['raw'] ?? '';

    // raw 兜底
    if (!$api_key && !$token && !$cookie && $raw) {
        $raw = trim($raw);
        if (strpos($raw, 'sk-') === 0) {
            $api_key = $raw;
        } else {
            $token = $raw;
        }
    }

    // 模式1: 有 Token → 查用量明细（如果有 API Key 也查余额）
    if ($token) {
        $result = tm_collect_deepseek_usage($token);
        if ($api_key && !isset($result[0]['error'])) {
            $balance = tm_collect_deepseek_balance($api_key);
            if (!isset($balance[0]['error'])) {
                $result[0]['balance'] = $balance[0]['balance'] ?? 0;
                $result[0]['granted_balance'] = $balance[0]['granted_balance'] ?? 0;
                $result[0]['topped_up_balance'] = $balance[0]['topped_up_balance'] ?? 0;
            }
        }
        return $result;
    }

    // 模式2: 只有 API Key → 查余额
    if ($api_key) {
        return tm_collect_deepseek_balance($api_key);
    }

    // 模式3: Cookie → 尝试提取 userToken 再查用量
    if ($cookie) {
        // 如果是 Netscape 格式，先尝试用 cookie 访问页面提取 userToken
        $actual_token = $cookie;
        if (strpos($cookie, '# Netscape') === 0) {
            // 解析 Netscape → key=value 字符串
            $lines = explode("\n", $cookie);
            $cookies = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || $line[0] === '#') continue;
                $parts = explode("\t", $line);
                if (count($parts) >= 7) {
                    $cookies[] = $parts[5] . '=' . $parts[6];
                }
            }
            $cookie_str = implode('; ', $cookies);
            
            // 用 cookie 访问 DS 平台页面，尝试提取 userToken
            $page_resp = tm_http_get('https://platform.deepseek.com/usage', [
                'Cookie' => $cookie_str,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ]);
            
            if ($page_resp && $page_resp['code'] === 200) {
                // 尝试从页面 JS 或 API 响应中提取 userToken
                $body = $page_resp['body'];
                // 从 localStorage 注入、/api/user/token 等地方提取
                if (preg_match('/userToken["\']?\s*[:=]\s*["\']([^"\']{20,})["\']/', $body, $m)) {
                    $actual_token = $m[1];
                }
            }
        }
        
        // 用 token 查用量
        if ($actual_token && strlen($actual_token) >= 20 && $actual_token !== $cookie) {
            $result = tm_collect_deepseek_usage($actual_token);
            if (!isset($result[0]['error'])) {
                return $result;
            }
        }
        // 兜底：原样当 token 试
        $result = tm_collect_deepseek_usage($cookie);
        if (!isset($result[0]['error'])) {
            return $result;
        }
    }

    return [tm_error_item('deepseek', '请提供 API Key 或 Token')];
}

/**
 * 模式1: API Key 查余额
 */
function tm_collect_deepseek_balance(string $api_key): array {
    $headers = [
        'Authorization' => "Bearer $api_key",
        'Accept' => 'application/json',
    ];

    $resp = tm_http_get('https://api.deepseek.com/user/balance', $headers);

    $result = [
        'platform' => 'deepseek',
        'total_tokens' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'remaining' => '-',
    ];

    if (!$resp) return [tm_error_item('deepseek', '请求失败（网络错误）')];
    if ($resp['code'] === 401) return [tm_error_item('deepseek', 'API Key 无效，请检查后重试')];
    if ($resp['code'] !== 200) return [tm_error_item('deepseek', "请求失败 (HTTP {$resp['code']})")];

    $data = json_decode($resp['body'], true);
    if (!is_array($data)) return [tm_error_item('deepseek', '响应解析失败')];

    if (!($data['is_available'] ?? false)) {
        $result['remaining'] = '¥0.00（已用完）';
    } else {
        foreach ($data['balance_infos'] ?? [] as $bi) {
            if (($bi['currency'] ?? '') === 'CNY') {
                $total = floatval($bi['total_balance'] ?? '0');
                $granted = floatval($bi['granted_balance'] ?? '0');
                $topped = floatval($bi['topped_up_balance'] ?? '0');
                $result['balance'] = $total;
                $result['granted_balance'] = $granted;
                $result['topped_up_balance'] = $topped;
                $result['remaining'] = '¥' . number_format($total, 2);
                break;
            }
        }
    }

    $result['raw_json'] = json_encode($data, JSON_UNESCAPED_UNICODE);
    return [$result];
}

/**
 * 模式2: UserToken 查用量明细
 */
function tm_collect_deepseek_usage(string $token): array {
    // userToken 可能是 JSON 格式 {"value":"xxx"} 或纯文本
    $parsed = json_decode($token, true);
    if (is_array($parsed) && isset($parsed['value'])) {
        $token = $parsed['value'];
    }
    $headers = [
        'Authorization' => "Bearer $token",
        'Accept' => 'application/json',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Origin' => 'https://platform.deepseek.com',
        'Referer' => 'https://platform.deepseek.com/usage',
    ];

    $now = (int)date('n');  // month
    $year = (int)date('Y');

    $result = [
        'platform' => 'deepseek',
        'total_tokens' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'cost_total' => 0,
        'remaining' => '-',
    ];

    // 1. 查用量（月初当月无数据时回退到上月）
    $resp = tm_http_get("https://platform.deepseek.com/api/v0/usage/amount?month=$now&year=$year", $headers);
    if ($resp && $resp['code'] === 200) {
        $amount_data = json_decode($resp['body'], true);
        if (is_array($amount_data) && ($amount_data['code'] ?? -1) === 0) {
            $biz_data = $amount_data['data']['biz_data']['total'] ?? [];
            // 判断是否"无数据"：所有模型的 amount 都为 0 或空
            $has_real_data = false;
            foreach ($biz_data as $model_entry) {
                foreach (($model_entry['usage'] ?? []) as $u) {
                    if (floatval($u['amount'] ?? 0) > 0) { $has_real_data = true; break 2; }
                }
            }
            if (!$has_real_data && $now === 1) {
                $now = 12; $year--;
                $resp = tm_http_get("https://platform.deepseek.com/api/v0/usage/amount?month=$now&year=$year", $headers);
                if ($resp && $resp['code'] === 200) $amount_data = json_decode($resp['body'], true);
            } elseif (!$has_real_data && $now > 1) {
                $now--;
                $resp = tm_http_get("https://platform.deepseek.com/api/v0/usage/amount?month=$now&year=$year", $headers);
                if ($resp && $resp['code'] === 200) $amount_data = json_decode($resp['body'], true);
            }
        }
    }
    if (!$resp || $resp['code'] !== 200) {
        $code = $resp ? $resp['code'] : 0;
        return [tm_error_item('deepseek', "用量查询失败 (HTTP $code)")];
    }

    $amount_data = json_decode($resp['body'], true);
    if (!is_array($amount_data) || ($amount_data['code'] ?? -1) !== 0) {
        $msg = $amount_data['msg'] ?? '用量查询失败';
        if (stripos($msg, 'token') !== false) {
            return [tm_error_item('deepseek', 'Token 已过期，请重新登录获取')];
        }
        return [tm_error_item('deepseek', $msg)];
    }

    // 2. 查费用（也回退到同一月份）
    $resp_cost = tm_http_get("https://platform.deepseek.com/api/v0/usage/cost?month=$now&year=$year", $headers);
    $cost_biz_data = [];
    if ($resp_cost && $resp_cost['code'] === 200) {
        $cost_data = json_decode($resp_cost['body'], true);
        $cost_totals = $cost_data['data']['biz_data'][0]['total'] ?? [];
        foreach ($cost_totals as $ce) {
            $model = $ce['model'] ?? '';
            $cost_biz_data[$model] = [];
            foreach ($ce['usage'] ?? [] as $u) {
                $cost_biz_data[$model][$u['type']] = floatval($u['amount']);
            }
        }
    }

    // 解析用量
    $totals = $amount_data['data']['biz_data']['total'] ?? [];
    $model_usages = [];
    $total_cost = 0.0;
    $total_tokens = 0;

    foreach ($totals as $me) {
        $model = $me['model'] ?? '';

        $usage = [];
        foreach ($me['usage'] ?? [] as $u) {
            $usage[$u['type']] = intval($u['amount']);
        }

        $cost_map = $cost_biz_data[$model] ?? [];

        $hit = $usage['PROMPT_CACHE_HIT_TOKEN'] ?? 0;
        $miss = $usage['PROMPT_CACHE_MISS_TOKEN'] ?? 0;
        $resp_tok = $usage['RESPONSE_TOKEN'] ?? 0;
        $requests = $usage['REQUEST'] ?? 0;

        $cost_hit = $cost_map['PROMPT_CACHE_HIT_TOKEN'] ?? 0;
        $cost_miss = $cost_map['PROMPT_CACHE_MISS_TOKEN'] ?? 0;
        $cost_resp = $cost_map['RESPONSE_TOKEN'] ?? 0;
        $model_cost = $cost_hit + $cost_miss + $cost_resp;
        $total_cost += $model_cost;
        $total_tokens += $hit + $miss + $resp_tok;

        $model_usages[] = [
            'model' => $model,
            'prompt_cache_hit' => $hit,
            'prompt_cache_miss' => $miss,
            'response' => $resp_tok,
            'requests' => $requests,
            'cost_hit' => round($cost_hit, 4),
            'cost_miss' => round($cost_miss, 4),
            'cost_resp' => round($cost_resp, 4),
            'cost_total' => round($model_cost, 4),
        ];
    }

    // 构建摘要
    $summary_lines = [];
    foreach ($model_usages as $mu) {
        $summary_lines[] = sprintf(
            "%s: ↑%s (cache:%s) ↓%s | ¥%.2f",
            $mu['model'],
            number_format($mu['prompt_cache_miss']),
            number_format($mu['prompt_cache_hit']),
            number_format($mu['response']),
            $mu['cost_total']
        );
    }

    $result['total_tokens'] = $total_tokens;
    $result['input_tokens'] = array_sum(array_column($model_usages, 'prompt_cache_miss')) + array_sum(array_column($model_usages, 'prompt_cache_hit'));
    $result['output_tokens'] = array_sum(array_column($model_usages, 'response'));
    $result['cost'] = round($total_cost, 2);
    $result['cost_total'] = round($total_cost, 2);
    $result['monthly_cost'] = round($total_cost, 2);
    $result['model_usages'] = $model_usages;
    $result['raw_json'] = json_encode($amount_data, JSON_UNESCAPED_UNICODE);

    return [$result];
}

// ============ 通用 ============

function tm_error_item(string $key, string $msg, bool $cookie_expired=false): array {
    $item = ['platform' => $key, 'error' => $msg];
    if ($cookie_expired) $item['cookie_expired'] = true;
    return $item;
}

/**
 * 把任意格式的 cookie 凭证归一化为标准 Cookie 请求头字符串。
 * - Netscape 格式（# Netscape HTTP Cookie File / tab 分隔）→ 转成 name=value; name=value
 * - 已是 name=value; ... 头部格式 → 原样返回
 * - JSON 凭证（api_key / token 类，以 { 开头）→ 原样返回（非 cookie）
 * 这样 Chrome 插件导出的 Netscape cookie 可直接粘贴使用。
 */
function tm_normalize_cookie(string $cookie): string {
    $cookie = trim($cookie);
    if ($cookie === '') return $cookie;
    // JSON 凭证（api_key / token）原样透传
    if (str_starts_with($cookie, '{')) return $cookie;
    // 已是头部格式（无 tab、无 Netscape 注释）→ 直接返回
    if (strpos($cookie, "\t") === false && stripos($cookie, '# Netscape') === false) {
        return $cookie;
    }
    // ---- Netscape 格式转换 ----
    $pairs = [];
    foreach (explode("\n", $cookie) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // 部分导出器把 HttpOnly cookie 的域名写成 #HttpOnly_.example.com（这不是注释）
        if (str_starts_with($line, '#HttpOnly_')) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif (str_starts_with($line, '#')) {
            continue; // 真正的注释行（# Netscape ...）
        }
        if (strpos($line, "\t") !== false) {
            $cols = explode("\t", $line);
        } else {
            $cols = preg_split('/\s+/', $line);
        }
        if (count($cols) < 7) continue;
        $name = $cols[5];
        $value = trim(implode(' ', array_slice($cols, 6))); // 值里可能含空格
        if ($name === '') continue;
        $pairs[] = $name . '=' . $value;
    }
    return implode('; ', $pairs);
}

// ============ 主刷新逻辑 ============

function tm_do_refresh_all(): array {
    require_once __DIR__ . '/db.php';
    $results = [];
    $creds = tm_list_credentials();
    $batch_ts = round(microtime(true), 1); // 批次标记，微秒精度防跳

    foreach ($creds as $cred) {
        $platform = $cred['platform'];
        $cred_data = tm_get_merged_credential_data($platform);
        if (!$cred_data) continue;

        $cookie_str = tm_normalize_cookie(isset($cred_data['raw']) ? $cred_data['raw'] : json_encode($cred_data, JSON_UNESCAPED_UNICODE));
        $start = microtime(true);

        try {
            // 检查是否有 api_data（插件CAPI拦截的数据），并做保鲜检测
            $api_data_raw = null;
            $api_data_age_status = null;
            if ($platform === 'tencent') {
                $all_creds = tm_get_all_credentials('tencent');
                foreach ($all_creds as $ac) {
                    if ($ac['credential_type'] === 'api_data') {
                        $api_data_raw = $ac['credential_data'];
                        // 检查 api_data 新鲜度
                        $age_info = tm_check_api_data_age('tencent');
                        $api_data_age_status = $age_info['status'];
                        if ($age_info['status'] === 'critical') {
                            // 严重过期：自动删除 api_data，降级为 Cookie 采集
                            tm_delete_credential('tencent', 'api_data');
                            $api_data_raw = null;
                            tm_add_refresh_log('tencent', 'warning', $age_info['message'], 0);
                            error_log("[TokenMonitor] " . $age_info['message']);
                        } elseif ($age_info['status'] === 'stale') {
                            // 过期即失效：删除 api_data，降级为 Cookie 采集
                            tm_delete_credential('tencent', 'api_data');
                            $api_data_raw = null;
                            tm_add_refresh_log('tencent', 'warning', $age_info['message'], 0);
                            error_log("[TokenMonitor] " . $age_info['message']);
                        }
                        break;
                    }
                }
            }

            $items = match($platform) {
                'tencent' => $api_data_raw ? tm_collect_tencent_from_api_data($api_data_raw) : tm_collect_tencent($cookie_str),
                'volcano' => tm_collect_volcano($cookie_str),
                'xiaomi' => tm_collect_xiaomi($cookie_str),
                'deepseek' => tm_collect_deepseek($cookie_str),
                'minimax' => tm_collect_minimax($cookie_str),
                'gpt_gateway' => tm_collect_gpt_gateway($cookie_str),
                default => [tm_error_item($platform, "不支持的平台: $platform")],
            };

            $duration = intval((microtime(true) - $start) * 1000);

            foreach ($items as $item) {
                $sub_platform = $item['platform'] ?? $platform;
                if (isset($item['error'])) {
                    $cookie_expired = $item['cookie_expired'] ?? false;
                    $log_msg = $item['error'];
                    if ($cookie_expired) $log_msg = "🍪 " . $log_msg;
                    tm_add_refresh_log($sub_platform, 'failed', $log_msg, $duration);
                    $results[$sub_platform] = [
                        'status' => 'failed', 'error' => $item['error'],
                        'duration_ms' => $duration, 'cookie_expired' => $cookie_expired,
                    ];
                } else {
                    $std = [
                        'total_tokens' => $item['total_tokens'] ?? 0,
                        'input_tokens' => $item['input_tokens'] ?? 0,
                        'output_tokens' => $item['output_tokens'] ?? 0,
                        'cost' => $item['cost'] ?? 0,
                        'remaining' => $item['remaining'] ?? '',
                    ];
                    $extra = array_filter($item, fn($v, $k) => !in_array($k, ['total_tokens','input_tokens','output_tokens','cost','remaining','platform','error']), ARRAY_FILTER_USE_BOTH);
                    tm_save_usage($sub_platform, $std['total_tokens'], $std['input_tokens'], $std['output_tokens'], $std['cost'], $std['remaining'], $extra, $batch_ts);
                    tm_add_refresh_log($sub_platform, 'success', "total={$std['total_tokens']}", $duration);
                    $results[$sub_platform] = ['status' => 'success', 'duration_ms' => $duration];
                }
            }
        } catch (Exception $e) {
            $duration = intval((microtime(true) - $start) * 1000);
            tm_add_refresh_log($platform, 'error', $e->getMessage(), $duration);
            $results[$platform] = ['status' => 'error', 'error' => $e->getMessage(), 'duration_ms' => $duration];
        }
    }

    // WAL checkpoint，防止 wal 文件无限增长
    $db = tm_get_db();
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    return $results;
}

function tm_do_refresh_platform(string $platform): array {
    require_once __DIR__ . '/db.php';
    $results = [];
    $cred_data = tm_get_merged_credential_data($platform);
    if (!$cred_data) return [$platform => ['status' => 'error', 'error' => '无凭证']];

    $cookie_str = isset($cred_data['raw']) ? $cred_data['raw'] : json_encode($cred_data, JSON_UNESCAPED_UNICODE);
    $start = microtime(true);

    try {
        // 腾讯云特殊处理：先检查 api_data 新鲜度
        if ($platform === 'tencent') {
            $all_creds = tm_get_all_credentials('tencent');
            foreach ($all_creds as $ac) {
                if ($ac['credential_type'] === 'api_data') {
                    $age_info = tm_check_api_data_age('tencent');
                    if ($age_info['status'] === 'critical' || $age_info['status'] === 'stale') {
                        tm_delete_credential('tencent', 'api_data');
                        tm_add_refresh_log('tencent', 'warning', $age_info['message'], 0);
                        error_log("[TokenMonitor] " . $age_info['message']);
                    }
                    break;
                }
            }
        }

        $items = match($platform) {
            'tencent' => tm_collect_tencent($cookie_str),
            'volcano' => tm_collect_volcano($cookie_str),
            'xiaomi' => tm_collect_xiaomi($cookie_str),
            'deepseek' => tm_collect_deepseek($cookie_str),
            'minimax' => tm_collect_minimax($cookie_str),
            default => [tm_error_item($platform, "不支持的平台: $platform")],
        };

        $duration = intval((microtime(true) - $start) * 1000);

        foreach ($items as $item) {
            $sub_platform = $item['platform'] ?? $platform;
            if (isset($item['error'])) {
                $cookie_expired = $item['cookie_expired'] ?? false;
                $log_msg = $item['error'];
                if ($cookie_expired) $log_msg = "🍪 " . $log_msg;
                tm_add_refresh_log($sub_platform, 'failed', $log_msg, $duration);
                $results[$sub_platform] = [
                    'status' => 'failed', 'error' => $item['error'],
                    'duration_ms' => $duration, 'cookie_expired' => $cookie_expired,
                ];
            } else {
                $std = [
                    'total_tokens' => $item['total_tokens'] ?? 0,
                    'input_tokens' => $item['input_tokens'] ?? 0,
                    'output_tokens' => $item['output_tokens'] ?? 0,
                    'cost' => $item['cost'] ?? 0,
                    'remaining' => $item['remaining'] ?? '',
                ];
                $extra = array_filter($item, fn($v, $k) => !in_array($k, ['total_tokens','input_tokens','output_tokens','cost','remaining','platform','plan_name','error']), ARRAY_FILTER_USE_BOTH);
                tm_save_usage($sub_platform, $std['total_tokens'], $std['input_tokens'], $std['output_tokens'], $std['cost'], $std['remaining'], $extra);
                tm_add_refresh_log($sub_platform, 'success', "total={$std['total_tokens']}", $duration);
                $results[$sub_platform] = ['status' => 'success', 'duration_ms' => $duration];
            }
        }
    } catch (Exception $e) {
        $duration = intval((microtime(true) - $start) * 1000);
        tm_add_refresh_log($platform, 'error', $e->getMessage(), $duration);
        $results[$platform] = ['status' => 'error', 'error' => $e->getMessage(), 'duration_ms' => $duration];
    }

    return $results;
}

function tm_do_check_credential(string $platform): array {
    require_once __DIR__ . '/db.php';
    $cred_data = tm_get_merged_credential_data($platform);
    if (!$cred_data) return ['status' => 'error', 'error' => '无凭证', 'platform' => $platform];

    $cookie_str = isset($cred_data['raw']) ? $cred_data['raw'] : json_encode($cred_data, JSON_UNESCAPED_UNICODE);
    $start = microtime(true);

    try {
        // 腾讯云特殊处理：先检查 api_data，并做保鲜检测
        $api_data_raw = null;
        if ($platform === 'tencent') {
            $all_creds = tm_get_all_credentials('tencent');
            foreach ($all_creds as $ac) {
                if ($ac['credential_type'] === 'api_data') {
                    $api_data_raw = $ac['credential_data'];
                    $age_info = tm_check_api_data_age('tencent');
                    if ($age_info['status'] === 'critical' || $age_info['status'] === 'stale') {
                        tm_delete_credential('tencent', 'api_data');
                        $api_data_raw = null;
                    }
                    break;
                }
            }
        }

        $items = match($platform) {
            'tencent' => $api_data_raw ? tm_collect_tencent_from_api_data($api_data_raw) : tm_collect_tencent($cookie_str),
            'volcano' => tm_collect_volcano($cookie_str),
            'xiaomi' => tm_collect_xiaomi($cookie_str),
            'deepseek' => tm_collect_deepseek($cookie_str),
            'minimax' => tm_collect_minimax($cookie_str),
            default => [tm_error_item($platform, "不支持的平台: $platform")],
        };

        $duration = intval((microtime(true) - $start) * 1000);
        $sub_results = [];
        $all_ok = true;

        foreach ($items as $item) {
            $sub_platform = $item['platform'] ?? $platform;
            if (isset($item['error'])) {
                $all_ok = false;
                $sub_results[] = [
                    'platform' => $sub_platform,
                    'status' => 'failed',
                    'error' => $item['error'],
                    'cookie_expired' => $item['cookie_expired'] ?? false,
                ];
            } else {
                $sub_results[] = [
                    'platform' => $sub_platform,
                    'status' => 'ok',
                    'remaining' => $item['remaining'] ?? '',
                ];
            }
        }

        return [
            'status' => $all_ok ? 'ok' : 'failed',
            'platform' => $platform,
            'duration_ms' => $duration,
            'details' => $sub_results,
        ];
    } catch (Exception $e) {
        $duration = intval((microtime(true) - $start) * 1000);
        return ['status' => 'error', 'error' => $e->getMessage(), 'platform' => $platform, 'duration_ms' => $duration];
    }
}

function tm_collect_minimax(string $credential): array {
    $decoded = json_decode($credential, true);
    $cookie = is_array($decoded) ? ($decoded['cookie'] ?? '') : '';
    $api_key = is_array($decoded) ? ($decoded['api_key'] ?? '') : '';

    // Netscape 格式自动转换
    if ($cookie && (strpos($cookie, '# Netscape') === 0 || strpos($cookie, "\t") !== false)) {
        $parsed = tm_parse_netscape($cookie);
        if ($parsed) $cookie = $parsed;
    }

    $results = [];

    // 1. 官方平台 Cookie 采集
    if ($cookie) {
        $official = tm_minimax_collect_official($cookie);
        if ($official) $results = array_merge($results, $official);
    }

    // 2. 中转站 API Key 采集
    if ($api_key) {
        $gateway = tm_minimax_collect_gateway($api_key);
        if ($gateway) {
            // 改 platform 为 minimax_gateway 区分
            foreach ($gateway as &$g) $g['platform'] = 'minimax_gateway';
            $results = array_merge($results, $gateway);
        }
    }

    if (!$results) {
        return [tm_error_item('minimax', '缺少凭证：需要 Cookie（官方平台）或 API Key（中转站）')];
    }

    return $results;
}

/* 解析 "532.09M" / "1.2B" / "300K" 为整数 */
function tm_parse_token_str(string $s): int {
    $s = trim($s);
    if (preg_match('/^([\d.]+)\s*([KMB]?)$/i', $s, $m)) {
        $num = floatval($m[1]);
        $unit = strtoupper($m[2] ?? '');
        if ($unit === 'K') return intval($num * 1000);
        if ($unit === 'M') return intval($num * 1000000);
        if ($unit === 'B') return intval($num * 1000000000);
        return intval($num);
    }
    return intval($s);
}

/* MiniMax 官方平台 Cookie 采集 */
function tm_minimax_collect_official(string $cookie): array {
    // 精确诊断：MiniMax 后端靠 HttpOnly 的 _sid/_token 鉴权。
    // 若 cookie 里没有，说明导出方式漏掉了 HttpOnly 会话 cookie（常见：document.cookie / 只暴露 JS 可见 cookie 的插件）。
    if (strpos($cookie, '_sid=') === false || strpos($cookie, '_token=') === false) {
        return [tm_error_item('minimax', 'Cookie 缺少 _sid/_token（HttpOnly 会话 cookie）。请用 Chrome DevTools → Network 找一个登录态请求 → 右键 Copy as cURL → 取出 -b 的 cookie 字符串；或用能读取 HttpOnly 的插件导出。仅粘贴非 HttpOnly 的跟踪 cookie 会被后端拒绝(401)。')];
    }

    $headers = [
        'Cookie' => $cookie,
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept' => 'application/json, text/plain, */*',
        'Referer' => 'https://platform.minimaxi.com/console/usage',
    ];

    $result = [
        'platform' => 'minimax',
        'total_tokens' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'remaining' => '-',
        'plan_name' => 'MiniMax',
        'unit' => 'tokens',
    ];

    // 1. 用量摘要
    $resp = tm_http_get('https://www.minimaxi.com/backend/account/token_plan/usage_summary', $headers);
    if (!$resp || $resp['code'] !== 200) {
        $code = $resp ? $resp['code'] : 0;
        return [tm_error_item('minimax', "用量查询失败 (HTTP $code)，Cookie可能已失效")];
    }
    $data = json_decode($resp['body'], true);
    if (!$data || ($data['base_resp']['status_code'] ?? 0) !== 0) {
        $msg = $data['base_resp']['status_msg'] ?? '未知错误';
        return [tm_error_item('minimax', "用量查询失败: $msg")];
    }

    $total_consumed_str = $data['total_token_consumed'] ?? '0';
    $result['total_tokens'] = tm_parse_token_str($total_consumed_str);
    $result['plan_name'] = 'Token Plan';

    // 按天用量（取最近7天非零）
    $daily = $data['daily_token_usage'] ?? [];
    $result['daily_counts'] = array_slice($daily, -7);

    // 按模型明细（取最近有数据的一天）
    $date_model_usage = $data['date_model_usage'] ?? [];
    $model_usages = [];
    $total_in = 0; $total_out = 0; $total_cache = 0;
    foreach (array_reverse($date_model_usage) as $day) {
        if (!empty($day['models'])) {
            foreach ($day['models'] as $m) {
                $model_usages[] = [
                    'model' => $m['model'] ?? 'unknown',
                    'input_token' => intval($m['input_token'] ?? 0),
                    'cache_read_token' => intval($m['cache_read_token'] ?? 0),
                    'output_token' => intval($m['output_token'] ?? 0),
                    'total_token' => intval($m['total_token'] ?? 0),
                    'cache_hit_percent' => $m['cache_hit_percent'] ?? '0%',
                    'date' => $day['date'] ?? '',
                ];
                $total_in += intval($m['input_token'] ?? 0);
                $total_out += intval($m['output_token'] ?? 0);
                $total_cache += intval($m['cache_read_token'] ?? 0);
            }
            break; // 只取最近一天
        }
    }
    $result['input_tokens'] = $total_in;
    $result['output_tokens'] = $total_out;
    $result['model_usages'] = $model_usages;

    // remaining 摘要
    $result['remaining'] = '14天用量 ' . $total_consumed_str . ' tokens';

    // 1.5 配额数据（5h / 周 / 视频赠送）— 直接调 remains_percent（非 IP 绑定，服务器可直接调）
    $resp_q = tm_http_get('https://www.minimaxi.com/backend/account/token_plan/remains_percent', $headers);
    if ($resp_q && $resp_q['code'] === 200) {
        $qd = json_decode($resp_q['body'], true);
        if ($qd && ($qd['base_resp']['status_code'] ?? 0) === 0 && !empty($qd['model_remains'])) {
            $quotas = [];
            foreach ($qd['model_remains'] as $m) {
                $mname = $m['model_name'] ?? '';
                $used_pct = intval(rtrim($m['current_interval_used_percent'] ?? '0%', '%'));
                $reset_ts = intval(($m['end_time'] ?? 0) / 1000);
                $pos = function($v) { $i = intval($v ?? -1); return $i > 0 ? $i : null; };
                if ($mname === 'general') {
                    $quotas['5h'] = [
                        'used_pct' => $used_pct,
                        'used'     => $pos($m['current_interval_used_count'] ?? -1),
                        'total'    => $pos($m['current_interval_total_count'] ?? -1),
                        'reset_ts' => $reset_ts,
                    ];
                    $quotas['weekly'] = [
                        'used_pct' => intval(rtrim($m['current_weekly_used_percent'] ?? '0%', '%')),
                        'used'     => $pos($m['current_weekly_used_count'] ?? -1),
                        'total'    => $pos($m['current_weekly_total_count'] ?? -1),
                        'reset_ts' => intval(($m['weekly_end_time'] ?? 0) / 1000),
                    ];
                } elseif ($mname === 'video') {
                    $quotas['video'] = [
                        'used_pct' => $used_pct,
                        'used'     => intval($m['current_interval_used_count'] ?? 0),
                        'total'    => intval($m['current_interval_total_count'] ?? 0),
                        'reset_ts' => $reset_ts,
                    ];
                }
            }
            if (!empty($quotas)) {
                $result['quotas'] = $quotas;
                $q5h = $quotas['5h']['used_pct'] ?? 0;
                $qw  = $quotas['weekly']['used_pct'] ?? 0;
                $result['remaining'] = "5h:{$q5h}% | 周:{$qw}%";
            }
        }
    }

    // 2. 积分/余额
    $resp2 = tm_http_get('https://www.minimaxi.com/backend/account/token_plan_credit', $headers);
    if ($resp2 && $resp2['code'] === 200) {
        $credit = json_decode($resp2['body'], true);
        if ($credit && ($credit['base_resp']['status_code'] ?? 0) === 0) {
            $result['balance'] = floatval($credit['total_credits'] ?? 0);
            $result['remaining_credits'] = floatval($credit['remaining_credits'] ?? 0);
        }
    }

    // 3. 套餐有效期（从消息 box 提取）
    $resp3 = tm_http_get('https://www.minimaxi.com/backend/message/box?message_category=4&not_read=false', $headers);
    if ($resp3 && $resp3['code'] === 200) {
        $msg_data = json_decode($resp3['body'], true);
        $templates = $msg_data['template_infos'] ?? [];
        foreach ($templates as $t) {
            $content = $t['template_info']['content'] ?? '';
            // 提取套餐名：Token Plan Plus / Pro / etc
            if (preg_match('/Token Plan\s+([\w]+)/i', $content, $pm)) {
                $result['plan_name'] = 'Token Plan ' . $pm[1];
            }
            // 提取有效期：2026年07月18日
            if (preg_match('/(\d{4})年(\d{2})月(\d{2})日/', $content, $dm)) {
                $valid_to = "{$dm[1]}-{$dm[2]}-{$dm[3]}";
                $result['valid_to'] = $valid_to;
                $remaining_days = intval((strtotime($valid_to) - time()) / 86400);
                if ($remaining_days > 0) $result['remaining_days'] = $remaining_days;
            }
        }
    }

    // 4. 账户名
    $resp4 = tm_http_get('https://www.minimaxi.com/backend/account', $headers);
    if ($resp4 && $resp4['code'] === 200) {
        $acct = json_decode($resp4['body'], true);
        if ($acct && ($acct['base_resp']['status_code'] ?? 0) === 0) {
            $result['account_name'] = $acct['account_info']['name'] ?? '';
        }
    }

    return [$result];
}

/* MiniMax 旧网关 API Key 采集（已废弃，保留兼容） */
function tm_minimax_collect_gateway(string $api_key): array {
    $result = [
        'platform' => 'minimax',
        'total_tokens' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'remaining' => '-',
        'plan_name' => 'MiniMax 网关(已废弃)',
    ];

    $headers = ['x-api-key' => $api_key];

    $resp = tm_http_get("https://minnimax.chat/v1/usage", $headers);
    if (!$resp || $resp['code'] !== 200) {
        $code = $resp ? $resp['code'] : 0;
        return [tm_error_item('minimax', "网关用量查询失败 (HTTP $code)，请改用官方 Cookie 采集")];
    }

    $usage_data = json_decode($resp['body'], true);
    if (!is_array($usage_data)) {
        return [tm_error_item('minimax', "用量数据解析失败")];
    }

    $rolling_5h = $usage_data['rolling_5h'] ?? [];
    $weekly = $usage_data['weekly'] ?? [];
    $daily_counts = $usage_data['daily_counts'] ?? [];
    $plan_name = $usage_data['plan_name'] ?? 'MiniMax';
    $expires_at = $usage_data['expires_at'] ?? '';

    $result['plan_name'] = $plan_name;
    $result['remaining_days'] = $expires_at ? max(0, ceil((strtotime($expires_at) - time()) / 86400)) : 0;

    $total_input = 0;
    $total_output = 0;
    foreach ($daily_counts as $day) {
        $total_input += intval($day['input_tokens'] ?? 0);
        $total_output += intval($day['output_tokens'] ?? 0);
    }
    $result['total_tokens'] = $total_input + $total_output;
    $result['input_tokens'] = $total_input;
    $result['output_tokens'] = $total_output;
    $result['daily_counts'] = $daily_counts;

    $h_limit = intval($rolling_5h['limit'] ?? 0);
    $h_used = intval($rolling_5h['used'] ?? 0);
    $w_limit = intval($weekly['limit'] ?? 0);
    $w_used = intval($weekly['used'] ?? 0);

    $parts = [];
    if ($h_limit > 0) { $parts[] = "5h:" . ($h_limit - $h_used) . "次"; }
    if ($w_limit > 0) { $parts[] = "周:" . ($w_limit - $w_used) . "次"; }
    $result['remaining'] = implode(' | ', $parts);

    $quotas = [];
    if ($h_limit > 0) $quotas['5h'] = ['total' => $h_limit, 'used' => $h_used, 'used_pct' => round($h_used / $h_limit * 100, 1)];
    if ($w_limit > 0) $quotas['weekly'] = ['total' => $w_limit, 'used' => $w_used, 'used_pct' => round($w_used / $w_limit * 100, 1)];
    $result['quotas'] = $quotas;
    $result['remaining_pct'] = $w_limit > 0 ? round(($w_limit - $w_used) / $w_limit * 100, 1) : 100;

    $resp_logs = tm_http_get("https://minnimax.chat/v1/logs?page=1&page_size=30", $headers);
    $recent_in = 0; $recent_out = 0;
    if ($resp_logs && $resp_logs['code'] === 200) {
        $logs_data = json_decode($resp_logs['body'], true);
        $logs = $logs_data['logs'] ?? [];
        foreach ($logs as $l) {
            $recent_in += intval($l['input_tokens'] ?? 0);
            $recent_out += intval($l['output_tokens'] ?? 0);
        }
    }
    $result['monthly_cost'] = $recent_in + $recent_out;

    return [$result];
}

// ============ GPT 中转站采集 ============

function tm_collect_gpt_gateway(string $credential): array {
    $token = $credential;
    $decoded = json_decode($credential, true);
    if (is_array($decoded)) {
        $token = $decoded['token'] ?? ($decoded['auth_token'] ?? '');
    }
    if (!$token) {
        return [tm_error_item('gpt_gateway', '缺少凭证：需要 auth_token')];
    }

    $base = 'http://68.64.183.211';
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
        'User-Agent' => 'Mozilla/5.0',
    ];

    $result = [
        'platform' => 'gpt_gateway',
        'total_tokens' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'remaining' => '-',
        'plan_name' => 'GPT中转',
        'unit' => 'tokens',
    ];

    // 1. 用户信息（余额）
    $resp = tm_http_get($base . '/api/v1/auth/me', $headers);
    $code = $resp ? $resp['code'] : 0;
    // Token 失效（400/401）→ 若用户已提供 refresh_token，尝试自动续期（自愈看板）
    if ($resp && ($code === 400 || $code === 401) && !empty($decoded['refresh_token'])) {
        $rt = tm_gpt_try_refresh($base, $decoded['refresh_token']);
        if ($rt) {
            $new_token = $rt['token'];
            $new_refresh = $rt['refresh_token'] ?? $decoded['refresh_token'];
            tm_save_credential('gpt_gateway', 'token', json_encode(['token' => $new_token, 'refresh_token' => $new_refresh], JSON_UNESCAPED_UNICODE));
            $token = $new_token;
            $headers['Authorization'] = 'Bearer ' . $new_token;
            $resp = tm_http_get($base . '/api/v1/auth/me', $headers);
            $code = $resp ? $resp['code'] : 0;
        }
    }
    if (!$resp || $code !== 200) {
        return [tm_error_item('gpt_gateway', "Token 已过期（有效期约 24 小时）。请重新登录 GPT 中转站获取新的 auth_token，在 ⚙️管理 → 更新凭证 中粘贴。")];
    }
    $me = json_decode($resp['body'], true);
    if (!$me || ($me['code'] ?? -1) !== 0) {
        return [tm_error_item('gpt_gateway', '用户信息解析失败')];
    }
    $user = $me['data'] ?? [];
    $balance = floatval($user['balance'] ?? 0);
    $result['balance'] = $balance;
    $result['account_name'] = $user['email'] ?? '';
    $result['remaining'] = '$' . number_format($balance, 2);

    // 2. 最近用量（取最近30条）
    $resp2 = tm_http_get($base . '/api/v1/usage?page=1&page_size=30', $headers);
    $total_requests = 0;
    $model_usages = [];
    $recent_cost = 0;
    $total_in = 0;
    $total_out = 0;

    if ($resp2 && $resp2['code'] === 200) {
        $usage = json_decode($resp2['body'], true);
        $items = $usage['data']['items'] ?? [];
        $total_requests = $usage['data']['total'] ?? count($items);

        $model_map = [];
        foreach ($items as $item) {
            $model = $item['model'] ?? 'unknown';
            $cost = floatval($item['total_cost'] ?? 0);
            $in_tok = intval($item['input_tokens'] ?? 0);
            $out_tok = intval($item['output_tokens'] ?? 0);
            $recent_cost += $cost;
            $total_in += $in_tok;
            $total_out += $out_tok;

            if (!isset($model_map[$model])) {
                $model_map[$model] = ['model' => $model, 'count' => 0, 'cost' => 0, 'input' => 0, 'output' => 0];
            }
            $model_map[$model]['count']++;
            $model_map[$model]['cost'] += $cost;
            $model_map[$model]['input'] += $in_tok;
            $model_map[$model]['output'] += $out_tok;
        }
        foreach (array_values($model_map) as $mu) {
            $model_usages[] = [
                'model' => $mu['model'],
                'requests' => $mu['count'],
                'input_token' => $mu['input'],
                'output_token' => $mu['output'],
                'total_token' => $mu['input'] + $mu['output'],
                'cost' => round($mu['cost'], 4),
            ];
        }
        usort($model_usages, fn($a, $b) => $b['requests'] <=> $a['requests']);
    }

    $result['total_tokens'] = $total_in + $total_out;
    $result['input_tokens'] = $total_in;
    $result['output_tokens'] = $total_out;
    $result['model_usages'] = $model_usages;
    $result['monthly_cost'] = round($recent_cost, 3);
    $result['total_requests'] = $total_requests;

    return [$result];
}

// GPT 中转 Token 自动续期（仅当刷新端点可用且响应合法时返回新 token，否则返回 null 安全降级）
function tm_gpt_try_refresh(string $base, string $refresh_token): ?array {
    $resp = tm_http_post($base . '/api/v1/auth/refresh', [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'User-Agent' => 'Mozilla/5.0',
    ], ['refresh_token' => $refresh_token]);
    if (!$resp || $resp['code'] !== 200) return null;
    $data = json_decode($resp['body'], true);
    if (!is_array($data)) return null;
    $d = $data['data'] ?? $data;
    $token = $d['token'] ?? null;
    // 严格校验：必须是非空字符串，避免把异常响应当成新 token 保存
    if (!is_string($token) || strlen($token) < 10) return null;
    return ['token' => $token, 'refresh_token' => $d['refresh_token'] ?? null];
}
