"""FastAPI 路由 - 纯 JSON API，不含 HTML"""

import time
import json
from typing import Optional
from fastapi import APIRouter, Query, Request, Depends, HTTPException
from datetime import datetime

from config import PLATFORMS, TENCENT_SUB_TO_PARENT, VOLCANO_SUB_TO_PARENT, APP_VERSION
from db import (
    get_latest_usage, list_credentials, get_credential, get_credential_data,
    get_merged_credential_data, save_credential, delete_credential,
    get_refresh_log, add_refresh_log, get_setting, set_setting, get_conn,
    get_sort_weights, set_sort_weight,
    get_hidden_services, hide_service, show_service,
    get_admin_password_hash, set_admin_password_hash, has_admin_password,
)
from auth import (
    hash_password, verify_password, create_session, validate_session,
    destroy_session, destroy_all_sessions_except,
    check_login_rate, get_login_remaining_attempts,
    verify_internal_key,
)
import db
from scheduler import scheduler
from collectors import REGISTRY

router = APIRouter()


# ============ 认证依赖 ============

def require_auth(request: Request) -> str:
    """认证依赖：验证 Bearer Token 或 X-Internal-Key，返回 token 字符串或 'internal'"""
    # 优先检查内部 API Key（用于 push_to_server 等服务间调用）
    internal_key = request.headers.get("X-Internal-Key", "")
    if internal_key and verify_internal_key(internal_key):
        return "internal"

    auth = request.headers.get("Authorization", "")
    if not auth.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="未登录")
    token = auth[7:]
    if not validate_session(token):
        raise HTTPException(status_code=401, detail="登录已过期，请重新登录")
    return token


# ============ 认证 API ============

@router.get("/api/auth/status")
def auth_status(request: Request):
    """获取认证状态：是否已设密码、当前是否已登录"""
    auth = request.headers.get("Authorization", "")
    authenticated = False
    if auth.startswith("Bearer "):
        authenticated = validate_session(auth[7:])
    return {
        "has_password": has_admin_password(),
        "authenticated": authenticated,
    }


@router.post("/api/auth/setup")
def auth_setup(data: dict, request: Request):
    """首次设置密码（仅当密码未设置时可用）
    注意：此端点不做登录限流，因为只允许在未设密码时调用一次。
    设完密码后此端点立即变为400不可用，不存在暴力风险。
    """
    if has_admin_password():
        raise HTTPException(status_code=400, detail="密码已设置，请使用登录接口")

    password = data.get("password", "")
    if len(password) < 4:
        raise HTTPException(status_code=400, detail="密码至少4位")

    set_admin_password_hash(hash_password(password))
    token = create_session()
    return {"status": "ok", "token": token, "message": "密码设置成功"}


@router.post("/api/auth/login")
def auth_login(data: dict, request: Request):
    """管理员登录"""
    client_ip = request.client.host if request.client else "unknown"

    if not check_login_rate(client_ip):
        remaining_time = 300  # 大约5分钟
        raise HTTPException(
            status_code=429,
            detail=f"尝试次数过多，请{remaining_time}秒后再试"
        )

    password = data.get("password", "")
    stored_hash = get_admin_password_hash()

    if not stored_hash:
        raise HTTPException(status_code=400, detail="请先设置密码")

    if not verify_password(password, stored_hash):
        remaining = get_login_remaining_attempts(client_ip)
        raise HTTPException(
            status_code=401,
            detail=f"密码错误，剩余尝试次数: {remaining}"
        )

    token = create_session()
    return {"status": "ok", "token": token}


@router.post("/api/auth/logout")
def auth_logout(request: Request):
    """管理员登出"""
    auth = request.headers.get("Authorization", "")
    if auth.startswith("Bearer "):
        destroy_session(auth[7:])
    return {"status": "ok"}


@router.post("/api/auth/change-password")
def auth_change_password(data: dict, token: str = Depends(require_auth)):
    """修改密码（需登录），修改后踢出其他设备的会话"""
    old_password = data.get("old_password", "")
    new_password = data.get("new_password", "")

    if not new_password or len(new_password) < 4:
        raise HTTPException(status_code=400, detail="新密码至少4位")

    stored_hash = get_admin_password_hash()
    if stored_hash and not verify_password(old_password, stored_hash):
        raise HTTPException(status_code=401, detail="原密码错误")

    set_admin_password_hash(hash_password(new_password))
    # 修改密码后踢出所有其他会话（保留当前 token）
    destroy_all_sessions_except(token)
    return {"status": "ok", "message": "密码已修改，其他设备已登出"}


