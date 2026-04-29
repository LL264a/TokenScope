"""采集器基类 - 定义统一接口"""

from abc import ABC, abstractmethod
from typing import Optional


class BaseCollector(ABC):
    """所有平台采集器必须继承此类"""

    @property
    @abstractmethod
    def platform(self) -> str:
        """平台标识，如 'tencent', 'volcano'"""
        ...

    @abstractmethod
    async def collect(self, cookie_str: str) -> list[dict]:
        """采集数据，返回子服务数据列表

        每个元素格式:
        {
            "platform": "tencent_codingplan",  # 子平台key
            "total_tokens": 18000,
            "input_tokens": 3600,
            "output_tokens": 14400,
            "cost": 40,
            "remaining": "5h:0.0% | 周:20.1% | 月:20.0%",
            # 额外字段会自动存入 raw_json:
            "quotas": {...},
            "plan_type": "Lite",
        }

        出错时返回: [{"platform": "xxx", "error": "错误信息"}]
        """
        ...

    def _make_headers(self, cookie_str: str, referer: str = "") -> dict:
        """构造通用请求头"""
        headers = {
            "Cookie": cookie_str,
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
        }
        if referer:
            headers["Referer"] = referer
        return headers

    def _error_result(self, sub_platform: str, message: str) -> list[dict]:
        """快捷返回错误结果"""
        return [{"platform": sub_platform, "error": message}]
