# Token Monitor 服务器部署指南

## 架构

```
本地 Windows (采集)                  服务器 Linux (展示)
┌──────────────────┐               ┌──────────────────┐
│ FastAPI + Chrome  │ ──POST JSON──→│ receive.php 存文件│
│ 每5分钟采集+推送   │               │ index.php 渲染页面 │
└──────────────────┘               └──────────────────┘
```

- **本地**：负责数据采集（需要 Chrome CDP 获取 Cookie），定时推送到服务器
- **服务器**：只负责存储和展示，不依赖本地在线（掉线也能看历史数据）

## 服务器部署（宝塔面板）

### 1. 创建网站

在宝塔面板中创建一个网站，例如 `token.yourdomain.com`

### 2. 上传文件

将 `server/` 目录下的文件上传到网站根目录：

```
/www/wwwroot/token.yourdomain.com/
├── index.php        ← 展示页面（手机访问这个）
├── receive.php      ← 数据接收端
└── data/            ← JSON数据目录（自动创建）
    ├── stats.json
    └── cookie_status.json
```

### 3. 设置权限

```bash
cd /www/wwwroot/token.yourdomain.com
chmod 755 .
chmod 644 index.php receive.php
mkdir -p data && chmod 755 data
```

> 宝塔默认 www 用户有写权限，`data/` 目录一般不需要额外设置

### 4. 修改令牌

编辑 `receive.php`，修改令牌：

```php
$TOKEN = 'tm_2026_你的密码';  // 改成你自己的强密码
```

### 5. Nginx 配置（可选：限制 receive.php 只接受 POST）

在宝塔的网站设置 → Nginx 配置中，可以在 `server{}` 块内添加：

```nginx
# 限制 data 目录不能直接访问
location /data/ {
    deny all;
    return 404;
}
```

### 6. HTTPS（推荐）

在宝塔面板中为网站申请 SSL 证书（Let's Encrypt 免费），开启强制 HTTPS

## 本地配置

### 1. 修改推送脚本

编辑 `push_to_server.py`，修改两处：

```python
SERVER_URL = "https://token.yourdomain.com/receive.php"  # 你的服务器地址
TOKEN = "tm_2026_你的密码"  # 和 receive.php 中一致
```

### 2. 手动测试推送

```bash
python push_to_server.py
```

看到 `[OK] 推送成功` 就说明通了。

### 3. 启动守护推送

```bash
# 每5分钟推送一次
python push_to_server.py --daemon 300
```

### 4. 集成到现有调度器（可选）

也可以在 FastAPI 的 scheduler 中自动推送，不需要单独跑脚本。

## 手机访问

打开 `https://token.yourdomain.com/index.php`

- 页面每 60 秒自动刷新
- Cookie 失效时显示红色警告
- 数据新鲜度显示在顶部（"刚刚"/"5分钟前"/"1小时前"）

## 常见问题

**Q: 推送报 403**
A: 令牌不匹配，检查 receive.php 和 push_to_server.py 中的 TOKEN 是否一致

**Q: data/ 目录写不进去**
A: 检查目录权限，宝塔默认 www 用户需要写权限

**Q: 本地电脑关了还能看吗？**
A: 能，服务器上的 JSON 文件保留着，页面显示的是最后一次推送的数据

**Q: Cookie 过期了怎么办？**
A: 页面会显示红色 "🍪 Cookie 已失效" 警告。需要在本地用 Chrome CDP 重新获取 Cookie
