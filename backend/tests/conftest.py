"""测试进程不读工作区里的 ``.env`` / ``.dev-secrets.env``。

单元测试断言的是代码行为，不是某台机器的配置。此前 ``Settings`` 会连带读
``backend/.env``，于是开发者本地 ``CCCMS_DEBUG=true`` 时
``test_prod_hardening_rejects_default_credentials`` 必然失败、CI 上却通过。
这里在收集测试之前清空 ``env_file``：显式传参仍然优先级最高，测试结论只取决于测试本身。

需要读取真实配置的集成测试应显式构造 ``Settings(_env_file=...)``，而不是依赖本文件。
"""

from __future__ import annotations

from cccms.config import Settings

Settings.model_config["env_file"] = ()
