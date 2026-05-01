"""项目配置 - 平台定义、常量、路径"""

from pathlib import Path

# ============ 版本 ============
APP_VERSION = 'v1.7.0'

# ============ 路径 ============
PROJECT_DIR = Path(__file__).parent
DB_PATH = PROJECT_DIR / "token_tracker.db"
STATIC_DIR = PROJECT_DIR / "static"

# ============ Chrome CDP ============
CDP_PORT = 9222
CHROME_EXE = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
CHROME_USER_DATA = Path.home() / "AppData" / "Local" / "Google" / "Chrome" / "User Data"

# ============ 腾讯云控制台内部 API ============
TENCENT_CAPI_URL = "https://console.cloud.tencent.com/cgi/capi"



# ============ 平台配置 ============
PLATFORMS = {
    "tencent": {
        "name": "腾讯云",
        "icon": "🔷",
        "services": [
            {
                "key": "tencent_codingplan",
                "name": "Coding Plan",
                "plan_type": "codingplan",
            },
            {
                "key": "tencent_hy_tokenplan",
                "name": "Hy Token Plan",
                "plan_type": "hy_tokenplan",
            },
            {
                "key": "tencent_tokenplan",
                "name": "Token Plan（个人版）",
                "plan_type": "tokenplan",
            },
        ],
        "credential_types": ["cookie"],
        "cookie_hint": '支持3种格式：\n1. Netscape Cookie文件内容（Get cookies.txt LOCALLY导出，直接粘贴）\n2. key=value; key=value 格式（DevTools复制）\n3. JSON: {"cookie":"完整Cookie","uin":"QQ号","ownerUin":"同uin","csrfCode":"值"}\n\n需要包含: uin, ownerUin, csrfCode(或qcmainCSRFToken)',
    },
    "volcano": {
        "name": "火山引擎",
        "icon": "🌋",
        "services": [
            {
                "key": "volcano_codingplan",
                "name": "Coding Plan",
                "url": "https://console.volcengine.com/ark/region:ark+cn-beijing/openManagement?advancedActiveKey=subscribe",
                "plan_type": "codingplan",
            },
            {
                "key": "volcano",
                "name": "方舟余额",
                "url": "https://console.volcengine.com/ark/region:ark+cn-beijing/usage",
                "plan_type": "volcano",
            },
        ],
        "credential_types": ["cookie", "api_key"],
        "cookie_hint": '支持3种格式：\n1. Netscape Cookie文件（Get cookies.txt LOCALLY导出）\n2. key=value; key=value 字符串\n3. JSON: {"cookie":"完整Cookie"}\n\nCookie中需包含 csrfToken',
        "api_key_hint": 'JSON格式: {"ak":"AccessKey","sk":"SecretKey"}  从火山引擎控制台 → 安全认证获取（仅查余额，不含Coding Plan配额）\n\n💡 同时配Cookie和AK/SK: {"cookie":"完整Cookie","ak":"AK","sk":"SK"}',
    },
    "xiaomi": {
        "name": "MIMO",
        "icon": "🧸",
        "services": [
            {
                "key": "xiaomi",
                "name": "MiMo 用量",
                "url": "https://platform.xiaomimimo.com/console/usage",
                "plan_type": "xiaomi",
            },
        ],
        "credential_types": ["cookie"],
        "cookie_hint": "从浏览器 DevTools 复制 Cookie（需先登录 platform.xiaomimimo.com）",
    },
    "deepseek": {
        "name": "DeepSeek",
        "icon": "🔮",
        "services": [
            {
                "key": "deepseek",
                "name": "DeepSeek 用量",
                "url": "https://platform.deepseek.com/usage",
                "plan_type": "deepseek",
            },
        ],
        "credential_types": ["api_key", "cookie"],
        "api_key_hint": '粘贴 DeepSeek API Key（从 https://platform.deepseek.com/api_keys 获取）\n显示：账户余额（¥）',
        "cookie_hint": '粘贴 Token（登录 https://platform.deepseek.com 后，按F12→Application→LocalStorage→userToken→复制value值）\n显示：按模型Token用量明细 + 月消费金额\n\n也可同时提供：{"api_key":"sk-xxx","token":"xxx"} 同时显示余额和用量',
    },
}

# ============ 腾讯子计划 → 父平台映射 ============
TENCENT_SUB_TO_PARENT = {
    "tencent_codingplan": "tencent",
    "tencent_hy_tokenplan": "tencent",
    "tencent_tokenplan": "tencent",
}

# ============ 火山引擎子计划 → 父平台映射 ============
VOLCANO_SUB_TO_PARENT = {
    "volcano_codingplan": "volcano",
    "volcano": "volcano",
}

# ============ 调度器默认值 ============
DEFAULT_REFRESH_INTERVAL = 60   # 默认60秒
MIN_REFRESH_INTERVAL = 10       # 最少10秒
MAX_REFRESH_INTERVAL = 120      # 最多120秒

# ============ UI 颜色规则 ============
# 已用 0-50% 绿, 50-80% 黄, 80-100% 红
