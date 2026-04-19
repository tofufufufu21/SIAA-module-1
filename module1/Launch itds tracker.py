#!/usr/bin/env python3
"""
============================================================
  IT&DS Tracker — Module 1: Asset & Inventory Master
  LAUNCHER SCRIPT  (XAMPP-aware)

  HOW TO RUN:
    python launch_itds_tracker.py

  In VS Code: open Terminal (Ctrl+`) and type:
    python launch_itds_tracker.py

  NOTE: Run via Terminal, NOT via the debugger (F5).
        The debugger intercepts sys.exit() as an exception.

  This script:
    1. Finds PHP — checks XAMPP, WAMP, Laragon, and PATH
    2. Starts a PHP built-in development server
    3. Opens the IT&DS Tracker in your default browser
    4. Press Ctrl+C in the terminal to stop
============================================================
"""

import subprocess
import sys
import os
import time
import webbrowser
import signal
import platform
import shutil
from pathlib import Path


# ── CONFIG ────────────────────────────────────────────────────
HOST       = "localhost"
PORT       = 8080
OPEN_DELAY = 1.5   # seconds before opening browser

# Auto-detect the folder to serve
SCRIPT_DIR = Path(__file__).resolve().parent

if (SCRIPT_DIR / "index.html").exists():
    SERVE_DIR = SCRIPT_DIR                      # script is inside module1/
elif (SCRIPT_DIR / "module1" / "index.html").exists():
    SERVE_DIR = SCRIPT_DIR / "module1"          # script is one level above
else:
    SERVE_DIR = SCRIPT_DIR                      # fallback

# ── ANSI Colors ───────────────────────────────────────────────
IS_WINDOWS = platform.system() == "Windows"

if IS_WINDOWS:
    import ctypes
    try:
        ctypes.windll.kernel32.SetConsoleMode(
            ctypes.windll.kernel32.GetStdHandle(-11), 7
        )
        USE_COLOR = True
    except Exception:
        USE_COLOR = False
else:
    USE_COLOR = hasattr(sys.stdout, "isatty") and sys.stdout.isatty()

class C:
    RESET   = "\033[0m"
    BOLD    = "\033[1m"
    CYAN    = "\033[36m"
    GREEN   = "\033[32m"
    YELLOW  = "\033[33m"
    RED     = "\033[31m"
    DIM     = "\033[2m"

def clr(text, *codes):
    if not USE_COLOR:
        return text
    return "".join(codes) + text + C.RESET

def banner():
    print()
    print(clr("╔══════════════════════════════════════════════════╗", C.CYAN, C.BOLD))
    print(clr("║   IT&DS Tracker — Module 1: Asset & Inventory   ║", C.CYAN, C.BOLD))
    print(clr("║               Development Launcher               ║", C.CYAN, C.BOLD))
    print(clr("╚══════════════════════════════════════════════════╝", C.CYAN, C.BOLD))
    print()

def section(title):
    print(clr(f"\n  ▸ {title}", C.CYAN, C.BOLD))

def ok(msg):
    print(clr(f"    ✓  {msg}", C.GREEN))

def warn(msg):
    print(clr(f"    ⚠  {msg}", C.YELLOW))

def err(msg):
    print(clr(f"    ✗  {msg}", C.RED))

def info(msg):
    print(clr(f"    →  {msg}", C.DIM))

def hr():
    print(clr("  " + "─" * 50, C.DIM))


# ── FIND PHP ─────────────────────────────────────────────────
# Add your custom XAMPP path here if it is installed in a
# non-standard location, e.g.:  r"D:\xampp\php\php.exe"
WINDOWS_PHP_PATHS = [
    r"C:\xampp\php\php.exe",
    r"C:\xampp8\php\php.exe",
    r"C:\xampp7\php\php.exe",
    r"C:\wamp64\bin\php\php8.2.0\php.exe",
    r"C:\wamp64\bin\php\php8.1.0\php.exe",
    r"C:\wamp\bin\php\php8.2.0\php.exe",
    r"C:\laragon\bin\php\php-8.2\php.exe",
    r"C:\laragon\bin\php\php-8.1\php.exe",
    r"C:\laragon\bin\php\php-8.0\php.exe",
    r"C:\php\php.exe",
    r"C:\php8\php.exe",
]

def find_php():
    # 1. Check well-known Windows install paths
    if IS_WINDOWS:
        for path in WINDOWS_PHP_PATHS:
            if Path(path).exists():
                return path

        # Glob for WAMP/Laragon versioned dirs
        import glob
        for pattern in [
            r"C:\wamp64\bin\php\php*\php.exe",
            r"C:\wamp\bin\php\php*\php.exe",
            r"C:\laragon\bin\php\php-*\php.exe",
        ]:
            matches = sorted(glob.glob(pattern), reverse=True)
            if matches:
                return matches[0]

    # 2. Check system PATH
    for cmd in ["php", "php8", "php8.2", "php8.1", "php8.0", "php7.4"]:
        path = shutil.which(cmd)
        if path:
            return path

    return None


# ── CHECKS ────────────────────────────────────────────────────

