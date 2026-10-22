"""Development launcher: API (reload) + scheduler in one terminal.

``uv sync`` only installs dependencies; it never starts a process.  Use this
module when you want the scheduler to follow the API during local development::

    uv run python -m cccms.dev

Production still runs the API and ``python -m cccms.scheduler`` as two
independent services (see docs/02 and docs/11).
"""

from __future__ import annotations

import argparse
import subprocess
import sys

from cccms.config import get_settings


def main() -> None:
    parser = argparse.ArgumentParser(description="Run API and scheduler together (dev only)")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8000)
    parser.add_argument("--no-reload", action="store_true", help="disable uvicorn autoreload")
    args = parser.parse_args()

    settings = get_settings()
    if settings.is_prod:
        raise SystemExit("cccms.dev 仅用于本地开发，生产请分别启动 API 与调度器")

    scheduler = subprocess.Popen([sys.executable, "-m", "cccms.scheduler"])
    command = [
        sys.executable,
        "-m",
        "uvicorn",
        "cccms.main:app",
        "--host",
        args.host,
        "--port",
        str(args.port),
    ]
    if not args.no_reload:
        command.append("--reload")

    try:
        # 命令由本进程 CLI 参数拼装, 未使用 shell=True, 无外部输入注入面。
        subprocess.run(command, check=False)  # noqa: S603
    except KeyboardInterrupt:
        pass
    finally:
        scheduler.terminate()
        try:
            scheduler.wait(timeout=10)
        except subprocess.TimeoutExpired:
            scheduler.kill()


if __name__ == "__main__":
    main()
