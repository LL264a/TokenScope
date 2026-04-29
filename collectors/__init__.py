"""数据采集器包 - 可插拔的平台采集模块"""

from collectors.base import BaseCollector
from collectors.tencent import TencentCollector
from collectors.volcano import VolcanoCollector
from collectors.xiaomi import XiaomiCollector

# 注册所有采集器（新增平台只需加一行）
REGISTRY: dict[str, BaseCollector] = {
    "tencent": TencentCollector(),
    "volcano": VolcanoCollector(),
    "xiaomi": XiaomiCollector(),
}

__all__ = ["REGISTRY", "BaseCollector"]
