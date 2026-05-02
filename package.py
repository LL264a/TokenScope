"""打包脚本
用法: C:/Users/ll264/.workbuddy/binaries/python/versions/3.11.9/python.exe package.py
"""
import py7zr
import zipfile
import os, shutil
from pathlib import Path

ROOT = Path(r"E:\Learn\WorkBuddy\Token")
OUT = ROOT / "dist"
CHROME_EXT = ROOT / "chrome-extension"
SERVER_PHP = ROOT / "server-php"

OUT.mkdir(exist_ok=True)

# ============ 排除列表 ============
EXCLUDE_DIRS = {
    "__pycache__", ".venv", ".git", ".workbuddy",
    "browser_data", "cookies", "debug_html", "screenshots",
    "dist", ".vscode", "node_modules",
}
EXCLUDE_FILES = {
    "*.db", "*.pyc", "*.pyo",
    "cookies_extracted.json", "cookies_new.json",
    "api_capture.json",
}
EXCLUDE_PATTERNS = [
    lambda p: p.suffix == ".db",
    lambda p: p.suffix == ".pyc",
    lambda p: p.name == "api_capture.json",
    lambda p: p.name.startswith("check_") and p.suffix == ".py",
    lambda p: p.name.startswith("explore_") and p.suffix == ".py",
    lambda p: p.name.startswith("intercept_") and p.suffix == ".py",
    lambda p: p.name.startswith("click_") and p.suffix == ".py",
    lambda p: p.name.startswith("confirm_") and p.suffix == ".py",
    lambda p: p.name.startswith("fetch_") and p.suffix == ".py",
    lambda p: p.name == "create_aksk.py",
    lambda p: p.name == "create_aksk2.py",
]

def should_exclude(p: Path) -> bool:
    rel = p.relative_to(ROOT)
    parts = rel.parts
    # 排除目录
    for part in parts:
        if part in EXCLUDE_DIRS:
            return True
    # 排除文件
    for pat in EXCLUDE_PATTERNS:
        if pat(p):
            return True
    return False

def collect_files(base: Path) -> list:
    files = []
    for p in base.rglob("*"):
        if p.is_dir():
            continue
        if should_exclude(p):
            continue
        files.append(p)
    return files

# ============ 1. 插件打包 ============
print("=" * 50)
print("1. 打包 Chrome 插件 (chrome_extension.zip)")
plugin_zip = OUT / "chrome_extension_v1.2.0.zip"
plugin_files = list(CHROME_EXT.rglob("*"))
print(f"   文件数: {len(plugin_files)}")
with zipfile.ZipFile(plugin_zip, "w", zipfile.ZIP_DEFLATED) as z:
    for p in plugin_files:
        if p.is_dir():
            continue
        arcname = str(p.relative_to(CHROME_EXT.parent))
        z.write(p, arcname)
print(f"   ✅ {plugin_zip.name} ({plugin_zip.stat().st_size / 1024:.1f} KB)")

# ============ 2. 项目打包 (7z) ============
print("=" * 50)
print("2. 打包完整项目 (TokenScope_v1.8.1.7z)")
project_7z = OUT / "TokenScope_v1.8.1.7z"
project_files = collect_files(ROOT)
print(f"   文件数: {len(project_files)}")

# 计算相对路径
with py7zr.SevenZipFile(project_7z, "w") as z:
    for p in project_files:
        rel = p.relative_to(ROOT)
        z.write(p, str(rel))
size_mb = project_7z.stat().st_size / 1024 / 1024
print(f"   ✅ {project_7z.name} ({size_mb:.1f} MB)")

# ============ 3. 服务端 PHP 打包 ============
print("=" * 50)
print("3. 打包服务端 (server_php_v1.8.1.7z)")
php_7z = OUT / "server_php_v1.8.1.7z"
php_files = list(SERVER_PHP.rglob("*"))
print(f"   文件数: {len(php_files)}")

with py7zr.SevenZipFile(php_7z, "w") as z:
    for p in php_files:
        if p.is_dir() or p.suffix in (".py", ".bak"):
            continue
        arcname = "server-php/" + p.name
        z.write(p, arcname)
size_kb = php_7z.stat().st_size / 1024
print(f"   ✅ {php_7z.name} ({size_kb:.1f} KB)")

print("=" * 50)
print(f"全部打包完成，输出目录: {OUT}")
print(f"  插件:  chrome_extension_v1.2.0.zip")
print(f"  项目:  TokenScope_v1.8.1.7z")
print(f"  服务端: server_php_v1.8.1.7z")
