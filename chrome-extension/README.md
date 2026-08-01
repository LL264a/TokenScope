# TokenScope Chrome 扩展 - Cookie 采集器

## 工作流程

### 自动模式（推荐）
```
1. 点击插件图标 → 选择平台 → 自动打开平台登录页
2. 登录后 content.js 自动检测 Cookie → 右上角浮窗 → 自动采集推送
3. TokenScope 面板数据自动更新
```

### 手动同步（Cookie 过期后补刷）
```
1. 登录平台控制台（如 console.volcengine.com）
2. 点插件图标 → 每个平台卡片右侧有 🔄 按钮
3. 点击 🔄 → 自动读取当前浏览器 Cookie（含 HttpOnly）→ 推送到服务器
4. 或点底部「🔄 同步全部 Cookie」一次性同步所有平台
```

## 安装

1. Chrome → `chrome://extensions`
2. 开启"开发者模式"
3. "加载已解压的扩展程序" → 选择 `chrome-extension/` 目录
4. 更新时：点扩展卡片上的「刷新」按钮即可

## 使用

1. 点插件图标 → 粘贴 API 密钥（名称|种子）→ 保存
2. 点平台按钮 → 自动打开该平台登录页
3. 登录成功后，页面右上角自动出现绿色浮窗
4. Cookie 自动采集并推送到服务器

## Cookie 一键同步（v1.3.0+）

每个平台卡片右侧的 🔄 按钮：
- 点击后通过 `chrome.cookies.getAll()` 读取该平台域名的所有 Cookie（**含 HttpOnly**）
- 自动推送到 TokenScope 服务器，免去手动复制粘贴
- 底部「🔄 同步全部 Cookie」按钮可一次性同步所有可见平台

## 支持平台

| 平台 | Cookie 域名 | 打开链接 |
|------|-----------|----------|
| 腾讯云 | .cloud.tencent.com | console.cloud.tencent.com/tokenhub/codingplan |
| 火山引擎 | .volcengine.com | console.volcengine.com/ark/region:ark+cn-beijing/plan |
| DeepSeek | .deepseek.com | platform.deepseek.com/usage |
| 小米 MIMO | .xiaomimimo.com | platform.xiaomimimo.com/console/plan-manage |
| MiniMax | .minimaxi.com | platform.minimaxi.com |

## 服务器

https://ait.ll264a.cn
