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
        CURLOPT_SSL_VERIFYPEER => false,
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
        CURLOPT_SSL_VERIFYPEER => false,
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

function tm_collect_tencent(string $cookie_str): array {
    // 解析凭证
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) $cred = ['cookie' => $cookie_str];

    $cookie = $cred['cookie'] ?? '';
    $uin = $cred['uin'] ?? tm_extract_cookie_value($cookie_str, 'uin');
    $ownerUin = $cred['ownerUin'] ?? $uin;
    $csrfCode = $cred['csrfCode'] ?? tm_extract_cookie_value($cookie_str, 'csrfCode');

    // 去除 uin 前缀的 o/O
    $uin = ltrim($uin, 'oO');

    if (!$cookie || !$uin || !$csrfCode) {
        $error = '凭证格式错误，需要Cookie+uin+ownerUin+csrfCode';
        return [
            tm_error_item('tencent_codingplan', $error),
            tm_error_item('tencent_hy_tokenplan', $error),
            tm_error_item('tencent_tokenplan', $error),
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

    // 2. Token Plans
    $plan_list = tm_tencent_capi_call($headers, $params_base, 'ListUserTokenPlans');
    if ($plan_list && !isset($plan_list['_error'])) {
        $plans = $plan_list['UserTokenPlanList'] ?? [];
        if (!$plans) {
            $msg = $cookie_expired ? 'Cookie 已失效，请重新获取' : '未找到任何Token Plan';
            $results[] = tm_error_item('tencent_hy_tokenplan', $msg, $cookie_expired);
            $results[] = tm_error_item('tencent_tokenplan', $msg, $cookie_expired);
        } else {
            // 按 plan_id 分组
            foreach ($plans as $plan) {
                $plan_id = $plan['Plan'] ?? '';
                $edition = $plan['Edition'] ?? 'personal';
                $plan_key = (strpos($plan_id, 'hy') !== false || $edition === 'hunyuan')
                    ? 'tencent_hy_tokenplan' : 'tencent_tokenplan';

                $usage = tm_tencent_capi_call($headers, $params_base, 'DescribeTokenPlanUsage', ['Edition' => $edition]);
                if ($usage && !isset($usage['_error'])) {
                    $data = tm_tencent_parse_plan_usage($usage, $plan_id);
                    if ($data) {
                        $data['platform'] = $plan_key;
                        $results[] = $data;
                    } else {
                        $results[] = tm_error_item($plan_key, '用量数据为空');
                    }
                } else {
                    $results[] = tm_error_item($plan_key, $usage['_error'] ?? '查询失败');
                }
            }
        }
    } else {
        $msg = $cookie_expired ? 'Cookie 已失效，请重新获取' : ($plan_list['_error'] ?? 'Token Plan 查询失败');
        $results[] = tm_error_item('tencent_hy_tokenplan', $msg, $cookie_expired);
        $results[] = tm_error_item('tencent_tokenplan', $msg, $cookie_expired);
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
    if (isset($quotas['5h'])) $parts[] = "5h:" . number_format($quotas['5h']['used_pct'], 1) . "%";
    if (isset($quotas['weekly'])) $parts[] = "周:" . number_format($quotas['weekly']['used_pct'], 1) . "%";
    if (isset($quotas['monthly'])) $parts[] = "月:" . number_format($quotas['monthly']['used_pct'], 1) . "%";
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

    $results = [];

    // 1. Coding Plan: Cookie + CSRF
    if ($cookie) {
        $cp = tm_volcano_collect_coding_plan($cookie);
        if ($cp) $results[] = $cp;
    }

    // 2. 余额: AK/SK
    if ($ak && $sk) {
        $bal = tm_volcano_query_balance($ak, $sk);
        if ($bal) $results[] = $bal;
    }

    if (!$results) {
        if (!$cookie && !($ak && $sk)) {
            return [tm_error_item('volcano', '缺少凭证：需要 Cookie（查Coding Plan）或 AK/SK（查余额）')];
        }
        if ($cookie && !($ak && $sk)) {
            $results[] = tm_error_item('volcano_codingplan', 'Coding Plan 查询失败，Cookie可能已失效');
        }
    }

    return $results;
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

    // remaining 摘要
    $parts = [];
    foreach (['5h', 'weekly', 'monthly'] as $key) {
        if (isset($quotas[$key])) $parts[] = "$key:" . number_format($quotas[$key]['used_pct'], 1) . "%";
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

    // 1. Token Plan 详情
    $plan = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/tokenPlan/detail', $headers);
    if ($plan) {
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
    } elseif ($plan === false) {
        return [tm_error_item('xiaomi', 'Cookie已失效，请重新登录小米平台', true)];
    }

    // 2. Token Plan 用量
    $usage = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/tokenPlan/usage', $headers);
    if ($usage) {
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
    }

    // 3. 通用用量（速率限制）
    $gen_usage = tm_xiaomi_api_get('https://platform.xiaomimimo.com/api/v1/usage', $headers);
    if ($gen_usage) {
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
    if ($bal) {
        $d = $bal['data'] ?? [];
        $result['balance'] = floatval($d['balance'] ?? 0);
        $result['gift_balance'] = floatval($d['giftBalance'] ?? 0);
        $result['cash_balance'] = floatval($d['cashBalance'] ?? 0);
        $result['frozen_balance'] = floatval($d['frozenBalance'] ?? 0);
    }

    return [$result];
}

function tm_xiaomi_api_get(string $url, array $headers): ?array {
    // 返回 null=请求失败, false=401(Cookie过期), array=成功
    $resp = tm_http_get($url, $headers);
    if (!$resp) return null;
    if ($resp['code'] === 401) return false;
    if ($resp['code'] !== 200) return null;
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || ($data['code'] ?? -1) !== 0) return null;
    return $data;
}

// ============ DeepSeek 采集 ============

function tm_collect_deepseek(string $cookie_str): array {
    // 解析凭证: JSON 格式或纯文本
    $cred = json_decode($cookie_str, true);
    if (!is_array($cred)) {
        $raw = trim($cookie_str);
        if (strpos($raw, 'sk-') === 0) {
            $cred = ['api_key' => $raw];
        } else {
            $cred = ['token' => $raw];
        }
    }

    $api_key = $cred['api_key'] ?? '';
    $token = $cred['token'] ?? '';
    $raw = $cred['raw'] ?? '';

    // raw 兜底
    if (!$api_key && !$token && $raw) {
        $raw = trim($raw);
        if (strpos($raw, 'sk-') === 0) {
            $api_key = $raw;
        } else {
            $token = $raw;
        }
    }

    // 模式1: API Key → 查余额
    if ($api_key && !$token) {
        return tm_collect_deepseek_balance($api_key);
    }

    // 模式2: Token → 查用量明细
    if ($token) {
        $result = tm_collect_deepseek_usage($token);
        // 同时有 API Key，合并余额
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

    // 1. 查用量
    $resp = tm_http_get("https://platform.deepseek.com/api/v0/usage/amount?month=$now&year=$year", $headers);
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

    // 2. 查费用
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

// ============ 主刷新逻辑 ============

function tm_do_refresh_all(): array {
    require_once __DIR__ . '/db.php';
    $results = [];
    $creds = tm_list_credentials();

    foreach ($creds as $cred) {
        $platform = $cred['platform'];
        $cred_data = tm_get_merged_credential_data($platform);
        if (!$cred_data) continue;

        $cookie_str = isset($cred_data['raw']) ? $cred_data['raw'] : json_encode($cred_data, JSON_UNESCAPED_UNICODE);
        $start = microtime(true);

        try {
            $items = match($platform) {
                'tencent' => tm_collect_tencent($cookie_str),
                'volcano' => tm_collect_volcano($cookie_str),
                'xiaomi' => tm_collect_xiaomi($cookie_str),
                'deepseek' => tm_collect_deepseek($cookie_str),
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
    }

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
        $items = match($platform) {
            'tencent' => tm_collect_tencent($cookie_str),
            'volcano' => tm_collect_volcano($cookie_str),
            'xiaomi' => tm_collect_xiaomi($cookie_str),
            'deepseek' => tm_collect_deepseek($cookie_str),
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
        $items = match($platform) {
            'tencent' => tm_collect_tencent($cookie_str),
            'volcano' => tm_collect_volcano($cookie_str),
            'xiaomi' => tm_collect_xiaomi($cookie_str),
            'deepseek' => tm_collect_deepseek($cookie_str),
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
