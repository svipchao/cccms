"""CCCMS backend package.

``__version__`` 是**唯一**的版本字面量：``pyproject.toml`` 通过 hatchling 的
``[tool.hatch.version]`` 从这里读取，``main.py``（OpenAPI）与
``services/upgrade.py``（在线升级状态）直接 import 它，前端 ``package.json``
由 ``tests/test_version.py`` 断言保持一致。改版本只改这一行。
"""

from __future__ import annotations

__version__ = "0.3.3"
