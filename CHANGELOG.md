# Changelog

## v1.8.0 (2026-05-03)

### Server

- **TOTP 认证修复**: `tm_require_auth()` 未检查 TOTP 头，插件请求全部被 401。修复后在 Bearer Token 之前优先验证 `X-TOTP-Key` + `X-TOTP-Code`
- **API Key 函数缺失修复**: `db.php` 缺少 `tm_create_api_key`、`tm_list_api_keys`、`tm_revoke_api_key`、`tm_verify_totp`、`tm_verify_api_totp` 一整套函数（仅在备份文件 `.bak2` 中有），导致生成密钥"加载失败"
- **php://input 二次消费修复**: `index.php` 和 `api.php` 各读一次 `php://input`，第二次为空。修复后 `index.php` 存 `$GLOBALS['_INDEX_INPUT']`，`api.php` 优先用全局变量
- **tm_json_response 移至 config.php**: 解决 api_keys 路由独立加载时函数未定义的崩溃
- **Netscape Cookie 解析修复**: 新增 `tm_parse_netscape()` 函数，解析插件推送的 Netscape 格式 Cookie
- **删除联动刷新**: 删除凭证后自动清理 usage/log 数据
- **后台面板**: API 密钥管理卡片

### Chrome 插件 (v1.2.0)

- **content.js 完全重写**: 从嵌套回调改为 async/await + Promise 包装 `sendMsg()`，解决"卡在采集中"问题
- **sendMsg 超时保护**: 15秒自动超时 + `chrome.runtime.lastError` 检查
- **popup.js**: 放弃内联 `crypto.subtle`，改走 background `sendMessage` 直接传参
- **background.js**: 所有异步 handler 增加 try/catch 保护，确保 `sendResponse` 100% 调用
- **apiWithTotp 异常保护**: 网络/JSON 错误返回 `{ error }` 不抛异常
- **userToken 自动推送**: DeepSeek 登录后自动读取 localStorage userToken 推送服务器
- **轮询超时**: 10分钟登录等待超时提示

### Bug Fixes

- 修复插件永久卡"采集中"（response 通道丢失）
- 修复 popup 内联 crypto.subtle 在短暂页面中被回收
- 修复删除凭证后前台数据残留
- 修复 token 凭证被 json_decode 打散不识别

### Server

- **TOTP 两步验证**: 新增 API 密钥管理后台，支持生成/吊销密钥，插件免密码 TOTP 认证
- **Netscape Cookie 解析修复**: 小米 MIMO 插件推送的 Netscape 格式 Cookie 无法正确解析，导致采集失败。新增 `tm_parse_netscape()` 函数
- **删除联动刷新**: 删除平台凭证后自动触发该平台数据刷新，前台不再残留旧数据
- **后台面板**: 新增 API 密钥管理卡片，生成后绿色框+复制按钮，复制后自动关闭

### Chrome 插件 (v1.1.0)

- **TOTP 内联计算**: popup 页使用 `crypto.subtle` 直接计算 TOTP，不再依赖 background 中转，解决密钥保存死循环问题
- **密钥保存验证**: 保存时调服务器 `/api/stats` 验证，无效密钥自动清除
- **自动检测已登录**: content.js 先检查 Cookie → 已登录直接采集推送，未登录才自动填表
- **智能平台列表**: 只显示服务器上有 Cookie 的平台，无数据平台折叠
- **一键打开**: 改为打开所有平台（不管是否有 Cookie）
- **插件日志**: 📋 按钮打开日志面板，记录所有操作和 API 调用
- **密钥更新**: 支持"更新"按钮直接修改密钥
- **密钥失效自动提示**: 检测到密钥被吊销后自动清除并提示
- **平台列表动态化**: 从服务器 `/api/platforms` 拉取，不再硬编码

### Bug Fixes

- 修复删除凭证后前台数据残留
- 修复 TOTP 密钥保存时 background 读不到 storage 的时序问题
- 修复生成密钥后绿色提示框被 loadApiKeys 冲掉