# ============ 监控 API（公开） ============

@router.get("/api/stats")
def get_stats():
    """获取所有平台统计数据（腾讯三子计划聚合为一个大卡）
    有凭证但无数据的平台也会显示（标记 no_data=True）
    """
    sub_data = get_latest_usage()

    all_platforms = {}
    tencent_services = []
    volcano_services = []

    for p in sub_data:
        group = TENCENT_SUB_TO_PARENT.get(p["platform"])
        volcano_group = VOLCANO_SUB_TO_PARENT.get(p["platform"])
        
        if group == "tencent":
            tencent_services.append(p)
        elif volcano_group == "volcano":
            volcano_services.append(p)
        else:
            # 从 raw_json 中提取 DeepSeek 模型用量等嵌套字段
            if p.get("platform") == "deepseek":
                raw_json = p.get("raw_json", "")
                if raw_json:
                    try:
                        raw_data = json.loads(raw_json) if isinstance(raw_json, str) else raw_json
                        if isinstance(raw_data, dict):
                            for extra_key in ("model_usages", "monthly_cost", "cost_total"):
                                if extra_key in raw_data and extra_key not in p:
                                    p[extra_key] = raw_data[extra_key]
                    except (json.JSONDecodeError, TypeError):
                        pass

            entry = {
                "platform": p["platform"],
                "total_tokens": p.get("total_tokens", 0),
                "input_tokens": p.get("input_tokens", 0),
                "output_tokens": p.get("output_tokens", 0),
                "cost": p.get("cost", 0),
                "remaining": p.get("remaining", ""),
                "last_updated": p.get("last_updated", ""),
                "source": "console",
                "calls": 0,
            }
            for key in ("quotas", "plan_type", "plan_code", "remaining_days", "valid_from",
                         "valid_to", "plan_status", "remaining_pct",
                         "balance_available", "balance_cash", "balance_credit",
                         "balance_frozen", "balance_arrears",
                         "balance", "gift_balance", "cash_balance", "frozen_balance",
                         "cache_tokens", "tpm", "rpm", "current_month_cost",
                         "month_used", "month_limit", "month_pct", "plan_pct",
                         "comp_total", "comp_used", "comp_pct", "auto_renew",
                         "model_usages", "monthly_cost", "cost_total"):
                if key in p:
                    entry[key] = p[key]
            all_platforms[p["platform"]] = entry

    # 腾讯聚合
    # 过滤隐藏的子服务
    hidden = get_hidden_services()
    tencent_services = [s for s in tencent_services if s["platform"] not in hidden]
    volcano_services = [s for s in volcano_services if s["platform"] not in hidden]

    if tencent_services:
        latest_ts = max(p["last_updated"] for p in tencent_services)
        all_platforms["tencent"] = {
            "platform": "tencent",
            "total_tokens": sum(p.get("total_tokens", 0) for p in tencent_services),
            "input_tokens": sum(p.get("input_tokens", 0) for p in tencent_services),
            "output_tokens": sum(p.get("output_tokens", 0) for p in tencent_services),
            "cost": sum(p.get("cost", 0) for p in tencent_services),
            "remaining": "",
            "last_updated": latest_ts,
            "source": "console",
            "calls": 0,
            "services": tencent_services,
        }

    # 火山引擎聚合
    if volcano_services:
        latest_ts = max(p["last_updated"] for p in volcano_services)
        all_platforms["volcano"] = {
            "platform": "volcano",
            "total_tokens": sum(p.get("total_tokens", 0) for p in volcano_services),
            "input_tokens": sum(p.get("input_tokens", 0) for p in volcano_services),
            "output_tokens": sum(p.get("output_tokens", 0) for p in volcano_services),
            "cost": sum(p.get("cost", 0) for p in volcano_services),
            "remaining": "",
            "last_updated": latest_ts,
            "source": "console",
            "calls": 0,
            "services": volcano_services,
        }

    # 有凭证但无数据的平台：显示占位卡片
    creds = list_credentials()
    cred_platforms = set(c["platform"] for c in creds)
    for platform_key in cred_platforms:
        if platform_key in all_platforms:
            continue  # 已有数据，跳过
        # 腾讯有凭证但无数据 → 也要聚合显示
        if platform_key == "tencent" and "tencent" not in all_platforms:
            all_platforms["tencent"] = {
                "platform": "tencent",
                "total_tokens": 0, "input_tokens": 0, "output_tokens": 0,
                "cost": 0, "remaining": "", "last_updated": "",
                "source": "console", "calls": 0,
                "services": [], "no_data": True,
            }
        elif platform_key == "volcano" and "volcano" not in all_platforms:
            all_platforms["volcano"] = {
                "platform": "volcano",
                "total_tokens": 0, "input_tokens": 0, "output_tokens": 0,
                "cost": 0, "remaining": "", "last_updated": "",
                "source": "console", "calls": 0,
                "services": [], "no_data": True,
            }
        elif platform_key not in ("tencent", "volcano"):
            # 其他独立平台（如 xiaomi）
            all_platforms[platform_key] = {
                "platform": platform_key,
                "total_tokens": 0, "input_tokens": 0, "output_tokens": 0,
                "cost": 0, "remaining": "", "last_updated": "",
                "source": "console", "calls": 0,
                "no_data": True,
            }

    # 实时排序：有数据的平台优先，按最后更新时间降序
    sorted_platforms = sorted(all_platforms.values(), key=lambda x: (
        0 if x.get("no_data") else 1,                           # 有数据的优先
        x.get("last_updated", "") or ""                         # 按更新时间降序
    ), reverse=True)

    return {
        "platforms": sorted_platforms,
        "last_updated": datetime.now().isoformat(),
        "version": APP_VERSION,
    }


