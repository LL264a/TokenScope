# Changelog

## v1.9.1 (2026-05-04)

### Server

- **安全: 移除全局 SSL 验证关闭**: 删除 `CURLOPT_SSL_VERIFYPEER => false`，恢复系统 CA 证书校验
- **修复: 腾讯/火山 Coding Plan "剩余"实际显示"已用"**: `remaining` 字段改为 `100 - used_pct`，与字段名一致
- **优化: 自动刷新 toast 仅在数据变化时弹出**: 避免每 30 秒弹通知
- **优化: WAL checkpoint 自动清理**: 采集结束后执行 `PRAGMA wal_checkpoint(TRUNCATE)`
- **修复: DeepSeek 月初空数据回退上月**: 当月用量返回空时自动降级到上月查询

### Chrome 插件 (v1.2.0)

- 无改动

## v1.9.0 (2026-05-04)

### Server

- **安全: 敏感数据目录移出 Web 可访问区域**: data/ 从 `wwwroot/data/` 迁移到 `/var/lib/token-monitor/data/`，Nginx 无法直接访问 DB 和日志文件
- **安全: 会话和限流迁移到 SQLite 原子操作**: 从 JSON 文件改用 SQLite 表，修复高并发下会话丢失和限流绕过的 TOCTOU 严重漏洞
- **实时排序优化**: 按活跃度排序（有数据>无数据，同批次用量降序），前端权重模式读取 localStorage 拖动顺序
- **排序稳定性修复**: 批量刷新使用统一时间戳，消除排序跳动
- **DS 面板增强**: 模型名称 V4-Pro/V4-Flash，缓存命中率显示，月累计Token 亿单位
- **倒计时样式/刷新动画/通知位置**: 整段居中、点击旋转、通知下移 60px
- **移动端适配**: 管理页自适应、倒计时只保留秒数
- **cron 调度漂移修复**: sleep 扣除采集耗时，保证实际间隔准确
- **前端稳定性**: stats fetch 加 catch、model_usages null 过滤、renderHideBtn 死函数清除
- **拖动排序重写**: 不卡死 + localStorage 自动保存 + 触控支持
- **凭证排序修正**: volcano 子平台清理 key 修复（去除不存在的 volcano_ark/volcano_balance）
- **DeepSeek 凭证简化**: 移除 Netscape Cookie 分支，仅接受 sk- 或 token

### Chrome 插件 (v1.2.0)

- 无改动

## v1.8.2 (2026-05-03)

### Server

- **移动端自适应**: 卡片字体缩小、网格变单列、顶栏优化（仅显示图标、隐藏连接状态、标题+版本号两行显示）
- **MIMO 余额上移**: 余额显示在套餐额度之前
- **MIMO 移动端不换行**: 减小字体和间隙，保持一行显示

### Chrome 插件 (v1.2.0)

- 无改动

### Server

- **/api/platforms 按凭证过滤**: 只返回有凭证的平台（deepseek + xiaomi），content.js 和 popup.js 自动跟着走，不再采集无凭证的平台（腾讯云、火山引擎）

### Chrome 插件 (v1.2.0)

- 无改动

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