def check_php():
    section("Checking PHP")
    php = find_php()

    if not php:
        err("PHP not found.")
        print()
        print(clr("  You are using XAMPP — PHP was found but is not in PATH.", C.YELLOW, C.BOLD))
        print(clr("  Fix this with ONE of the options below:", C.YELLOW))
        print()
        print(clr("  ── Option A: Add XAMPP to PATH (one-time fix) ─────────", C.CYAN))
        print("    1. Press Win + S → search 'Environment Variables'")
        print("    2. Click 'Edit the system environment variables'")
        print("    3. Click 'Environment Variables…' button")
        print("    4. Under 'System variables' → find 'Path' → Edit")
        print(r"    5. Click 'New' and add:  C:\xampp\php")
        print("    6. Click OK everywhere → RESTART VS Code")
        print("    7. Run this script again from the Terminal")
        print()
        print(clr("  ── Option B: Run from XAMPP Shell (quick fix) ─────────", C.CYAN))
        print("    1. Open XAMPP Control Panel")
        print("    2. Click the 'Shell' button")
        print(r"    3. Type:  cd C:\xampp\htdocs\module1")
        print("    4. Type:  python launch_itds_tracker.py")
        print()
        print(clr("  ── Option C: Use VS Code Terminal with XAMPP PHP ───────", C.CYAN))
        print(r"    In VS Code Terminal, run this instead of the script:")
        print(r"    C:\xampp\php\php.exe -S localhost:8080 -t C:\xampp\htdocs\module1")
        print("    Then open http://localhost:8080 in your browser manually.")
        print()
        input("  Press Enter to exit…")
        os._exit(1)   # use os._exit to avoid debugger catching SystemExit

    try:
        result = subprocess.run(
            [php, "--version"],
            capture_output=True, text=True, timeout=5
        )
        first_line = result.stdout.split("\n")[0]
        ok(f"Found: {first_line}")
    except Exception:
        ok(f"Found: {php}")

    return php


def check_serve_dir():
    section("Checking project directory")
    index = SERVE_DIR / "index.html"
    ok(f"Root  : {SERVE_DIR}")
    if index.exists():
        ok("index.html found")
    else:
        warn("index.html NOT found in that folder.")
        warn("Make sure this launcher sits inside module1/ or one level above it.")


def check_port(port):
    import socket
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(1)
        try:
            s.connect((HOST, port))
            return True
        except (ConnectionRefusedError, OSError):
            return False


# ── SERVER ────────────────────────────────────────────────────

def start_server(php_path, serve_dir, port):
    section("Starting PHP development server")

    cmd = [php_path, "-S", f"{HOST}:{port}", "-t", str(serve_dir)]
    info(f"Command : {' '.join(cmd)}")

    kwargs = {}
    if IS_WINDOWS:
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW

    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        text=True,
        **kwargs,
    )

    time.sleep(0.6)

    if proc.poll() is not None:
        err("PHP server failed to start.")
        stderr_out = proc.stderr.read()
        if stderr_out:
            print(clr(stderr_out, C.RED))
        input("\n  Press Enter to exit…")
        os._exit(1)

    ok(f"Server  : {clr(f'http://{HOST}:{port}', C.CYAN, C.BOLD)}")
    return proc


def open_browser(url, delay):
    section("Opening browser")
    info(f"Waiting {delay}s…")
    time.sleep(delay)
    webbrowser.open(url)
    ok(f"Opened {url}")


# ── MAIN ──────────────────────────────────────────────────────

def main():
    banner()

    section("Checking Python version")
    v = sys.version_info
    ok(f"Python {v.major}.{v.minor}.{v.micro}")

    php_path = check_php()
    check_serve_dir()

    section("Checking port availability")
    port = PORT
    if check_port(port):
        warn(f"Port {port} is busy. Trying {port + 1}…")
        port += 1
        if check_port(port):
            err(f"Port {port} is also busy. Free port {PORT} and retry.")
            input("\n  Press Enter to exit…")
            os._exit(1)
    ok(f"Port {port} is available")

    url = f"http://{HOST}:{port}"

    hr()
    proc = start_server(php_path, SERVE_DIR, port)
    open_browser(url, OPEN_DELAY)

    print()
    print(clr("  ╔══════════════════════════════════════════════════╗", C.GREEN, C.BOLD))
    print(clr( "  ║  ✓  IT&DS Tracker is running!                  ║", C.GREEN, C.BOLD))
    print(clr(f"  ║     {url:<44} ║", C.GREEN, C.BOLD))
    print(clr( "  ╠══════════════════════════════════════════════════╣", C.GREEN, C.BOLD))
    print(clr( "  ║  Press Ctrl+C in this terminal to stop          ║", C.GREEN))
    print(clr( "  ╚══════════════════════════════════════════════════╝", C.GREEN, C.BOLD))
    print()

    def shutdown(sig=None, frame=None):
        print()
        section("Shutting down")
        proc.terminate()
        try:
            proc.wait(timeout=3)
        except subprocess.TimeoutExpired:
            proc.kill()
        ok("Server stopped. Goodbye!")
        print()
        os._exit(0)

    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    try:
        while True:
            line = proc.stderr.readline()
            if line:
                stripped = line.strip()
                if stripped and not any(x in stripped for x in [
                    "Development Server", "::1]", "127.0.0.1]",
                    " 200 ", " 404 ", " 304 ", " 302 "
                ]):
                    print(clr(f"  [PHP] {stripped}", C.DIM))

            if proc.poll() is not None:
                err("PHP server exited unexpectedly.")
                input("\n  Press Enter to exit…")
                os._exit(1)

            time.sleep(0.05)

    except KeyboardInterrupt:
        shutdown()


if __name__ == "__main__":
    main()