"""定时调度器 - 独立线程 + asyncio 事件循环"""

import asyncio
import threading
import time
from db import get_setting, set_setting, add_refresh_log
from config import DEFAULT_REFRESH_INTERVAL, MIN_REFRESH_INTERVAL, MAX_REFRESH_INTERVAL


class Scheduler:
    """后台定时刷新调度器"""

    def __init__(self):
        self._running = False
        self._task = None
        self._interval = int(get_setting("refresh_interval", str(DEFAULT_REFRESH_INTERVAL)))
        self._refresh_callback = None  # 由 main.py 注入

    @property
    def interval(self):
        return self._interval

    @interval.setter
    def interval(self, val):
        self._interval = max(MIN_REFRESH_INTERVAL, min(MAX_REFRESH_INTERVAL, int(val)))
        set_setting("refresh_interval", str(self._interval))

    @property
    def running(self):
        return self._running

    def set_refresh_callback(self, callback):
        """注入刷新回调函数"""
        self._refresh_callback = callback

    async def _loop(self):
        """定时刷新循环，每次循环前从数据库读取最新间隔"""
        from db import get_setting
        while self._running:
            try:
                # 每次循环前读取最新间隔（支持运行时动态调整）
                db_interval = int(get_setting("refresh_interval", str(self._interval)))
                self._interval = max(MIN_REFRESH_INTERVAL, min(MAX_REFRESH_INTERVAL, db_interval))
            except (ValueError, TypeError):
                pass
            try:
                if self._refresh_callback:
                    await self._refresh_callback()
            except Exception as e:
                print(f"[SCHEDULER] Error: {e}")
            await asyncio.sleep(self._interval)

    def start(self) -> bool:
        if self._running:
            return False
        self._running = True

        def _thread_target():
            loop = asyncio.new_event_loop()
            asyncio.set_event_loop(loop)
            self._task = loop.create_task(self._loop())
            try:
                loop.run_until_complete(self._task)
            except asyncio.CancelledError:
                pass
            finally:
                loop.close()

        t = threading.Thread(target=_thread_target, daemon=True, name="token-refresh")
        t.start()
        return True

    def stop(self):
        self._running = False
        if self._task and not self._task.done():
            self._task.cancel()
        return True

    def status(self) -> dict:
        return {
            "running": self._running,
            "interval": self._interval,
            "interval_label": f"{self._interval}秒",
        }


# 全局实例
scheduler = Scheduler()
