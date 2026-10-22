"""Idempotent menu and permission-node seeding.

Menus are global (sys_menu has no tenant_id).  Page nodes double as the route
permission; button nodes are generated from the route metadata so a fresh
install always matches the backend declarations.
"""

from __future__ import annotations

from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.models.dict import Config
from cccms.models.user import Menu

DEFAULT_CONFIGS: list[dict[str, Any]] = [
    {
        "group_key": "system",
        "name": "system.name",
        "title": "站点名称",
        "type": "input",
        "value": "CCCMS",
        "sort": 1,
    },
    {
        "group_key": "system",
        "name": "system.logo",
        "title": "站点 Logo",
        "type": "input",
        "value": "",
        "sort": 2,
    },
    {
        "group_key": "system",
        "name": "system.icp",
        "title": "ICP 备案号",
        "type": "input",
        "value": "",
        "sort": 3,
    },
    {
        "group_key": "system",
        "name": "system.copyright",
        "title": "版权信息",
        "type": "input",
        "value": "",
        "sort": 4,
    },
    {
        "group_key": "system",
        "name": "system.maintenance",
        "title": "维护模式",
        "type": "switch",
        "value": "0",
        "sort": 5,
    },
    {
        "group_key": "system",
        "name": "system.maintenance_notice",
        "title": "维护提示",
        "type": "textarea",
        "value": "系统维护中，请稍后访问",
        "sort": 6,
    },
    {
        "group_key": "security",
        "name": "security.login_captcha",
        "title": "登录验证码",
        "type": "switch",
        "value": "0",
        "sort": 10,
    },
    {
        "group_key": "security",
        "name": "security.reset_email_enabled",
        "title": "邮箱找回密码",
        "type": "switch",
        "value": "0",
        "sort": 11,
    },
    {
        "group_key": "security",
        "name": "security.reset_sms_enabled",
        "title": "短信找回密码",
        "type": "switch",
        "value": "0",
        "sort": 12,
    },
    {
        "group_key": "security",
        "name": "security.reset_code_ttl",
        "title": "验证码有效期（秒）",
        "type": "input-number",
        "value": "300",
        "sort": 13,
    },
    {
        "group_key": "security",
        "name": "security.reset_send_interval",
        "title": "验证码发送间隔（秒）",
        "type": "input-number",
        "value": "60",
        "sort": 14,
    },
    {
        "group_key": "ui",
        "name": "ui.page_size",
        "title": "默认分页大小",
        "type": "input-number",
        "value": "15",
        "sort": 20,
    },
    {
        "group_key": "ui",
        "name": "ui.theme",
        "title": "默认主题",
        "type": "select",
        "value": "light",
        "options": [{"label": "浅色", "value": "light"}, {"label": "深色", "value": "dark"}],
        "sort": 21,
    },
    {
        "group_key": "ui",
        "name": "ui.watermark",
        "title": "启用水印",
        "type": "switch",
        "value": "1",
        "sort": 22,
    },
    {
        "group_key": "mail",
        "name": "mail.host",
        "title": "SMTP 主机",
        "type": "input",
        "value": "",
        "sort": 30,
    },
    {
        "group_key": "mail",
        "name": "mail.port",
        "title": "SMTP 端口",
        "type": "input-number",
        "value": "465",
        "sort": 31,
    },
    {
        "group_key": "mail",
        "name": "mail.username",
        "title": "SMTP 用户名",
        "type": "input",
        "value": "",
        "sort": 32,
    },
    {
        "group_key": "mail",
        "name": "mail.password",
        "title": "SMTP 密码",
        "type": "password",
        "value": "",
        "sort": 33,
    },
]


async def seed_configs(session: AsyncSession) -> int:
    """Insert missing global defaults; never overwrite operator values."""
    existing = set(
        (
            await session.scalars(
                select(Config.name).where(Config.tenant_id.is_(None))
            )
        ).all()
    )
    created = 0
    for item in DEFAULT_CONFIGS:
        if item["name"] in existing:
            continue
        session.add(Config(tenant_id=None, status=1, **item))
        created += 1
    return created


