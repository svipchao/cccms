"""Small operational CLI.

``python -m cccms.cli init-db`` applies the Alembic migration chain
(``alembic upgrade head``), ``migrate`` does the same on an existing database.
``seed`` inserts the platform tenant, the ``super_admin`` role, built-in cron
jobs and the initial administrator.
"""

from __future__ import annotations

import asyncio

import typer
from sqlalchemy import select

from cccms.config import BACKEND_DIR, get_settings
from cccms.core.security import hash_password_async
from cccms.db.session import session_scope
from cccms.models.crontab import Crontab
from cccms.models.tenant import Tenant
from cccms.models.user import Role, User, UserRole
from cccms.seeds import seed_configs, seed_menus

cli = typer.Typer(help="CCCMS backend maintenance commands", no_args_is_help=True)


def _run_migrations(revision: str = "head") -> None:
    """跑 Alembic revision；连接串来自 CCCMS_* 环境变量（见 migrations/env.py）。"""
    from alembic import command
    from alembic.config import Config

    config = Config(str(BACKEND_DIR / "alembic.ini"))
    config.set_main_option("script_location", str(BACKEND_DIR / "migrations"))
    command.upgrade(config, revision)


async def _create_schema() -> None:
    await asyncio.to_thread(_run_migrations)


async def _seed() -> None:
    settings = get_settings()
    async with session_scope() as session:
        tenant = await session.scalar(
            select(Tenant).where(Tenant.code == "platform", Tenant.delete_time.is_(None))
        )
        if tenant is None:
            tenant = Tenant(
                code="platform",
                name="平台",
                is_platform=1,
                status=1,
                max_users=0,
                remark="平台租户（租户管理入口）",
            )
            session.add(tenant)
            await session.flush()

        role = await session.scalar(
            select(Role).where(
                Role.tenant_id == tenant.id,
                Role.code == "super_admin",
                Role.delete_time.is_(None),
            )
        )
        if role is None:
            role = Role(
                tenant_id=tenant.id,
                code="super_admin",
                name="超级管理员",
                data_scope=1,
                is_system=1,
                status=1,
                sort=0,
                remark="内置角色，拥有全部权限",
            )
            session.add(role)
            await session.flush()

        user = await session.scalar(
            select(User).where(
                User.tenant_id == tenant.id,
                User.username == settings.admin_username,
                User.delete_time.is_(None),
            )
        )
        if user is None:
            user = User(
                tenant_id=tenant.id,
                username=settings.admin_username,
                password_hash=await hash_password_async(settings.admin_password),
                nickname=settings.admin_nickname,
                email=settings.admin_email or None,
                status=1,
            )
            session.add(user)
            await session.flush()

        link = await session.scalar(
            select(UserRole.id).where(UserRole.user_id == user.id, UserRole.role_id == role.id)
        )
        if link is None:
            session.add(UserRole(tenant_id=tenant.id, user_id=user.id, role_id=role.id))

        builtins = [
            ("封存审计日志链", "0 */10 * * * *", "cccms.audit.seal", {"batch": 200}),
            ("归档历史操作日志", "0 10 3 * * *", "cccms.log.archive", {"days": 90}),
            ("清理过期操作日志", "0 30 3 * * *", "cccms.log.clean", {"days": settings.log_keep_days}),
            ("清理过期任务日志", "0 50 3 * * *", "cccms.crontabLog.clean", {"days": 30}),
            ("清理过期已读回执", "0 10 4 * * *", "cccms.noticeRead.clean", {"days": 90}),
        ]
        if tenant.is_platform:
            # 导出文件与分片目录是全局资源，只由平台租户统一清理。
            builtins.extend(
                [
                    ("清理过期导出文件", "0 0 4 * * *", "cccms.export.clean", {}),
                    ("清理未完成分片", "0 15 4 * * *", "cccms.upload.cleanChunks", {"hours": 48}),
                ]
            )
        for name, cron, target, params in builtins:
            exists = await session.scalar(
                select(Crontab.id).where(
                    Crontab.tenant_id == tenant.id,
                    Crontab.name == name,
                    Crontab.delete_time.is_(None),
                )
            )
            if exists is None:
                session.add(
                    Crontab(
                        tenant_id=tenant.id,
                        name=name,
                        cron=cron,
                        target=target,
                        params=params,
                        group_name="系统",
                        status=1,
                    )
                )

        menu_count, button_count = await seed_menus(session)
        config_count = await seed_configs(session)

    typer.echo(
        f"seeded tenant={tenant.code} role={role.code} admin={settings.admin_username} "
        f"menus={menu_count} buttons={button_count} configs={config_count}"
    )


@cli.command("init-db")
def init_db() -> None:
    """Apply every migration (fresh install) via ``alembic upgrade head``."""
    asyncio.run(_create_schema())
    typer.echo("schema migrated to head")


@cli.command("migrate")
def migrate() -> None:
    """Apply pending migrations on an existing database."""
    _run_migrations()
    typer.echo("schema migrated to head")


@cli.command("seed")
def seed() -> None:
    """Insert the platform tenant, super_admin role and initial admin."""
    asyncio.run(_seed())


@cli.command("audit-verify")
def audit_verify() -> None:
    """Verify the audit hash chain and exit non-zero when it is broken."""
    import json

    from cccms.core.audit import verify_chain

    result = asyncio.run(verify_chain())
    typer.echo(json.dumps(result, ensure_ascii=False))
    if not result.get("ok"):
        raise typer.Exit(code=1)


@cli.command("check")
def check() -> None:
    """Fail if a route is missing its permission declaration."""
    from cccms.core.permissions import assert_route_declarations
    from cccms.main import app

    assert_route_declarations(app)
    typer.echo("route declarations ok")


if __name__ == "__main__":
    cli()
