"""版本号只允许一个字面量。

``src/cccms/__init__.py`` 是唯一来源，``pyproject.toml`` 通过 hatchling 动态读取，
OpenAPI 与在线升级状态直接 import。前端 ``package.json`` 是另一套生态，用断言兜住。
"""

from __future__ import annotations

import json
from pathlib import Path

from cccms import __version__
from cccms.main import app

BACKEND_DIR = Path(__file__).resolve().parents[1]
FRONTEND_PACKAGE = BACKEND_DIR.parent / "frontend" / "package.json"


def test_openapi_version_comes_from_package() -> None:
    assert app.version == __version__


def test_frontend_version_matches_backend() -> None:
    package = json.loads(FRONTEND_PACKAGE.read_text(encoding="utf-8"))
    assert package["version"] == __version__


def test_pyproject_reads_version_dynamically() -> None:
    pyproject = (BACKEND_DIR / "pyproject.toml").read_text(encoding="utf-8")
    assert 'dynamic = ["version"]' in pyproject
    # 不允许再出现静态版本行（否则又多一处要同步）。
    assert '\nversion = "' not in pyproject
