"""腾讯混元采集器 - 逆向控制台API，纯HTTP采集三种计划"""

import time
import json
from typing import Optional
from collectors.base import BaseCollector
from config import PLATFORMS

# 腾讯云控制台内部 API 网关
TENCENT_CAPI_URL = "https://console.cloud.tencent.com/cgi/capi"

# Plan 名称映射
_PLAN_NAMES = {
    "tp_hy_standard": "Hy Standard",
    "tp_lite": "Lite",
    "tp_pro": "Pro",
}


class TencentCollector(BaseCollector):
    """腾讯混元 - 一个Cookie采集三个子计划（通过控制台内部API）"""

    platform = "tencent"

    def __init__(self):
        self.services = PLATFORMS["tencent"]["services"]

    async def collect(self, cookie_str: str) -> list[dict]:
        """采集三种计划数据

        cookie_str 可以是：
        1. 纯 Cookie 字符串（需要额外配置 uin/ownerUin/csrfCode）
        2. JSON 格式: {"cookie": "xxx", "uin": "xxx", "ownerUin": "xxx", "csrfCode": "xxx"}

        优先使用扩展推送的 CAPI 数据（api_data 凭证），
        避免服务端 CAPI 验证失败导致数据为空。
        """
        # === 优先：使用扩展推送的 api_data ===
        pushed = self._load_pushed_api_data()
        if pushed:
            return pushed

        # === 降级：直调 CAPI（可能因 IP/csrfCode 失败） ===
        import httpx

        # 解析凭证
        cred = self._parse_credential(cookie_str)
        if not cred:
            results = []
            for svc in self.services:
                results.extend(self._error_result(svc["key"],
                    "凭证格式错误，需要Cookie+uin+ownerUin+csrfCode"))
            return results

        cookie_str, uin, ownerUin, csrfCode = cred
        headers = self._make_api_headers(cookie_str)
        params_base = self._make_params_base(uin, ownerUin, csrfCode)

        results = []
        self._cookie_expired = False
        async with httpx.AsyncClient(verify=False, timeout=20, follow_redirects=False) as client:
            # ---- 1. Coding Plan (DescribePkg) ----
            try:
                cp_data = await self._fetch_coding_plan(client, headers, params_base)
                if cp_data:
                    cp_data["platform"] = "tencent_codingplan"
                    results.append(cp_data)
                else:
                    self._cookie_expired = True
                    results.extend(self._error_result("tencent_codingplan", "Cookie 已失效，请重新获取", cookie_expired=True))
            except Exception as e:
                results.extend(self._error_result("tencent_codingplan", str(e)))

            # ---- 2. Token Plans (ListUserTokenPlans + DescribeTokenPlanUsage) ----
            try:
                plan_list = await self._fetch_plan_list(client, headers, params_base)
                if not plan_list:
                    self._cookie_expired = True
                    results.extend(self._error_result("tencent_hy_tokenplan", "Cookie 已失效，请重新获取", cookie_expired=True))
                    results.extend(self._error_result("tencent_tokenplan", "Cookie 已失效，请重新获取", cookie_expired=True))
                else:
                    for plan in plan_list:
                        plan_key = plan.get("plan_key", "")
                        try:
                            usage = await self._fetch_plan_usage(
                                client, headers, params_base, plan
                            )
                            if usage:
                                usage["platform"] = plan_key
                                results.append(usage)
                            else:
                                results.extend(self._error_result(plan_key, "用量数据为空"))
                        except Exception as e:
                            results.extend(self._error_result(plan_key, str(e)))
            except Exception as e:
                results.extend(self._error_result("tencent_hy_tokenplan", str(e)))
                results.extend(self._error_result("tencent_tokenplan", str(e)))

        return results

    # ============ 扩展推送数据加载 ============

    def _load_pushed_api_data(self) -> Optional[list[dict]]:
        """从 DB 加载扩展推送的 CAPI 数据（api_data 凭证）

        返回标准采集结果格式，或 None（无数据/解析失败）
        """
        try:
            from db import get_credential_data
            cred = get_credential_data("tencent", cred_type="api_data")
            if not cred:
                return None

            # 兼容两种格式：dict 或 list
            raw_data = cred
            if isinstance(raw_data, dict) and "raw" in raw_data:
                raw_data = json.loads(raw_data["raw"])

            if not isinstance(raw_data, list):
                raw_data = [raw_data]

            results = []

            for packet in raw_data:
                # 新格式：{all: [{cmd, data, url}]}
                all_list = packet.get("all") if isinstance(packet, dict) else None
                if all_list and isinstance(all_list, list):
                    for item in all_list:
                        cmd = item.get("cmd", "")
                        data = item.get("data", {})
                        parsed = self._parse_capi_response(cmd, data)
                        if parsed:
                            results.append(parsed)
                    continue

                # 旧格式：{pkgs: [...]} 或 [{pkgs: [...]}]
                pkgs = packet.get("pkgs") if isinstance(packet, dict) else packet
                if isinstance(pkgs, list) and len(pkgs) > 0:
                    # 创建一个 DescribePkg 格式数据
                    fake_resp = {"PkgList": pkgs}
                    parsed = self._parse_capi_response("DescribePkg", fake_resp)
                    if parsed:
                        results.append(parsed)

            return results if results else None

        except Exception as e:
            import logging
            logging.getLogger("tencent").warning(f"加载推送数据失败: {e}")
            return None

    def _parse_capi_response(self, cmd: str, response: dict) -> Optional[dict]:
        """根据 CAPI 命令名解析响应数据为采集结果格式"""
        try:
            if cmd == "DescribePkg":
                pkgs = response.get("PkgList", [])
                if not pkgs:
                    return None
                return self._build_coding_plan_data(pkgs[0])

            elif cmd == "ListUserTokenPlans":
                # ListUserTokenPlans 本身不返回用量，只返回计划列表
                # 后续 DescribeTokenPlanUsage 会提供用量
                return None

            elif cmd == "DescribeTokenPlanUsage":
                usage_list = response.get("TokenPlanUsageList", [])
                if not usage_list:
                    return None
                return self._build_token_plan_usage_data(usage_list[0])

        except Exception as e:
            import logging
            logging.getLogger("tencent").warning(f"[{cmd}] 解析失败: {e}")
        return None

    def _build_coding_plan_data(self, pkg: dict) -> dict:
        """从单个 DescribePkg 套餐数据构建标准结果"""
        data = {
            "total_tokens": 0, "input_tokens": 0, "output_tokens": 0,
            "cost": 40, "remaining": "", "plan_name": "codingplan",
            "plan_type": pkg.get("PkgName", "Lite"),
            "platform": "tencent_codingplan",
        }
        quotas = {}

        data["cost"] = pkg.get("Price", 40)
        remaining_days = pkg.get("RemainingDays", 0)
        if remaining_days:
            data["remaining_days"] = remaining_days

        usage_detail = pkg.get("UsageDetail", {})
        for api_key, internal_key in [("PerFiveHour", "5h"), ("PerWeek", "weekly"), ("PerMonth", "monthly")]:
            detail = usage_detail.get(api_key, {})
            if detail:
                total = int(detail.get("Total", 0))
                used_pct = float(detail.get("UsagePercent", 0))
                if used_pct == 0 and total > 0:
                    used_pct = float(detail.get("Used", 0)) / total * 100
                end_time = detail.get("EndTime", "")
                quotas[internal_key] = {
                    "total": total,
                    "used_pct": used_pct,
                    "refresh_at": end_time,
                }

        data["quotas"] = quotas

        if "monthly" in quotas:
            data["total_tokens"] = quotas["monthly"]["total"]
            data["input_tokens"] = quotas["monthly"]["total"] * quotas["monthly"]["used_pct"] // 100
            data["output_tokens"] = quotas["monthly"]["total"] - data["input_tokens"]

        parts = []
        if "5h" in quotas:
            parts.append(f"5h:{quotas['5h']['used_pct']:.1f}%")
        if "weekly" in quotas:
            parts.append(f"周:{quotas['weekly']['used_pct']:.1f}%")
        if "monthly" in quotas:
            parts.append(f"月:{quotas['monthly']['used_pct']:.1f}%")
        data["remaining"] = " | ".join(parts) if parts else ""

        return data

    def _build_token_plan_usage_data(self, item: dict) -> dict:
        """从单个 DescribeTokenPlanUsage 数据构建标准结果"""
        pkg = item.get("TokenPlanPackage", {})
        res = item.get("TokenPlanResource", {})

        capacity = int(res.get("CycleCapacity", 0))
        total_usage = int(res.get("CycleTotalUsage", 0))
        input_usage = int(res.get("CycleInputUsage", 0))
        output_usage = int(res.get("CycleOutputUsage", 0))
        remain = int(res.get("CycleRemain", 0))

        plan_id = pkg.get("Plan", "")
        plan_name = _PLAN_NAMES.get(plan_id, plan_id)
        is_hy = "hy" in plan_id or plan_id == "tp_hy_standard"

        remaining_pct = (remain / capacity * 100) if capacity > 0 else 0

        data = {
            "total_tokens": capacity,
            "input_tokens": total_usage,
            "output_tokens": remain,
            "cost": 0,
            "remaining": f"{remaining_pct:.1f}% ({self._fmt(remain)})",
            "plan_name": "hy_tokenplan" if is_hy else "tokenplan",
            "plan_type": plan_name,
            "platform": "tencent_hy_tokenplan" if is_hy else "tencent_tokenplan",
            "remaining_pct": round(remaining_pct, 1),
            "daily_usage": res.get("DailyUsageList", []),
            "start_time": pkg.get("StartTime", ""),
            "expire_time": pkg.get("ExpireTime", ""),
        }

        return data

    # ============ 凭证解析 ============

    def _parse_credential(self, cookie_str: str) -> Optional[tuple]:
        """解析凭证，返回 (cookie_str, uin, ownerUin, csrfCode) 或 None

        支持格式：
        1. JSON: {"cookie":"xxx","uin":"xxx","ownerUin":"xxx","csrfCode":"xxx"}
        2. Netscape Cookie 文件格式（Get cookies.txt LOCALLY 导出）
        3. 纯 Cookie 字符串: key=value; key=value
        """
        # 尝试 JSON 格式
        try:
            data = json.loads(cookie_str)
            if isinstance(data, dict):
                cookie = data.get("cookie", "")
                uin = data.get("uin", "")
                ownerUin = data.get("ownerUin", uin)
                csrfCode = data.get("csrfCode", "")
                if cookie and uin and csrfCode:
                    return (cookie, uin, ownerUin, csrfCode)
        except (json.JSONDecodeError, TypeError):
            pass

        # 检测 Netscape Cookie 文件格式并转换
        parsed = self._parse_netscape_cookies(cookie_str)
        if parsed:
            cookie_str = parsed  # 转为 key=value; key=value 格式

        # 纯 Cookie 字符串格式，从 Cookie 中提取 uin/ownerUin/csrfCode
        uin = self._extract_cookie_value(cookie_str, "uin")
        ownerUin = self._extract_cookie_value(cookie_str, "ownerUin") or uin
        # csrfCode: 优先取 csrfCode，其次取 qcmainCSRFToken（腾讯云控制台实际字段名）
        csrfCode = (self._extract_cookie_value(cookie_str, "csrfCode")
                    or self._extract_cookie_value(cookie_str, "qcmainCSRFToken"))

        if not uin or not csrfCode:
            return None
        return (cookie_str, uin, ownerUin, csrfCode)

    # ============ HTTP 请求构造 ============

    def _make_api_headers(self, cookie_str: str) -> dict:
        """构造 API 请求头"""
        return {
            "Cookie": cookie_str,
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Referer": "https://console.cloud.tencent.com/tokenhub/codingplan",
            "Accept": "application/json, text/javascript, */*; q=0.01",
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        }

    def _make_params_base(self, uin: str, ownerUin: str, csrfCode: str) -> dict:
        """构造 URL 参数基础模板"""
        return {
            "action": "delegate",
            "secure": "1",
            "version": "3",
            "json": "1",
            "dictId": "3216",
            "sts": "1",
            "uin": uin,
            "ownerUin": ownerUin,
            "csrfCode": csrfCode,
        }

    async def _capi_call(self, client, headers: dict, params_base: dict,
                         cmd: str, extra_data: dict = None) -> Optional[dict]:
        """调用腾讯云控制台内部 API"""
        import httpx
        import logging
        logger = logging.getLogger("tencent")

        params = params_base.copy()
        params["cmd"] = cmd
        params["t"] = str(int(time.time() * 1000))

        body = {
            "regionId": 1,
            "serviceType": "hunyuan",
            "cmd": cmd,
            "data": {"Version": "2023-09-01", "Language": "zh-CN"},
        }
        if extra_data:
            body["data"].update(extra_data)

        try:
            resp = await client.post(TENCENT_CAPI_URL, params=params, headers=headers, json=body)
        except Exception as e:
            logger.warning(f"[{cmd}] HTTP请求失败: {e}")
            return None

        if resp.status_code != 200:
            logger.warning(f"[{cmd}] HTTP {resp.status_code}")
            return None

        try:
            raw = resp.json()
        except json.JSONDecodeError:
            logger.warning(f"[{cmd}] 响应非JSON: {resp.text[:200]}")
            return None

        # 检查登录状态
        code = raw.get("code")
        if code != 0:
            msg = raw.get("message", "")
            logger.warning(f"[{cmd}] API code={code}, message={msg}")
            # 标记 Cookie 失效（code=9 需要登录 / code=60001 CSRF失败）
            if code in (9, 60001) or "login" in msg.lower() or "csrf" in msg.lower():
                self._cookie_expired = True
            return None

        # 嵌套提取 Response
        inner = raw.get("data", {})
        if isinstance(inner, dict):
            inner = inner.get("data", {})
            if isinstance(inner, dict):
                inner = inner.get("Response", inner)

        return inner

    # ============ Coding Plan ============

    async def _fetch_coding_plan(self, client, headers: dict, params_base: dict) -> Optional[dict]:
        """获取 Coding Plan 数据 (DescribePkg)"""
        resp = await self._capi_call(client, headers, params_base, "DescribePkg")
        if not resp:
            return None

        data = {
            "total_tokens": 0, "input_tokens": 0, "output_tokens": 0,
            "cost": 40, "remaining": "", "plan_name": "codingplan",
            "plan_type": "Lite",
        }
        quotas = {}

        # DescribePkg 返回 PkgList
        pkg_list = resp.get("PkgList", [])
        if not pkg_list:
            return None

        # 取第一个套餐（通常只有一个）
        pkg = pkg_list[0]

        # 套餐信息
        data["plan_type"] = pkg.get("PkgName", "Lite")
        data["cost"] = pkg.get("Price", 40)
        remaining_days = pkg.get("RemainingDays", 0)
        if remaining_days:
            data["remaining_days"] = remaining_days

        # 三层配额：UsageDetail.PerFiveHour / PerWeek / PerMonth
        usage_detail = pkg.get("UsageDetail", {})
        for api_key, internal_key in [("PerFiveHour", "5h"), ("PerWeek", "weekly"), ("PerMonth", "monthly")]:
            detail = usage_detail.get(api_key, {})
            if detail:
                total = int(detail.get("Total", 0))
                used_pct = float(detail.get("UsagePercent", 0))
                if used_pct == 0 and total > 0:
                    used_pct = float(detail.get("Used", 0)) / total * 100
                end_time = detail.get("EndTime", "")
                quotas[internal_key] = {
                    "total": total,
                    "used_pct": used_pct,
                    "refresh_at": end_time,
                }

        data["quotas"] = quotas

        if "monthly" in quotas:
            data["total_tokens"] = quotas["monthly"]["total"]
            data["input_tokens"] = quotas["monthly"]["total"] * quotas["monthly"]["used_pct"] // 100
            data["output_tokens"] = quotas["monthly"]["total"] - data["input_tokens"]

        parts = []
        if "5h" in quotas:
            parts.append(f"5h:{quotas['5h']['used_pct']:.1f}%")
        if "weekly" in quotas:
            parts.append(f"周:{quotas['weekly']['used_pct']:.1f}%")
        if "monthly" in quotas:
            parts.append(f"月:{quotas['monthly']['used_pct']:.1f}%")
        data["remaining"] = " | ".join(parts) if parts else ""

        return data

    @staticmethod
    def _map_period(period: str) -> str:
        """将 API 返回的周期标识映射为内部 key"""
        period_lower = str(period).lower()
        if "5h" in period_lower or "fivehour" in period_lower or "five_hour" in period_lower:
            return "5h"
        if "week" in period_lower:
            return "weekly"
        if "month" in period_lower or "sub" in period_lower:
            return "monthly"
        return ""

    # ============ Token Plans ============

    async def _fetch_plan_list(self, client, headers: dict, params_base: dict) -> list[dict]:
        """获取 Token Plan 列表 (ListUserTokenPlans)"""
        resp = await self._capi_call(client, headers, params_base, "ListUserTokenPlans")
        if not resp:
            return []

        plans = resp.get("UserTokenPlanList", [])
        result = []
        for plan in plans:
            plan_id = plan.get("Plan", "")
            edition = plan.get("Edition", "")
            plan_key = self._map_plan_to_key(plan_id, edition)
            result.append({
                "plan_key": plan_key,
                "Plan": plan_id,
                "Edition": edition,
                "Level": plan.get("Level", 0),
                "QuotaStatus": plan.get("QuotaStatus", 0),
                "StartTime": plan.get("StartTime", ""),
                "ExpireTime": plan.get("ExpireTime", ""),
                "ResourceID": plan.get("ResourceID", plan.get("ResourceId", "")),
                "RenewFlag": plan.get("RenewFlag", 0),
            })
        return result

    @staticmethod
    def _map_plan_to_key(plan_id: str, edition: str) -> str:
        """将 API 返回的 Plan/Edition 映射为系统内部 key"""
        if "hy" in plan_id or edition == "hunyuan":
            return "tencent_hy_tokenplan"
        return "tencent_tokenplan"

    async def _fetch_plan_usage(self, client, headers: dict, params_base: dict,
                                plan_info: dict) -> Optional[dict]:
        """获取单个 Token Plan 的用量 (DescribeTokenPlanUsage)

        plan_info 必须包含 Edition 字段（hunyuan/personal）
        """
        extra_data = {
            "Edition": plan_info.get("Edition", "personal"),
        }
        resp = await self._capi_call(
            client, headers, params_base, "DescribeTokenPlanUsage",
            extra_data=extra_data,
        )
        if not resp:
            return None

        usage_list = resp.get("TokenPlanUsageList", [])
        if not usage_list:
            return None

        # 取第一个（应该只有一个，因为按 ResourceId 查询）
        item = usage_list[0]
        pkg = item.get("TokenPlanPackage", {})
        res = item.get("TokenPlanResource", {})

        capacity = int(res.get("CycleCapacity", 0))
        total_usage = int(res.get("CycleTotalUsage", 0))
        input_usage = int(res.get("CycleInputUsage", 0))
        output_usage = int(res.get("CycleOutputUsage", 0))
        remain = int(res.get("CycleRemain", 0))

        plan_id = pkg.get("Plan", "")
        plan_name = _PLAN_NAMES.get(plan_id, plan_id)
        is_hy = "hy" in plan_id or plan_id == "tp_hy_standard"

        remaining_pct = (remain / capacity * 100) if capacity > 0 else 0

        data = {
            "total_tokens": capacity,
            "input_tokens": total_usage,
            "output_tokens": remain,
            "cost": 0,
            "remaining": f"{remaining_pct:.1f}% ({self._fmt(remain)})",
            "plan_name": "hy_tokenplan" if is_hy else "tokenplan",
            "plan_type": plan_name,
            "remaining_pct": round(remaining_pct, 1),
            "daily_usage": res.get("DailyUsageList", []),
            "start_time": pkg.get("StartTime", ""),
            "expire_time": pkg.get("ExpireTime", ""),
        }

        return data

    # ============ 工具方法 ============

    @staticmethod
    def _fmt(n: int) -> str:
        if n >= 100000000:
            return f"{n / 100000000:.1f}亿"
        elif n >= 10000:
            return f"{n / 10000:.1f}万"
        return str(n)
