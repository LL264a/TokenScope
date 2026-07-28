"""Token Monitor - 入口文件"""

import sys
import io
import os

# Windows GBK终端修复
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

from contextlib import asynccontextmanager
from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from starlette.types import ASGIApp, Receive, Scope, Send

from db import init_tables, get_setting
from config import STATIC_DIR
from api import router, _do_refresh_all
from scheduler import scheduler


# ============ 安全中间件（纯 ASGI，避免 BaseHTTPMiddleware 的问题） ============

class SecurityHeadersMiddleware:
    """添加安全响应头（纯 ASGI 中间件，兼容 uvicorn async）"""
    def __init__(self, app: ASGIApp):
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        async def send_with_headers(message):
            if message["type"] == "http.response.start":
                headers = dict(message.get("headers", []))
                security_headers = {
                    b"x-content-type-options": b"nosniff",
                    b"x-frame-options": b"DENY",
                    b"x-xss-protection": b"1; mode=block",
                    b"referrer-policy": b"strict-origin-when-cross-origin",
                    b"content-security-policy": (
                        b"default-src 'self'; "
                        b"style-src 'self' 'unsafe-inline'; "
                        b"script-src 'self' 'unsafe-inline'; "
                        b"connect-src 'self'; "
                        b"img-src 'self' data:; "
                        b"font-src 'self'; "
                        b"frame-ancestors 'none'"
                    ),
                }
                headers.update(security_headers)
                message["headers"] = list(headers.items())
            await send(message)

        await self.app(scope, receive, send_with_headers)


# ============ 应用生命周期 ============

@asynccontextmanager
async def lifespan(app_instance):
    """启动：初始化数据库 + 自动启动调度器"""
    init_tables()
    # 启动时清理历史冗余数据，防止本地库无限制增长（与服务器 TM_DATA_RETENTION_DAYS 一致）
    try:
        import db
        res = db.prune_old_usage()
        if res["platform_usage_removed"] or res["freed_mb"]:
            print(f"[DB] 启动清理历史数据: 删除 {res['platform_usage_removed']} 行, "
                  f"释放 {res['freed_mb']} MB (库现 {res['db_size_mb']} MB)")
    except Exception as e:
        print(f"[DB] 启动清理失败(可忽略): {e}")
    scheduler.set_refresh_callback(_do_refresh_all)
    if get_setting("scheduler_auto_start", "0") == "1":
        scheduler.start()
        print("[SCHEDULER] Auto-started")
    yield
    scheduler.stop()


# ============ 创建应用 ============

# 生产环境禁用 Swagger 文档（环境变量控制）
docs_enabled = os.environ.get("TOKEN_MONITOR_DOCS", "0") == "1"

app = FastAPI(
    title="Token Monitor",
    lifespan=lifespan,
    docs_url="/docs" if docs_enabled else None,
    redoc_url="/redoc" if docs_enabled else None,
    openapi_url="/openapi.json" if docs_enabled else None,
)

# CORS 中间件（可通过环境变量配置允许的域名）
cors_origin = os.environ.get("TOKEN_MONITOR_CORS_ORIGIN", "")
if cors_origin:
    from fastapi.middleware.cors import CORSMiddleware
    origins = [o.strip() for o in cors_origin.split(",") if o.strip()]
    app.add_middleware(
        CORSMiddleware,
        allow_origins=origins,
        allow_credentials=True,
        allow_methods=["GET", "POST", "DELETE", "OPTIONS"],
        allow_headers=["Authorization", "Content-Type", "X-Internal-Key"],
    )

# 安全响应头中间件（纯 ASGI）
app.add_middleware(SecurityHeadersMiddleware)

# 路由
app.include_router(router)

# 静态文件
STATIC_DIR.mkdir(exist_ok=True)
app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


@app.get("/")
def index():
    """返回前端页面"""
    return FileResponse(str(STATIC_DIR / "index.html"))


# ============ 全局异常处理 ============

@app.exception_handler(401)
async def unauthorized_handler(request: Request, exc):
    """401 统一返回 JSON，方便前端处理"""
    return JSONResponse(
        status_code=401,
        content={"detail": str(exc.detail) if hasattr(exc, 'detail') else "未授权"},
    )


@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc):
    """全局异常处理：记录错误详情"""
    import traceback
    traceback.print_exc()
    return JSONResponse(
        status_code=500,
        content={"detail": str(exc)},
    )


if __name__ == "__main__":
    import uvicorn
    print("Token Monitor")
    print("  Dashboard: http://localhost:8765")
    if docs_enabled:
        print("  API docs:  http://localhost:8765/docs")
    uvicorn.run("main:app", host="0.0.0.0", port=8765, reload=False)