MENU_TREE: list[dict[str, Any]] = [
    {
        "type": 1,
        "title": "系统管理",
        "path": "/system",
        "icon": "icon-settings-3-line",
        "sort": 10,
        "children": [
            {
                "type": 2,
                "title": "工作台",
                "path": "/dashboard",
                "component": "cccms/dashboard/index",
                "icon": "icon-home-line",
                "sort": 0,
            },
            {
                "type": 2,
                "title": "用户管理",
                "path": "/user",
                "component": "cccms/user/index",
                "icon": "icon-user-line",
                "sort": 1,
                "node": "cccms:user:index",
            },
            {
                "type": 2,
                "title": "角色管理",
                "path": "/role",
                "component": "cccms/role/index",
                "icon": "icon-shield-user-line",
                "sort": 2,
                "node": "cccms:role:index",
            },
            {
                "type": 2,
                "title": "菜单管理",
                "path": "/menu",
                "component": "cccms/menu/index",
                "icon": "icon-menu-line",
                "sort": 3,
                "node": "cccms:menu:tree",
            },
            {
                "type": 2,
                "title": "部门管理",
                "path": "/dept",
                "component": "cccms/dept/index",
                "icon": "icon-node-tree",
                "sort": 4,
                "node": "cccms:dept:tree",
            },
            {
                "type": 2,
                "title": "岗位管理",
                "path": "/post",
                "component": "cccms/post/index",
                "icon": "icon-briefcase-line",
                "sort": 5,
                "node": "cccms:post:index",
            },
            {
                "type": 2,
                "title": "字典管理",
                "path": "/dict",
                "component": "cccms/dict/index",
                "icon": "icon-book-2-line",
                "sort": 6,
                "node": "cccms:dict:index",
            },
            {
                "type": 2,
                "title": "配置管理",
                "path": "/config",
                "component": "cccms/config/index",
                "icon": "icon-settings-4-line",
                "sort": 7,
                "node": "cccms:config:index",
            },
            {
                "type": 2,
                "title": "通知公告",
                "path": "/notice",
                "component": "cccms/notice/index",
                "icon": "icon-notification-3-line",
                "sort": 8,
                "node": "cccms:notice:index",
            },
            {
                "type": 2,
                "title": "附件管理",
                "path": "/file",
                "component": "cccms/file/index",
                "icon": "icon-folder-2-line",
                "sort": 9,
                "node": "cccms:file:index",
            },
            {
                "type": 2,
                "title": "数据权限",
                "path": "/data_rule",
                "component": "cccms/data_rule/index",
                "icon": "icon-lock-2-line",
                "sort": 10,
                "node": "cccms:data_rule:index",
            },
        ],
    },
    {
        "type": 1,
        "title": "运维管理",
        "path": "/ops",
        "icon": "icon-tools-line",
        "sort": 20,
        "children": [
            {
                "type": 2,
                "title": "定时任务",
                "path": "/crontab",
                "component": "cccms/crontab/index",
                "icon": "icon-timer-line",
                "sort": 1,
                "node": "cccms:crontab:index",
            },
            {
                "type": 2,
                "title": "操作日志",
                "path": "/log",
                "component": "cccms/log/index",
                "icon": "icon-file-list-3-line",
                "sort": 2,
                "node": "cccms:log:index",
            },
            {
                "type": 2,
                "title": "在线用户",
                "path": "/online",
                "component": "cccms/online/index",
                "icon": "icon-user-voice-line",
                "sort": 3,
                "node": "cccms:online:index",
            },
            {
                "type": 2,
                "title": "代码生成",
                "path": "/generator",
                "component": "cccms/generator/index",
                "icon": "icon-magic-line",
                "sort": 4,
                "node": "cccms:generator:tables",
            },
            {
                "type": 2,
                "title": "在线升级",
                "path": "/upgrade",
                "component": "cccms/upgrade/index",
                "icon": "icon-upload-cloud-2-line",
                "sort": 5,
                "node": "cccms:upgrade:index",
            },
        ],
    },
    {
        "type": 1,
        "title": "平台管理",
        "path": "/platform",
        "icon": "icon-building-4-line",
        "sort": 30,
        "children": [
            {
                "type": 2,
                "title": "租户管理",
                "path": "/tenant",
                "component": "cccms/tenant/index",
                "icon": "icon-community-line",
                "sort": 1,
                "node": "cccms:tenant:index",
            },
        ],
    },
]


async def _upsert(session: AsyncSession, parent_id: int | None, data: dict[str, Any]) -> Menu:
    node = data.get("node")
    row: Menu | None = None
    if node:
        row = await session.scalar(
            select(Menu).where(Menu.node == node, Menu.delete_time.is_(None))
        )
    elif data.get("path"):
        # 目录没有权限节点，用「父级 + 路径」识别，避免重复 seed 产生重复目录
        statement = select(Menu).where(
            Menu.path == data["path"],
            Menu.delete_time.is_(None),
            Menu.parent_id.is_(None) if parent_id is None else Menu.parent_id == parent_id,
        )
        row = await session.scalar(statement)
    if row is None:
        # data 里可能自带 status（例如停用节点），默认值必须先铺再被覆盖。
        row = Menu(parent_id=parent_id, **{"status": 1, **data})
        session.add(row)
        await session.flush()
        return row
    row.parent_id = parent_id
    for key in ("type", "title", "path", "component", "icon", "sort", "status"):
        if key in data:
            setattr(row, key, data[key])
    await session.flush()
    return row


async def seed_menus(session: AsyncSession) -> tuple[int, int]:
    """Insert / update the menu tree and generate button nodes from routes."""
    from cccms.api import BUSINESS_ROUTERS
    from cccms.core.permissions import _flatten_routes

    page_nodes: set[str] = set()
    module_menu: dict[str, int] = {}
    dirs = 0
    menus = 0

    for group in MENU_TREE:
        parent = await _upsert(session, None, {key: value for key, value in group.items() if key != "children"})
        dirs += 1
        for child in group["children"]:
            row = await _upsert(session, parent.id, child)
            menus += 1
            if row.node:
                page_nodes.add(str(row.node))
                module = str(row.node).split(":")[1]
                module_menu.setdefault(module, row.parent_id or parent.id)

    # 按钮节点：从路由元数据生成，跳过已作为页面节点的 slug
    buttons: dict[str, str] = {}
    for router in BUSINESS_ROUTERS:
        for route in _flatten_routes(getattr(router, "routes", ())):
            meta = getattr(route, "cccms_meta", None)
            slug = getattr(meta, "slug", None)
            if not slug or slug in page_nodes:
                continue
            buttons.setdefault(str(slug), str(getattr(meta, "title", "") or slug))

    button_count = 0
    for index, (slug, title) in enumerate(sorted(buttons.items()), start=1):
        module = slug.split(":")[1] if slug.count(":") >= 2 else ""
        parent_id = module_menu.get(module)
        await _upsert(
            session,
            parent_id,
            {
                "type": 3,
                "title": title,
                "sort": 1000 + index,
                "node": slug,
                "status": 1,
            },
        )
        button_count += 1

    return dirs + menus, button_count
