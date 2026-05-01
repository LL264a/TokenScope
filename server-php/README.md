# Token Monitor v1.7.0 PHP 版 - 部署指南

## 🎯 一句话

把文件丢进宝塔网站目录，加一行 Nginx 配置，设一个 cron 任务，完事。

## 📦 环境要求

| 依赖 | 要求 | 说明 |
|------|------|------|
| PHP | ≥ 8.2 | `match` 表达式、联合类型 |
| SQLite3 | PHP 内置 | 数据 + 会话持久化 |
| cURL | PHP 内置 | HTTP 请求 |
| OpenSSL | PHP 内置 | PBKDF2-SHA256 |
| Web Server | Nginx / Apache | try_files 路由 |
| 磁盘 | ~10MB | SQLite 数据库 |

## 📁 文件列表

```
/www/wwwroot/token-monitor/
├── index.php          # 统一入口（路由 API 和页面）
├── api.php            # API 路由
├── config.php         # 配置
├── db.php             # 数据库
├── auth.php           # 认证
├── collectors.php     # 数据采集（腾讯/火山/小米/DeepSeek）
├── cron.php           # 定时采集入口
├── receive.php        # 接收 Python 实例推送数据
├── index.html         # 前端页面
└── data/              # 自动创建（SQLite + 会话文件）
```

## 🚀 部署步骤

### 1. 上传文件

把所有文件上传到宝塔网站根目录，如 `/www/wwwroot/token-monitor/`

### 2. Nginx 配置（必须！只加一行）

在宝塔 → 网站 → 设置 → Nginx 配置的 `server{}` 块内，找到类似 `location / {` 的地方，改成：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

这样所有 `/api/*` 请求都会路由到 `index.php`。

### 3. 设置权限

```bash
cd /www/wwwroot/token-monitor
chmod 755 .
chmod 644 *.php *.html
mkdir -p data && chmod 755 data
```

### 4. 设定定时采集（宝塔 Cron）

在宝塔 → 计划任务 → 添加任务：

- **任务类型**：Shell 脚本
- **任务名称**：Token Monitor 采集
- **执行周期**：每 5 分钟
- **脚本内容**：

```bash
/usr/bin/php /www/wwwroot/token-monitor/cron.php
```

> 注意：PHP 路径用你宝塔的 PHP 版本路径，如 `/www/server/php/82/bin/php`

### 5. 访问

打开 `https://你的域名/`

- 首次访问：设置管理密码
- 管理页：添加各平台 Cookie
- 之后自动采集刷新

## 🍪 手动添加 Cookie

服务器没有 Chrome 浏览器，需要手动从本地电脑复制 Cookie：

1. 本地浏览器登录对应平台
2. F12 → Network → 任意请求 → 复制 Cookie 头
3. Token Monitor 管理页 → 添加凭证 → 粘贴

| 平台 | 格式 |
|------|------|
| 腾讯云 | `{"cookie":"完整Cookie","uin":"QQ号","ownerUin":"同uin","csrfCode":"940711892"}` |
| 火山引擎 | Cookie 字符串（含 csrfToken），如需查余额可加 AK/SK |
| 小米 MIMO | Cookie 字符串（登录 platform.xiaomimimo.com 后复制） |
| DeepSeek | API Key: `sk-xxx`（查余额）；Token: 登录后 F12→LocalStorage→userToken 复制（查用量明细） |

## ⚠️ 常见问题

**Q: 页面 404 或 API 返回 Not Found**
A: Nginx 没配置 `try_files`，请检查第 2 步

**Q: 页面空白**
A: 检查 PHP 版本 >= 8.2

**Q: 采集失败**
A: Cookie 过期了，需要重新从浏览器复制粘贴

**Q: 火山余额查询失败**
A: 需要配置 AK/SK（火山引擎控制台 → 安全认证），在管理页添加 api_key 类型凭证

**Q: data 目录没写权限**
A: `chmod 755 data && chown www:www data`

## 🔄 从本地 Python 实例推送数据

如果你本地有 Python TokenScope 在跑，可以定时推送到服务器：

1. 修改 `push_to_server.py` 中的 `SERVER_URL` 和 `TOKEN`
2. 修改 `server-php/receive.php` 中的 `RECEIVE_TOKEN` 保持一致
3. 运行: `python push_to_server.py --daemon 300`（每5分钟推送一次）