@router.get("/api/scrape-status")
def get_scrape_status():
    """获取各平台最近抓取时间"""
    sub_data = get_latest_usage()
    return [
        {
            "platform": p["platform"],
            "last_scraped": p.get("last_updated"),
            "total_tokens": p.get("total_tokens", 0),
            "remaining": p.get("remaining", ""),
        }
        for p in sub_data
    ]


@router.get("/api/cookie-status")
def cookie_status():
    """检测各平台 Cookie 是否有效（基于最近刷新日志）"""
    conn = db.get_conn()
    # 取每个平台最近3条日志
    rows = conn.execute("""
        SELECT platform, status, message, timestamp
        FROM refresh_log
        WHERE id IN (
            SELECT id FROM refresh_log rl2
            WHERE rl2.platform = refresh_log.platform
            ORDER BY id DESC LIMIT 3
        )
        ORDER BY platform, id DESC
    """).fetchall()

    platform_status = {}
    for row in rows:
        p = row["platform"]
        if p not in platform_status:
            platform_status[p] = {"platform": p, "healthy": True, "last_check": None, "message": ""}

        ts = row["timestamp"]
        if platform_status[p]["last_check"] is None or ts > platform_status[p]["last_check"]:
            platform_status[p]["last_check"] = ts
            platform_status[p]["last_check_fmt"] = time.strftime(
                "%Y-%m-%d %H:%M:%S", time.localtime(ts)
            ) if ts else ""

        if row["status"] != "success":
            platform_status[p]["healthy"] = False
            msg = row["message"] or ""
            platform_status[p]["message"] = msg.replace("🍪 ", "")
            platform_status[p]["cookie_expired"] = "🍪" in msg or "Cookie" in msg or "失效" in msg

    # 格式化时间
    for p in platform_status.values():
        if "last_check" in p and isinstance(p["last_check"], (int, float)):
            p["last_check_fmt"] = time.strftime(
                "%Y-%m-%d %H:%M:%S", time.localtime(p["last_check"])
            )

    return list(platform_status.values())


# ============ 管理 API（需认证） ============

@router.get("/api/admin/credentials")
def admin_list_credentials(_token: str = Depends(require_auth)):
    return list_credentials()


@router.get("/api/admin/credentials/{platform}")
def admin_get_credential(platform: str, _token: str = Depends(require_auth)):
    cred = get_credential(platform)
    if not cred:
        return {"error": "凭证不存在"}
    data = cred.get("credential_data", "")
    if len(data) > 20:
        masked = data[:8] + "..." + data[-4:]
    else:
        masked = data[:4] + "..." if len(data) > 4 else "***"
    return {
        "platform": cred["platform"],
        "credential_type": cred["credential_type"],
        "credential_data_masked": masked,
        "note": cred.get("note", ""),
        "updated_at": cred.get("updated_at", 0),
    }


