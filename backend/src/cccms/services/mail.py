"""SMTP 投递能力。

目前只有一个消费方：``services/password_reset.py`` 的验证码邮件。刻意不引入
``aiosmtplib``：标准库 ``smtplib`` 放进 ``asyncio.to_thread`` 已经够用，
连接/读写超时统一由 ``smtp_timeout`` 控制，也不必新增一条运行时依赖。

调用方约定：投递失败一律抛 ``ServiceUnavailable``，且**不要在失败后仍把验证码
写进 Redis** —— 否则用户拿到的是一个永远收不到的码，还被冷却期锁住。
"""

from __future__ import annotations

import asyncio
import logging
import smtplib
import ssl
from email.message import EmailMessage
from email.utils import formataddr, formatdate, make_msgid

from cccms.config import Settings, get_settings
from cccms.core.errors import ServiceUnavailable
from cccms.core.redact import mask_email

logger = logging.getLogger("cccms.mail")


def build_message(
    *, to: str, subject: str, text: str, settings: Settings | None = None
) -> EmailMessage:
    """构造一封纯文本邮件；单独抽出来便于在测试里断言头部与正文。"""
    cfg = settings or get_settings()
    sender = cfg.smtp_from
    message = EmailMessage()
    message["Subject"] = subject
    message["From"] = formataddr((cfg.smtp_from_name, sender)) if cfg.smtp_from_name else sender
    message["To"] = to
    message["Date"] = formatdate(localtime=True)
    message["Message-ID"] = make_msgid(domain=sender.rpartition("@")[2] or None)
    message.set_content(text)
    return message


def _deliver(message: EmailMessage, cfg: Settings) -> None:
    """阻塞式投递，由 ``asyncio.to_thread`` 调起。"""
    context = ssl.create_default_context()
    client: smtplib.SMTP
    if cfg.smtp_security == "ssl":
        client = smtplib.SMTP_SSL(
            cfg.smtp_host, cfg.smtp_port, timeout=cfg.smtp_timeout, context=context
        )
    else:
        client = smtplib.SMTP(cfg.smtp_host, cfg.smtp_port, timeout=cfg.smtp_timeout)
    with client:
        if cfg.smtp_security == "starttls":
            client.ehlo()
            client.starttls(context=context)
            client.ehlo()
        if cfg.smtp_user:
            client.login(cfg.smtp_user, cfg.smtp_password)
        client.send_message(message)


async def send_mail(*, to: str, subject: str, text: str, settings: Settings | None = None) -> None:
    """投递一封纯文本邮件；发不出去就抛 ``ServiceUnavailable``。

    ``CCCMS_SMTP_SECURITY=none`` 时凭据会明文过网，仅适用于内网中继。
    """
    cfg = settings or get_settings()
    if not cfg.smtp_ready:
        raise ServiceUnavailable("未配置 SMTP，无法发送邮件", message_key=None)
    message = build_message(to=to, subject=subject, text=text, settings=cfg)
    try:
        await asyncio.to_thread(_deliver, message, cfg)
    except (OSError, smtplib.SMTPException) as exc:
        # 收件人脱敏后再进日志：邮件地址属个人信息。
        logger.warning("SMTP 投递失败 recipient=%s", mask_email(to), exc_info=True)
        raise ServiceUnavailable("邮件发送失败，请稍后再试", message_key=None) from exc


__all__ = ["build_message", "send_mail"]
