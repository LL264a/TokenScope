# TokenScope 🔭

多平台 AI Token 配额实时监控面板 v1.6.2

实时追踪**腾讯云、火山引擎、小米、DeepSeek** 等平台的 Token 消耗与余额，让你对 AI 资源用量一目了然。

## ✨ 特性

- 🔭 **多平台聚合** — 腾讯云（Coding Plan / Hy Token Plan / Token Plan）、火山引擎（Coding Plan + AK/SK 余额）、小米（MiMo）、**DeepSeek（Token 用量明细 + 余额）**，一个面板全掌握
- 📊 **模型级用量** — DeepSeek 卡片展示 v4-pro / v4-flash 的缓存命中、未命中、输出 Token、请求次数、费用明细
- ⏱️ **实时脉搏** — 服务启动自动刷新，Token 用量持续追踪
- 📊 **可视化配额** — 进度条 + 百分比 + 颜色编码（绿→黄→红），一眼判断剩余额度
- 🔐 **凭证管理** — Cookie / API Key 加密存储，支持在线更新、有效性检查、级联删除
- 🛡️ **过期告警** — Cookie 失效自动识别，统一红色气泡提醒
- 🎨 **暗色主题** — 玻璃态卡片 + 渐变标题 + 流畅动画
- 🪶 **轻量部署** — Python 单文件后端（FastAPI + SQLite），也支持纯 PHP 零配置服务器部署
- 🔌 **可插拔采集器** — 新增平台只需添加一个 collector 文件 + 注册，不改主逻辑

## 🚀 快速开始

### Python 版（推荐本地使用）

```bash
# 克隆项目
git clone https://github.com/LL264a/TokenScope.git
cd TokenScope

# 创建虚拟环境
python -m venv .venv
.venv\Scripts\activate  # Windows
# source .venv/bin/activate  # Linux/macOS

# 安装依赖
pip install fastapi uvicorn httpx

# 启动
python -m uvicorn main:app --host 0.0.0.0 --port 8765 --reload
```

打开 http://localhost:8765 即可使用。

### PHP 版（服务器部署）

将 `server-php/` 目录下的文件部署到支持 PHP 的 Web 服务器即可，零配置。

详见 [server-php/README.md](server-php/README.md)

## 📁 项目结构

```
├── main.py              # 入口 + ASGI 安全中间件
├── config.py            # 平台配置 / 常量
├── db.py                # SQLite 存取层
├── auth.py              # 认证 / 会话 / 限流
├── api.py               # FastAPI 路由
├── scheduler.py         # 定时调度
├── cookie_fetcher.py    # CDP Cookie 获取
├── collectors/          # 可插拔采集器
│   ├── tencent.py       # 腾讯云采集器
│   ├── volcano.py       # 火山引擎采集器
│   ├── xiaomi.py        # 小米采集器
│   └── deepseek.py      # DeepSeek采集器（API Key查余额 / Token查用量明细）
├── static/
│   └── index.html       # 前端页面
└── server-php/          # PHP 服务器部署版本
```

## 🔌 添加新平台

1. 在 `collectors/` 下新建 `your_platform.py`，实现 `collect(credential_data)` 函数
2. 在 `REGISTRY` 中注册
3. 在 `config.py` 中添加平台配置
4. 完成！

## 📜 开源协议

[MIT License](LICENSE)

---

**TokenScope** — 透视 AI 资源，尽在掌控 💚
