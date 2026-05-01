"""采集器基类 - 定义统一接口和公共工具方法"""

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

    # ============ 公共 HTTP 工具 ============

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

    # ============ Cookie 解析公共方法 ============

    @staticmethod
    def _extract_cookie_value(cookie_str: str, key: str) -> str:
        """从 Cookie 字符串中提取指定值"""
        for part in cookie_str.split(";"):
            part = part.strip()
            if part.startswith(f"{key}="):
                return part.split("=", 1)[1]
        return ""

    @staticmethod
    def _parse_netscape_cookies(text: str) -> Optional[str]:
        """解析 Netscape Cookie 文件格式，返回 key=value; key=value 字符串

        Netscape 格式每行: domain\\tinclude_subdomains\\tpath\\tsecure\\texpiry\\tname\\tvalue
        返回 None 表示不是 Netscape 格式
        """
        lines = text.strip().splitlines()
        pairs = []
        is_netscape = False
        for line in lines:
            line = line.strip()
            if not line or line.startswith("#"):
                if any(kw in line.lower() for kw in ("curl", "netscape", "generated")):
                    is_netscape = True
                continue
            parts = line.split("\t")
            if len(parts) >= 7:
                is_netscape = True
                name = parts[5].strip()
                value = parts[6].strip()
                if name:
                    pairs.append(f"{name}={value}")
        if is_netscape and pairs:
            return "; ".join(pairs)
        return None

    # ============ 错误构造 ============

    def _error_result(self, sub_platform: str, message: str,
                      cookie_expired: bool = False) -> list[dict]:
        """构造错误结果，可选标记 Cookie 失效"""
        item = {"platform": sub_platform, "error": message}
        if cookie_expired:
            item["cookie_expired"] = True
        return [item]
