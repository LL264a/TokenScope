"""DeepSeek 采集器 - 双模式：API Key 查余额 / Token 查用量明细"""

import json
import logging
from typing import Optional
from collectors.base import BaseCollector

logger = logging.getLogger("deepseek")

_DEEPSEEK_PRICES = {
    "deepseek-v4-pro": {
        "prompt": 3.0,        # 每百万token ¥3（未命中缓存）
        "prompt_cache_hit": 0.025,  # 每百万token ¥0.025（命中缓存）
        "response": 6.0,      # 每百万token ¥6
    },
    "deepseek-v4-flash": {
        "prompt": 1.0,
        "prompt_cache_hit": 0.025,
        "response": 2.0,
    },
}


class DeepSeekCollector(BaseCollector):
    """DeepSeek 采集器

    模式1 - API Key 模式:
        用 API Key 调官方 GET /user/balance 查余额
        凭证格式: {"api_key": "sk-xxx"}

    模式2 - Token 模式:
        用登录后 localStorage 中的 userToken 值，调内部 API 查用量明细
        凭证格式: {"token": "xxx"} 或直接粘贴 token 字符串
    """

    platform = "deepseek"

    async def collect(self, cookie_str: str) -> list[dict]:
        import httpx

        try:
            cred = json.loads(cookie_str)
        except (json.JSONDecodeError, TypeError):
            # 纯文本：直接作为 api_key 或 token
            raw = cookie_str.strip()
            if raw.startswith("sk-"):
                cred = {"api_key": raw}
            else:
                cred = {"token": raw}

        api_key = cred.get("api_key", "")
        token = cred.get("token", "")

        # 如果 JSON 解析失败进了 raw 分支
        if not api_key and not token and "raw" in cred:
            raw = cred["raw"].strip()
            if raw.startswith("sk-"):
                api_key = raw
            else:
                token = raw

        # 模式1: API Key → 查余额
        if api_key and not token:
            result = await self._collect_balance(api_key)

        # 模式2: Token → 查用量明细
        elif token:
            result = await self._collect_usage(token)
            # 如果同时有 API Key，合并余额数据
            if api_key and "error" not in result[0]:
                balance_result = await self._collect_balance(api_key)
                if "error" not in balance_result[0]:
                    result[0].update({
                        "balance": balance_result[0].get("balance", 0),
                        "granted_balance": balance_result[0].get("granted_balance", 0),
                        "topped_up_balance": balance_result[0].get("topped_up_balance", 0),
                    })

        else:
            return self._error_result("deepseek", "请提供 API Key 或 Token")

        if "error" in result[0]:
            return result

        return result

    async def _collect_balance(self, api_key: str) -> list[dict]:
        """模式1: 用 API Key 查余额"""
        import httpx

        headers = {
            "Authorization": f"Bearer {api_key}",
            "Accept": "application/json",
        }

        result = {
            "platform": "deepseek",
            "total_tokens": 0,
            "input_tokens": 0,
            "output_tokens": 0,
            "cost": 0,
            "remaining": "-",
        }

        try:
            async with httpx.AsyncClient(timeout=15, verify=False) as client:
                resp = await client.get(
                    "https://api.deepseek.com/user/balance",
                    headers=headers,
                )

                if resp.status_code == 401:
                    return self._error_result("deepseek", "API Key 无效，请检查后重试")
                if resp.status_code != 200:
                    return self._error_result("deepseek", f"请求失败 (HTTP {resp.status_code})")

                data = resp.json()

                if not data.get("is_available"):
                    result["remaining"] = "¥0.00（已用完）"
                else:
                    balance_infos = data.get("balance_infos", [])
                    for bi in balance_infos:
                        if bi.get("currency") == "CNY":
                            total = float(bi.get("total_balance", "0"))
                            granted = float(bi.get("granted_balance", "0"))
                            topped_up = float(bi.get("topped_up_balance", "0"))
                            result.update({
                                "balance": total,
                                "granted_balance": granted,
                                "topped_up_balance": topped_up,
                                "remaining": f"¥{total:.2f}",
                            })
                            break

                result["raw_json"] = json.dumps(data, ensure_ascii=False)

            return [result]

        except httpx.TimeoutException:
            return self._error_result("deepseek", "请求超时")
        except Exception as e:
            logger.error(f"[deepseek] 余额查询异常: {e}")
            return self._error_result("deepseek", str(e))

    async def _collect_usage(self, token: str) -> list[dict]:
        """模式2: 用 UserToken 查用量明细"""
        import httpx

        headers = {
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Origin": "https://platform.deepseek.com",
            "Referer": "https://platform.deepseek.com/usage",
        }

        now = __import__("datetime").datetime.now()
        month = now.month
        year = now.year

        result = {
            "platform": "deepseek",
            "total_tokens": 0,
            "input_tokens": 0,
            "output_tokens": 0,
            "cost": 0,
            "cost_total": 0,
            "remaining": "-",
        }

        try:
            async with httpx.AsyncClient(timeout=15, verify=False) as client:
                # 1. 查用量
                resp = await client.get(
                    f"https://platform.deepseek.com/api/v0/usage/amount?month={month}&year={year}",
                    headers=headers,
                )
                if resp.status_code != 200:
                    return self._error_result("deepseek", f"用量查询失败 (HTTP {resp.status_code})")

                amount_data = resp.json()
                if amount_data.get("code") != 0:
                    # 401-like 错误
                    if "token" in amount_data.get("msg", "").lower():
                        return self._error_result("deepseek", "Token 已过期，请重新登录获取")
                    return self._error_result("deepseek", amount_data.get("msg", "用量查询失败"))

                # 2. 查费用
                resp_cost = await client.get(
                    f"https://platform.deepseek.com/api/v0/usage/cost?month={month}&year={year}",
                    headers=headers,
                )
                cost_biz_data = {}
                if resp_cost.status_code == 200:
                    cost_data = resp_cost.json()
                    cost_totals = cost_data.get("data", {}).get("biz_data", [{}])[0].get("total", [])
                    for ce in cost_totals:
                        cost_biz_data[ce["model"]] = {u["type"]: float(u["amount"]) for u in ce["usage"]}

                # 解析用量
                biz_data = amount_data.get("data", {}).get("biz_data", {})
                totals = biz_data.get("total", [])

                model_usages = []
                total_cost = 0.0
                total_tokens = 0

                for me in totals:
                    model = me.get("model", "")
                    usage = {u["type"]: int(u["amount"]) for u in me.get("usage", [])}
                    cost_map = cost_biz_data.get(model, {})

                    hit = usage.get("PROMPT_CACHE_HIT_TOKEN", 0)
                    miss = usage.get("PROMPT_CACHE_MISS_TOKEN", 0)
                    resp_tok = usage.get("RESPONSE_TOKEN", 0)
                    requests = usage.get("REQUEST", 0)

                    cost_hit = cost_map.get("PROMPT_CACHE_HIT_TOKEN", 0)
                    cost_miss = cost_map.get("PROMPT_CACHE_MISS_TOKEN", 0)
                    cost_resp = cost_map.get("RESPONSE_TOKEN", 0)
                    model_cost = cost_hit + cost_miss + cost_resp
                    total_cost += model_cost
                    total_tokens += hit + miss + resp_tok

                    mu = {
                        "model": model,
                        "prompt_cache_hit": hit,
                        "prompt_cache_miss": miss,
                        "response": resp_tok,
                        "requests": requests,
                        "cost_hit": round(cost_hit, 4),
                        "cost_miss": round(cost_miss, 4),
                        "cost_resp": round(cost_resp, 4),
                        "cost_total": round(model_cost, 4),
                    }
                    model_usages.append(mu)

                # 构建摘要
                summary_lines = []
                for mu in model_usages:
                    line = (f"{mu['model']}: "
                            f"↑{mu['prompt_cache_miss']:,} "
                            f"(cache:{mu['prompt_cache_hit']:,}) "
                            f"↓{mu['response']:,} "
                            f"| ¥{mu['cost_total']:.2f}")
                    summary_lines.append(line)

                result.update({
                    "total_tokens": total_tokens,
                    "input_tokens": sum(mu["prompt_cache_miss"] + mu["prompt_cache_hit"] for mu in model_usages),
                    "output_tokens": sum(mu["response"] for mu in model_usages),
                    "cost": round(total_cost, 2),
                    "cost_total": round(total_cost, 2),
                    "monthly_cost": round(total_cost, 2),
                    "model_usages": model_usages,
                    "raw_json": json.dumps(amount_data, ensure_ascii=False),
                })

            return [result]

        except httpx.TimeoutException:
            return self._error_result("deepseek", "请求超时")
        except Exception as e:
            logger.error(f"[deepseek] 用量查询异常: {e}")
            return self._error_result("deepseek", str(e))
