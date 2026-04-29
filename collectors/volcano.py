"""火山引擎采集器 - 使用 Cookie+CSRF 查询 Coding Plan + AK/SK 查询余额"""

import json
import time
import logging
from typing import Optional
from collectors.base import BaseCollector

logger = logging.getLogger("volcano")

# 火山引擎控制台 API
VOLCANO_CONSOLE_API = "https://console.volcengine.com/api/top/ark/cn-beijing/2024-01-01"

# 三层配额 Level 映射
_LEVEL_MAP = {
    "session": {"name": "每5小时", "icon": "⚡", "key": "5h"},
    "weekly": {"name": "每周", "icon": "📅", "key": "weekly"},
    "monthly": {"name": "每订阅月", "icon": "📆", "key": "monthly"},
}

# 套餐 BizInfo 映射
_BIZ_MAP = {
    "lite": {"name": "Lite", "price": 40},
    "pro": {"name": "Pro", "price": 200},
}


class VolcanoCollector(BaseCollector):
    """火山引擎用量采集 - 支持 Cookie+CSRF (Coding Plan) 和 AK/SK (余额)"""

    platform = "volcano"

    async def collect(self, cookie_str: str) -> list[dict]:
        import httpx
        import asyncio

        # cookie_str 可能是 JSON（含 ak/sk 或 cookie）或纯 Cookie
        try:
            cred = json.loads(cookie_str)
        except (json.JSONDecodeError, TypeError):
            cred = {"cookie": cookie_str}

        ak = cred.get("ak", "")
        sk = cred.get("sk", "")
        cookie = cred.get("cookie", "")

        results = []

        # 1. Coding Plan: Cookie + CSRF 方式
        if cookie:
            coding_plan_data = await self._collect_coding_plan(cookie)
            if coding_plan_data:
                results.append(coding_plan_data)

        # 2. 余额: AK/SK 方式
        if ak and sk:
            balance_data = await asyncio.to_thread(self._query_balance_sync, ak, sk)
            if balance_data:
                results.append(balance_data)

        # 如果两者都没有
        if not results:
            if not cookie and not (ak and sk):
                return self._error_result("volcano", "缺少凭证：需要 Cookie（查Coding Plan）或 AK/SK（查余额）")
            elif cookie and not (ak and sk):
                # 只有 Cookie，Coding Plan 查询失败
                results.append(self._error_item("volcano_codingplan", "Coding Plan 查询失败，Cookie可能已失效"))
            elif ak and sk and not cookie:
                # 只有 AK/SK，无法查 Coding Plan
                pass  # 余额数据已加入

        if not results:
            return self._error_result("volcano", "所有查询均失败")

        return results

    async def _collect_coding_plan(self, cookie_str: str) -> Optional[dict]:
        """用 Cookie + CSRF Token 查询 Coding Plan 三层配额"""
        import httpx

        # 从 Cookie 中提取 CSRF Token
        csrf_token = self._extract_cookie_value(cookie_str, "csrfToken")
        if not csrf_token:
            logger.warning("[volcano] Cookie中无csrfToken，尝试不带CSRF请求")
            self._cookie_expired = False
        else:
            self._cookie_expired = False

        headers = {
            "Cookie": cookie_str,
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Content-Type": "application/json",
            "Referer": "https://console.volcengine.com/ark/region:ark+cn-beijing/openManagement",
            "X-Requested-With": "XMLHttpRequest",
        }
        if csrf_token:
            headers["X-CSRF-Token"] = csrf_token
            headers["X-Volc-CSRF"] = csrf_token

        try:
            async with httpx.AsyncClient(timeout=20, follow_redirects=False, verify=False) as client:
                # 1. 获取 Coding Plan 用量
                usage_data = await self._get_coding_plan_usage(client, headers)
                if not usage_data:
                    return None

                # 2. 获取订阅信息
                subscribe_info = await self._get_subscribe_trade(client, headers)

                # 合并数据
                result = {
                    "platform": "volcano_codingplan",
                    "total_tokens": 0,
                    "input_tokens": 0,
                    "output_tokens": 0,
                    "cost": 0,
                    "remaining": "",
                    "quotas": usage_data.get("quotas", {}),
                    "plan_type": usage_data.get("plan_type", "Coding Plan"),
                    "plan_name": "Coding Plan",
                }

                # 从订阅信息补充
                if subscribe_info:
                    result["cost"] = subscribe_info.get("price", 0)
                    result["plan_type"] = subscribe_info.get("plan_type", "Coding Plan")
                    start = subscribe_info.get("start_time", "")
                    end = subscribe_info.get("end_time", "")
                    if start:
                        result["valid_from"] = start[:10]
                    if end:
                        result["valid_to"] = end[:10]
                        # 计算剩余天数
                        try:
                            end_ts = time.mktime(time.strptime(end[:19], "%Y-%m-%dT%H:%M:%S"))
                            remaining_days = int((end_ts - time.time()) / 86400)
                            if remaining_days > 0:
                                result["remaining_days"] = remaining_days
                        except:
                            pass

                # 计算 remaining 摘要
                quotas = result.get("quotas", {})
                parts = []
                for key in ("5h", "weekly", "monthly"):
                    if key in quotas:
                        parts.append(f"{key}:{quotas[key]['used_pct']:.1f}%")
                result["remaining"] = " | ".join(parts)

                return result

        except Exception as e:
            logger.error(f"[volcano] Coding Plan 采集异常: {e}")
            return None

    async def _get_coding_plan_usage(self, client, headers) -> Optional[dict]:
        """获取 Coding Plan 用量 - GetCodingPlanUsage"""
        try:
            resp = await client.post(
                f"{VOLCANO_CONSOLE_API}/GetCodingPlanUsage?",
                headers=headers,
                json={},
            )

            if resp.status_code == 401 or resp.status_code == 403:
                self._cookie_expired = True
                logger.warning("[volcano] Cookie已失效 (GetCodingPlanUsage 401/403)")
                return None

            if resp.status_code != 200:
                logger.warning(f"[volcano] GetCodingPlanUsage HTTP {resp.status_code}")
                return None

            data = resp.json()
            result_meta = data.get("ResponseMetadata", {})
            if "Error" in result_meta:
                error = result_meta["Error"]
                logger.warning(f"[volcano] GetCodingPlanUsage API error: {error.get('Code')} - {error.get('Message')}")
                if "CSRF" in error.get("Code", ""):
                    self._cookie_expired = True
                return None

            result = data.get("Result", {})
            status = result.get("Status", "")
            quota_usage = result.get("QuotaUsage", [])

            if not quota_usage:
                logger.warning("[volcano] GetCodingPlanUsage 返回空 QuotaUsage")
                return None

            # 转换为统一格式
            quotas = {}
            for item in quota_usage:
                level = item.get("Level", "")
                mapping = _LEVEL_MAP.get(level, {"name": level, "icon": "📊", "key": level})
                pct = item.get("Percent", 0)
                reset_ts = item.get("ResetTimestamp", 0)

                # 格式化恢复时间
                reset_at = ""
                if reset_ts:
                    reset_at = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(reset_ts))

                quotas[mapping["key"]] = {
                    "total": 0,  # API 不返回总量，只有百分比
                    "used_pct": round(pct, 1),
                    "refresh_at": reset_at,
                }

            return {
                "quotas": quotas,
                "plan_type": "Coding Plan",
                "status": status,
            }

        except Exception as e:
            logger.error(f"[volcano] GetCodingPlanUsage 异常: {e}")
            return None

    async def _get_subscribe_trade(self, client, headers) -> Optional[dict]:
        """获取订阅信息 - ListSubscribeTrade"""
        try:
            resp = await client.post(
                f"{VOLCANO_CONSOLE_API}/ListSubscribeTrade?",
                headers=headers,
                json={"ResourceTypes": ["CodingPlan"], "ResourceNames": [""], "BizInfos": ["lite", "pro"]},
            )

            if resp.status_code != 200:
                return None

            data = resp.json()
            if "Error" in data.get("ResponseMetadata", {}):
                return None

            info_list = data.get("Result", {}).get("InfoList", [])
            if not info_list:
                return None

            # 取第一个订阅（通常只有一个）
            sub = info_list[0]
            biz = sub.get("BizInfo", "lite")
            biz_info = _BIZ_MAP.get(biz, {"name": biz, "price": 0})

            return {
                "plan_type": f"Coding Plan {biz_info['name']}",
                "price": biz_info["price"],
                "start_time": sub.get("StartTime", ""),
                "end_time": sub.get("EndTime", ""),
                "auto_renew": sub.get("EnableAutoRenew", False),
                "instance_id": sub.get("InstanceID", ""),
            }

        except Exception as e:
            logger.warning(f"[volcano] ListSubscribeTrade 失败: {e}")
            return None

    @staticmethod
    def _extract_cookie_value(cookie_str: str, key: str) -> str:
        """从 Cookie 字符串中提取指定值"""
        for part in cookie_str.split(";"):
            part = part.strip()
            if part.startswith(f"{key}="):
                return part.split("=", 1)[1]
        return ""

    async def _collect_with_aksdk(self, ak: str, sk: str) -> list[dict]:
        """用 AK/SK 通过官方 SDK 查询余额（保留旧逻辑）"""
        import asyncio

        results = []

        try:
            balance_data = await asyncio.to_thread(self._query_balance_sync, ak, sk)
            if balance_data:
                results.append(balance_data)
            else:
                results.append(self._error_item("volcano", "余额查询返回为空"))
        except Exception as e:
            logger.error(f"[volcano] SDK余额查询失败: {e}")
            results.append(self._error_item("volcano", f"余额查询失败: {e}"))

        if not results:
            return self._error_result("volcano", "所有查询均失败")

        return results

    def _query_balance_sync(self, ak: str, sk: str) -> Optional[dict]:
        """同步方式查询账户余额 - QueryBalanceAcct
        
        SDK 响应字段直接在 response 对象上（无 Result 嵌套）:
        available_balance, cash_balance, credit_limit, freeze_amount, arrears_balance
        """
        from volcenginesdkcore import Configuration, ApiClient
        from volcenginesdkbilling import BILLINGApi
        from volcenginesdkbilling.models import QueryBalanceAcctRequest

        config = Configuration()
        config.ak = ak
        config.sk = sk
        config.region = "cn-beijing"

        client = ApiClient(config)
        api = BILLINGApi(client)

        try:
            resp = api.query_balance_acct(QueryBalanceAcctRequest(config))
            
            available = float(getattr(resp, 'available_balance', 0) or 0)
            cash = float(getattr(resp, 'cash_balance', 0) or 0)
            credit = float(getattr(resp, 'credit_limit', 0) or 0)
            frozen = float(getattr(resp, 'freeze_amount', 0) or 0)
            arrears = float(getattr(resp, 'arrears_balance', 0) or 0)

            return {
                "platform": "volcano",
                "total_tokens": 0,
                "input_tokens": 0,
                "output_tokens": 0,
                "cost": 0,
                "remaining": f"可用 ¥{available:.2f}",
                "balance_available": available,
                "balance_cash": cash,
                "balance_credit": credit,
                "balance_frozen": frozen,
                "balance_arrears": arrears,
                "plan_type": "pay_as_you_go",
                "plan_name": "火山方舟",
            }
        except Exception as e:
            logger.error(f"[volcano] SDK调用异常: {e}")
            return None

    def _error_item(self, key: str, msg: str, cookie_expired: bool = False) -> dict:
        item = {"platform": key, "error": msg}
        if cookie_expired:
            item["cookie_expired"] = True
        return item
