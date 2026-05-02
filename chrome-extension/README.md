# TokenScope Chrome 扩展 - Cookie 采集器

## 工作流程

```
1. 点击插件图标 → 选择平台 → 自动打开平台登录页
2. 输入账号密码/扫码 → 登录
3. content.js 自动检测到 Cookie → 浮窗出现 → 自动采集推送
4. TokenScope 面板数据自动更新
```

## 安装

1. Chrome → `chrome://extensions`
2. 开启"开发者模式"
3. "加载已解压的扩展程序" → 选择 `chrome-extension/` 目录

## 使用

1. 点插件图标 → 输入 TokenScope 密码登录
2. 点平台按钮（腾讯云/火山/DeepSeek/小米）→ 自动打开该平台登录页
3. 登录成功后，页面右上角自动出现绿色浮窗
4. Cookie 自动采集并推送到服务器

## 支持平台

| 平台 | 打开链接 |
|------|----------|
| 腾讯云 | console.cloud.tencent.com/tokenhub/codingplan |
| 火山引擎 | console.volcengine.com/ark/region:ark+cn-beijing/plan |
| DeepSeek | platform.deepseek.com/usage |
| 小米 MIMO | platform.xiaomimimo.com/console/plan-manage |

## 服务器

https://ait.ll264a.cn
