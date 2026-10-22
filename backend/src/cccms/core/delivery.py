"""把"后台开关打开了"收敛成"真的能把验证码发出去"。

仓库目前没有任何投递实现：dev / test 只把验证码写进日志（`password_reset`），
prod 直接拒绝发送。所以 prod 下一律视为**没有可用渠道** —— 否则登录页会显示
"忘记密码"，用户填完表单点发送才拿到 503。

三个消费方统一走这里，避免三份各写一遍"只看配置开关"的规则：
``services/auth.py``（登录页初始化）、``core/config_store.py``（公共配置）、
``services/password_reset.py``（真正发码前的校验）。

接入 SMTP / 短信网关时，只需在 ``deliverable_channels`` 里按渠道判断能力
（例如 email 要求 `smtp_host` 非空），上面三处会自动跟着生效。
"""

from __future__ import annotations

from cccms.config import get_settings


def deliverable_channels(channels: list[str]) -> list[str]:
    """过滤掉当前环境实际发不出去的渠道。"""
    if get_settings().is_prod:
        return []
    return list(channels)


__all__ = ["deliverable_channels"]