@router.post("/api/admin/credentials")
def admin_save_credential(data: dict, _token: str = Depends(require_auth)):
    platform = data.get("platform")
    cred_type = data.get("credential_type", "cookie")
    cred_data = data.get("credential_data", "")
    note = data.get("note", "")

    if not platform or not cred_data:
        return {"error": "平台和凭证数据不能为空"}
    if platform not in PLATFORMS:
        return {"error": f"不支持的平台: {platform}"}

    save_credential(platform, cred_type, cred_data, note)
    return {"status": "ok", "message": f"已保存 {platform} 的凭证"}


@router.delete("/api/admin/credentials/{platform}")
def admin_delete_credential(platform: str, _token: str = Depends(require_auth)):
    if delete_credential(platform):
        return {"status": "ok", "message": f"已删除 {platform} 的凭证"}
    return {"error": "凭证不存在"}


@router.post("/api/admin/refresh")
async def admin_refresh_now(_token: str = Depends(require_auth)):
    """立即刷新所有平台数据"""
    results = await _do_refresh_all()
    return {"status": "ok", "results": results}


@router.post("/api/admin/refresh/{platform}")
async def admin_refresh_platform(platform: str, _token: str = Depends(require_auth)):
    """刷新单个平台数据"""
    if platform not in REGISTRY:
        return {"error": "未知平台"}
    results = await _do_refresh_platform(platform)
    return {"status": "ok", "results": results}


@router.post("/api/admin/check-credential/{platform}")
async def admin_check_credential(platform: str, _token: str = Depends(require_auth)):
    """检查单个平台凭证是否有效（不写数据库）"""
    if platform not in REGISTRY:
        return {"error": "未知平台"}
    result = await _do_check_credential(platform)
    return result


@router.get("/api/admin/refresh-log")
def admin_refresh_log(limit: int = 30, _token: str = Depends(require_auth)):
    return get_refresh_log(limit)


@router.get("/api/admin/scheduler")
def admin_scheduler_status(_token: str = Depends(require_auth)):
    return scheduler.status()


@router.post("/api/admin/scheduler")
def admin_scheduler_control(data: dict, _token: str = Depends(require_auth)):
    action = data.get("action", "")
    interval = data.get("interval")

    if interval:
        scheduler.interval = interval

    if action == "start":
        if scheduler.start():
            return {"status": "ok", "message": f"调度器已启动，间隔{scheduler.interval}秒"}
        return {"status": "already_running", "message": "调度器已在运行"}
    elif action == "stop":
        scheduler.stop()
        return {"status": "ok", "message": "调度器已停止"}
    elif action == "restart":
        scheduler.stop()
        time.sleep(0.5)
        scheduler.start()
        return {"status": "ok", "message": f"调度器已重启，间隔{scheduler.interval}秒"}

    return {"error": "未知操作"}


@router.get("/api/admin/platforms")
def admin_platforms(_token: str = Depends(require_auth)):
    return PLATFORMS


@router.get("/api/admin/sort-weights")
def admin_get_sort_weights(_token: str = Depends(require_auth)):
    """获取所有平台排序权重"""
    return get_sort_weights()


@router.post("/api/admin/sort-weights")
def admin_set_sort_weights(data: dict, _token: str = Depends(require_auth)):
    """批量设置平台排序权重，data = {platform: weight, ...}"""
    for platform, weight in data.items():
        try:
            set_sort_weight(platform, int(weight))
        except (ValueError, TypeError):
            pass
    return {"status": "ok", "message": "排序权重已更新"}


@router.get("/api/admin/hidden-services")
def admin_get_hidden_services(_token: str = Depends(require_auth)):
    """获取已隐藏的子服务列表"""
    return {"hidden": get_hidden_services()}


@router.post("/api/admin/hidden-services/hide")
def admin_hide_service(data: dict, _token: str = Depends(require_auth)):
    """隐藏一个子服务"""
    sub_platform = data.get("sub_platform")
    if not sub_platform:
        return {"error": "缺少 sub_platform"}
    hide_service(sub_platform)
    return {"status": "ok", "message": f"已隐藏 {sub_platform}"}


