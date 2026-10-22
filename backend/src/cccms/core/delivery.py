"""把"后台开关打开了"收敛成"真的能把验证码发出去"。

三个消费方统一走这里，避免三份各写一遍"只看配置开关"的规则：
``services/auth.py``（登录页初始化）、``core/config_store.py``（公共配置）、
``services/password_reset.py``（真正发码前的校验）。

判定口径：

* email：``smtp_ready``（host 与 from 非空）才算能发；dev 没有 SMTP 也放行，
  此时验证码打到日志，便于联调。
* sms：仍无投递实现，prod 一律不可用，dev 只写日志。
"""

from __future__ import annotations

from cccms.config import get_settings


def deliverable_channels(channels: list[str]) -> list[str]:
    """过滤掉当前环境实际发不出去的渠道。"""
    settings = get_settings()
    available: list[str] = []
    for channel in channels:
        if channel == "email":
            if settings.smtp_ready or not settings.is_prod:
                available.append(channel)
        elif channel == "sms" and not settings.is_prod:
            available.append(channel)
    return available


__all__ = ["deliverable_channels"]
