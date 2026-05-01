"""小米 MiMo 采集器 - 使用控制台内部 API + Cookie"""

import json
import time
import logging
from typing import Optional
from collectors.base import BaseCollector

logger = logging.getLogger("xiaomi")


class XiaomiCollector(BaseCollector):
    """小米 MiMo 用量采集 - Cookie + 内部 API
    
    API 端点（2026-04-28 CDP 逆向确认）:
    - /api/v1/usage → tokenUsage + costUsage + pluginUsage + rateLimit
    - /api/v1/balance → balance + giftBalance + cashBalance
    - /api/v1/tokenPlan/detail → 套餐详情(planCode/planName/到期时间/自动续费)
    - /api/v1/tokenPlan/usage → 套餐用量(月度/套餐/补偿额度)
    - /api/v1/tokenPlan/list → 套餐列表和价格
    """

    platform = "xiaomi"

    async def collect(self, cookie_str: str) -> list[dict]:
        import httpx

        # 支持 Netscape Cookie 文件格式（自动检测并转换）
        netscape_parsed = self._parse_netscape_cookies(cookie_str)
        if netscape_parsed:
            cookie_str = netscape_parsed

        # cookie_str 可能是 JSON 或纯 Cookie
        try:
            cred = json.loads(cookie_str)
        except (json.JSONDecodeError, TypeError):
            cred = {"cookie": cookie_str}

        cookie = cred.get("cookie", "")
        if not cookie:
            return self._error_result("xiaomi", "Cookie为空，请先登录小米MiMo平台")

        headers = {
            "Cookie": cookie,
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "zh-CN,zh;q=0.9",
            "Referer": "https://platform.xiaomimimo.com/console/plan-manage",
            "Origin": "https://platform.xiaomimimo.com",
        }

        self._cookie_expired = False
        result = {
            "platform": "xiaomi",
            "total_tokens": 0,
            "input_tokens": 0,
            "output_tokens": 0,
            "cost": 0,
            "remaining": "-",
        }

        try:
            async with httpx.AsyncClient(timeout=30, follow_redirects=False, verify=False) as client:
                # 1. Token Plan 详情（套餐信息）
                plan_data = await self._query_plan_detail(client, headers)
                if plan_data:
                    result.update(plan_data)
                elif self._cookie_expired:
                    return self._error_result("xiaomi", "Cookie已失效，请重新登录小米平台")

                # 2. Token Plan 用量（核心配额数据）
                plan_usage = await self._query_plan_usage(client, headers)
                if plan_usage:
                    result.update(plan_usage)
                elif self._cookie_expired:
                    return self._error_result("xiaomi", "Cookie已失效，请重新登录小米平台")

                # 3. 通用用量（速率限制等）
                usage_data = await self._query_usage(client, headers)
                if usage_data:
                    result.update(usage_data)

                # 4. 余额
                balance_data = await self._query_balance(client, headers)
                if balance_data:
                    result.update(balance_data)

            return [result]

        except Exception as e:
            logger.error(f"[xiaomi] 采集异常: {e}")
            return self._error_result("xiaomi", str(e))

    async def _query_plan_detail(self, client, headers) -> Optional[dict]:
        """查询套餐详情 - /api/v1/tokenPlan/detail
        
        返回:
        {
          "code": 0,
          "data": {
            "planCode": "lite",
            "planName": "Lite", 
            "currentPeriodEnd": "2026-05-23 23:59:59",
            "expired": false,
            "enableAutoRenew": true,
            "hasAutoRenewSubscribed": true
          }
        }
        """
        try:
            resp = await client.get(
                "https://platform.xiaomimimo.com/api/v1/tokenPlan/detail",
                headers=headers,
            )
            if resp.status_code == 401:
                self._cookie_expired = True
                return None
            if resp.status_code != 200:
                return None

            data = resp.json()
            if data.get("code") != 0:
                return None

            d = data.get("data", {})
            result = {
                "plan_type": d.get("planName", ""),
                "plan_code": d.get("planCode", ""),
                "auto_renew": d.get("enableAutoRenew", False),
            }

            # 计算剩余天数
            end_str = d.get("currentPeriodEnd", "")
            if end_str:
                result["valid_to"] = end_str[:10]
                try:
                    end_ts = time.mktime(time.strptime(end_str[:19], "%Y-%m-%dT%H:%M:%S"))
                    remaining_days = int((end_ts - time.time()) / 86400)
                    if remaining_days > 0:
                        result["remaining_days"] = remaining_days
                except:
                    pass

            # 套餐价格（从 plan_code 映射）
            _PLAN_PRICE = {"lite": 39, "pro": 199}
            plan_code = d.get("planCode", "")
            if plan_code in _PLAN_PRICE:
                result["cost"] = _PLAN_PRICE[plan_code]

            return result

        except Exception as e:
            logger.warning(f"[xiaomi] tokenPlan/detail failed: {e}")
            return None

    async def _query_plan_usage(self, client, headers) -> Optional[dict]:
        """查询套餐用量 - /api/v1/tokenPlan/usage
        
        返回:
        {
          "code": 0,
          "data": {
            "monthUsage": {
              "percent": 1,
              "items": [{"name": "month_total_token", "used": 63848958, "limit": 60000000, "percent": 1.0641}]
            },
            "usage": {
              "percent": 0.81,
              "items": [
                {"name": "plan_total_token", "used": 48420387, "limit": 60000000, "percent": 0.81},
                {"name": "compensation_total_token", "used": 15428571, "limit": 15428571, "percent": 1.00}
              ]
            }
          }
        }
        """
        try:
            resp = await client.get(
                "https://platform.xiaomimimo.com/api/v1/tokenPlan/usage",
                headers=headers,
            )
            if resp.status_code == 401:
                self._cookie_expired = True
                return None
            if resp.status_code != 200:
                return None

            data = resp.json()
            if data.get("code") != 0:
                return None

            d = data.get("data", {})
            result = {}

            # 月度用量（跨套餐+补偿的总月度消耗）
            month_usage = d.get("monthUsage", {})
            month_items = month_usage.get("items", [])
            if month_items:
                month_item = month_items[0]
                result["month_used"] = month_item.get("used", 0)
                result["month_limit"] = month_item.get("limit", 0)
                result["month_pct"] = round(month_item.get("percent", 0) * 100, 1)

            # 套餐+补偿分项
            usage = d.get("usage", {})
            usage_items = usage.get("items", [])
            for item in usage_items:
                name = item.get("name", "")
                if name == "plan_total_token":
                    # 套餐额度
                    plan_limit = item.get("limit", 0)
                    plan_used = item.get("used", 0)
                    plan_pct = item.get("percent", 0)
                    result["total_tokens"] = plan_limit
                    result["input_tokens"] = plan_used  # 复用字段存储已使用量
                    result["remaining_pct"] = round((1 - plan_pct) * 100, 1)
                    result["plan_pct"] = round(plan_pct * 100, 1)
                elif name == "compensation_total_token":
                    # 补偿额度
                    comp_limit = item.get("limit", 0)
                    comp_used = item.get("used", 0)
                    comp_pct = item.get("percent", 0)
                    result["comp_total"] = comp_limit
                    result["comp_used"] = comp_used
                    result["comp_pct"] = round(comp_pct * 100, 1)

            # remaining 摘要
            if "remaining_pct" in result:
                result["remaining"] = f"{result['remaining_pct']:.1f}%"
            
            return result

        except Exception as e:
            logger.warning(f"[xiaomi] tokenPlan/usage failed: {e}")
            return None

    async def _query_usage(self, client, headers) -> Optional[dict]:
        """查询通用用量 - /api/v1/usage（速率限制等补充信息）"""
        try:
            resp = await client.get(
                "https://platform.xiaomimimo.com/api/v1/usage",
                headers=headers,
            )
            if resp.status_code == 401:
                self._cookie_expired = True
                return None
            if resp.status_code != 200:
                return None

            data = resp.json()
            if data.get("code") != 0:
                return None

            d = data.get("data", {})
            result = {
                "tpm": d.get("accountRateLimit", {}).get("tpm", 0),
                "rpm": d.get("accountRateLimit", {}).get("rpm", 0),
                "cache_tokens": d.get("tokenUsage", {}).get("cacheToken", 0) or 0,
            }

            # 如果 tokenPlan/usage 没拿到数据，退回使用 /api/v1/usage 的总量
            cost_usage = d.get("costUsage", {})
            if "current_month_cost" not in result:
                result["current_month_cost"] = float(cost_usage.get("currentMonthCost", "0") or 0)

            return result

        except Exception as e:
            logger.warning(f"[xiaomi] usage query failed: {e}")
            return None

    async def _query_balance(self, client, headers) -> Optional[dict]:
        """查询余额 - /api/v1/balance"""
        try:
            resp = await client.get(
                "https://platform.xiaomimimo.com/api/v1/balance",
                headers=headers,
            )
            if resp.status_code != 200:
                return None

            data = resp.json()
            if data.get("code") != 0:
                return None

            d = data.get("data", {})
            balance = float(d.get("balance", "0") or 0)
            gift = float(d.get("giftBalance", "0") or 0)
            cash = float(d.get("cashBalance", "0") or 0)
            frozen = float(d.get("frozenBalance", "0") or 0)
            
            result = {
                "balance": balance,
                "gift_balance": gift,
                "cash_balance": cash,
                "frozen_balance": frozen,
            }
            # 不覆盖 remaining，Token Plan 的百分比更有意义
            
            return result

        except Exception as e:
            logger.warning(f"[xiaomi] balance query failed: {e}")
            return None