@router.post("/api/admin/hidden-services/show")
def admin_show_service(data: dict, _token: str = Depends(require_auth)):
    """恢复显示一个子服务"""
    sub_platform = data.get("sub_platform")
    if not sub_platform:
        return {"error": "缺少 sub_platform"}
    show_service(sub_platform)
    return {"status": "ok", "message": f"已恢复显示 {sub_platform}"}


# ============ 内部刷新逻辑 ============

async def _do_refresh_all() -> dict:
    """执行所有平台的刷新"""
    from db import save_usage as _save_usage
    import logging
    logger = logging.getLogger("refresh")

    results = {}
    creds = list_credentials()

    for cred in creds:
        platform = cred["platform"]
        cred_data = get_credential_data(platform)
        if not cred_data:
            continue

        # 腾讯平台需要完整的 JSON 凭证（含 cookie/uin/ownerUin/csrfCode）
        # 火山平台需要合并的 JSON 凭证（含 cookie + ak/sk）
        # 其他平台只需 Cookie 字符串
        if platform == "volcano":
            # 火山引擎：合并所有凭证（Cookie + AK/SK）
            cred_data = get_merged_credential_data(platform)
            if not cred_data:
                continue
            cookie_str = json.dumps(cred_data, ensure_ascii=False)
        elif platform in ("tencent", "xiaomi"):
            # cred_data 可能是 {"raw": "..."}（纯文本/Netscape Cookie）或 {"cookie": "..."}（JSON格式）
            if "raw" in cred_data:
                cookie_str = cred_data["raw"]
            else:
                cookie_str = json.dumps(cred_data, ensure_ascii=False)
        elif platform == "deepseek":
            # DeepSeek：凭证可能含 api_key 和/或 token
            cookie_str = json.dumps(cred_data, ensure_ascii=False)
        else:
            cookie_str = cred_data.get("cookie", cred_data.get("raw", ""))

        collector = REGISTRY.get(platform)
        if not collector:
            continue

        start = time.time()
        try:
            items = await collector.collect(cookie_str)
            duration = int((time.time() - start) * 1000)

            for item in items:
                sub_platform = item.get("platform", platform)
                if "error" in item:
                    cookie_expired = item.get("cookie_expired", False)
                    log_msg = item["error"]
                    if cookie_expired:
                        log_msg = "🍪 " + log_msg
                    add_refresh_log(sub_platform, "failed", log_msg, duration)
                    results[sub_platform] = {
                        "status": "failed", "error": item["error"],
                        "duration_ms": duration, "cookie_expired": cookie_expired,
                    }
                else:
                    std = {
                        "total_tokens": item.get("total_tokens", 0),
                        "input_tokens": item.get("input_tokens", 0),
                        "output_tokens": item.get("output_tokens", 0),
                        "cost": item.get("cost", 0),
                        "remaining": item.get("remaining", ""),
                    }
                    extra = {k: v for k, v in item.items()
                             if k not in std and k not in ("platform", "plan_name", "error")}
                    _save_usage(sub_platform, raw=extra, **std)
                    add_refresh_log(sub_platform, "success", f"total={std['total_tokens']}", duration)
                    results[sub_platform] = {"status": "success", "duration_ms": duration}

        except Exception as e:
            duration = int((time.time() - start) * 1000)
            logger.error(f"[{platform}] 采集异常: {e}")
            add_refresh_log(platform, "error", str(e), duration)
            results[platform] = {"status": "error", "error": str(e), "duration_ms": duration}

    return results


async def _do_refresh_platform(platform: str) -> dict:
    """刷新单个平台数据"""
    from db import save_usage as _save_usage
    import logging
    logger = logging.getLogger("refresh")

    results = {}
    cred_data = get_credential_data(platform)
    if not cred_data:
        return {platform: {"status": "error", "error": "无凭证"}}

    # 构造凭证字符串
    if platform == "volcano":
        cred_data = get_merged_credential_data(platform)
        if not cred_data:
            return {platform: {"status": "error", "error": "无凭证"}}
        cookie_str = json.dumps(cred_data, ensure_ascii=False)
    elif platform in ("tencent", "xiaomi"):
        # cred_data 可能是 {"raw": "..."}（纯文本/Netscape Cookie）或 {"cookie": "..."}（JSON格式）
        if "raw" in cred_data:
            cookie_str = cred_data["raw"]
        else:
            cookie_str = json.dumps(cred_data, ensure_ascii=False)
    elif platform == "deepseek":
        cookie_str = json.dumps(cred_data, ensure_ascii=False)
    else:
        cookie_str = cred_data.get("cookie", cred_data.get("raw", ""))

    # 调试日志
    import logging as _lg
    _lg.getLogger("refresh").warning(f"[DEBUG] platform={platform} cred_data_keys={list(cred_data.keys())} cookie_str_len={len(cookie_str)} cookie_str_start={repr(cookie_str[:80])}")

    collector = REGISTRY.get(platform)
    if not collector:
        return {platform: {"status": "error", "error": "无采集器"}}

    start = time.time()
    try:
        items = await collector.collect(cookie_str)
        duration = int((time.time() - start) * 1000)

        for item in items:
            sub_platform = item.get("platform", platform)
            if "error" in item:
                cookie_expired = item.get("cookie_expired", False)
                log_msg = item["error"]
                if cookie_expired:
                    log_msg = "🍪 " + log_msg
                add_refresh_log(sub_platform, "failed", log_msg, duration)
                results[sub_platform] = {
                    "status": "failed", "error": item["error"],
                    "duration_ms": duration, "cookie_expired": cookie_expired,
                }
            else:
                std = {
                    "total_tokens": item.get("total_tokens", 0),
                    "input_tokens": item.get("input_tokens", 0),
                    "output_tokens": item.get("output_tokens", 0),
                    "cost": item.get("cost", 0),
                    "remaining": item.get("remaining", ""),
                }
                extra = {k: v for k, v in item.items()
                         if k not in std and k not in ("platform", "plan_name", "error")}
                _save_usage(sub_platform, raw=extra, **std)
                add_refresh_log(sub_platform, "success", f"total={std['total_tokens']}", duration)
                results[sub_platform] = {"status": "success", "duration_ms": duration}

    except Exception as e:
        duration = int((time.time() - start) * 1000)
        logger.error(f"[{platform}] 采集异常: {e}")
        add_refresh_log(platform, "error", str(e), duration)
        results[platform] = {"status": "error", "error": str(e), "duration_ms": duration}

    return results


async def _do_check_credential(platform: str) -> dict:
    """检查凭证是否有效（不写数据库，只测试采集）"""
    import logging
    logger = logging.getLogger("check-cred")

    cred_data = get_credential_data(platform)
    if not cred_data:
        return {"status": "error", "error": "无凭证", "platform": platform}

    # 构造凭证字符串
    if platform == "volcano":
        cred_data = get_merged_credential_data(platform)
        if not cred_data:
            return {"status": "error", "error": "无凭证", "platform": platform}
        cookie_str = json.dumps(cred_data, ensure_ascii=False)
    elif platform in ("tencent", "xiaomi"):
        # cred_data 可能是 {"raw": "..."}（纯文本/Netscape Cookie）或 {"cookie": "..."}（JSON格式）
        if "raw" in cred_data:
            cookie_str = cred_data["raw"]
        else:
            cookie_str = json.dumps(cred_data, ensure_ascii=False)
    elif platform == "deepseek":
        cookie_str = json.dumps(cred_data, ensure_ascii=False)
    else:
        cookie_str = cred_data.get("cookie", cred_data.get("raw", ""))

    collector = REGISTRY.get(platform)
    if not collector:
        return {"status": "error", "error": "无采集器", "platform": platform}

    start = time.time()
    try:
        items = await collector.collect(cookie_str)
        duration = int((time.time() - start) * 1000)

        # 汇总结果
        sub_results = []
        all_ok = True
        for item in items:
            sub_platform = item.get("platform", platform)
            if "error" in item:
                all_ok = False
                sub_results.append({
                    "platform": sub_platform,
                    "status": "failed",
                    "error": item["error"],
                    "cookie_expired": item.get("cookie_expired", False),
                })
            else:
                sub_results.append({
                    "platform": sub_platform,
                    "status": "ok",
                    "remaining": item.get("remaining", ""),
                })

        return {
            "status": "ok" if all_ok else "failed",
            "platform": platform,
            "duration_ms": duration,
            "details": sub_results,
        }

    except Exception as e:
        duration = int((time.time() - start) * 1000)
        return {"status": "error", "error": str(e), "platform": platform, "duration_ms": duration}
