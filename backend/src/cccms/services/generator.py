"""Table driven code generator.

Products are written below ``src/cccms/generated/<module>/`` so a generation
run can never overwrite hand written modules.  Move the files into their final
package once reviewed; the menu snippet returned alongside tells the operator
which permission nodes to register.
"""

from __future__ import annotations

import json
import keyword
import re
from typing import Any

from sqlalchemy import inspect

from cccms.config import BACKEND_DIR, get_settings
from cccms.core.context import UserContext
from cccms.core.errors import Conflict, NotFound, ValidationFailed
from cccms.db.session import get_engine
from cccms.schemas.generator import (
    GeneratorColumnOut,
    GeneratorPreviewOut,
    GeneratorRequest,
    GeneratorResultOut,
    GeneratorTableOut,
)

GENERATED_ROOT = BACKEND_DIR / "src" / "cccms" / "generated"
SKIP_SAVE = {"id", "create_time", "update_time", "delete_time"}
TABLE_PREFIXES = ("sys_", "cccms_", "ks_")


def ensure_available() -> None:
    """Schema inspection is a development tool; keep it out of production.

    路由层与 service 层都会调用：漏一处就等于对外暴露表结构。
    """
    if get_settings().is_prod:
        raise ValidationFailed("代码生成器仅在 dev / test 环境可用")


def _class_name(table: str) -> str:
    name = table
    for prefix in TABLE_PREFIXES:
        if name.startswith(prefix):
            name = name[len(prefix) :]
            break
    parts = [part for part in re.split(r"[^A-Za-z0-9]+", name) if part]
    if not parts:
        raise ValidationFailed("无法从表名推导类名")
    class_name = "".join(part[:1].upper() + part[1:] for part in parts)
    if class_name[0].isdigit():
        class_name = f"T{class_name}"
    return class_name


def _snake(name: str) -> str:
    value = re.sub(r"(?<!^)(?=[A-Z])", "_", name).lower()
    if keyword.iskeyword(value):
        value += "_"
    return value


def _module_name(payload: GeneratorRequest, class_name: str) -> str:
    return payload.module or _snake(class_name)


def _quote(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


async def list_tables() -> list[GeneratorTableOut]:
    ensure_available()
    engine = get_engine()

    def _read(sync_connection: Any) -> list[GeneratorTableOut]:
        inspector = inspect(sync_connection)
        rows: list[GeneratorTableOut] = []
        for name in inspector.get_table_names():
            comment = ""
            # 部分 MySQL 版本/账号读不到表注释, 退化为空字符串即可。
            try:
                comment = str(inspector.get_table_comment(name).get("text") or "")
            except Exception:  # noqa: BLE001
                comment = ""
            rows.append(GeneratorTableOut(name=name, comment=comment))
        return rows

    async with engine.connect() as connection:
        return await connection.run_sync(_read)


async def list_columns(table: str) -> list[GeneratorColumnOut]:
    engine = get_engine()

    def _read(sync_connection: Any) -> list[GeneratorColumnOut]:
        inspector = inspect(sync_connection)
        if table not in inspector.get_table_names():
            raise NotFound("数据表不存在")
        primary = set(inspector.get_pk_constraint(table).get("constrained_columns") or ())
        rows: list[GeneratorColumnOut] = []
        for column in inspector.get_columns(table):
            rows.append(
                GeneratorColumnOut(
                    name=str(column["name"]),
                    data_type=str(column["type"]),
                    type=str(column["type"]),
                    nullable=bool(column.get("nullable", True)),
                    default=str(column.get("default") or ""),
                    comment=str(column.get("comment") or ""),
                    primary_key=str(column["name"]) in primary,
                    primary=str(column["name"]) in primary,
                )
            )
        return rows

    async with engine.connect() as connection:
        return await connection.run_sync(_read)


def _python_type(data_type: str) -> str:
    lowered = data_type.lower()
    if "tinyint(1)" in lowered or "bool" in lowered or "bit(1)" in lowered:
        return "bool"
    if "bigint" in lowered or "int" in lowered:
        return "int"
    if any(item in lowered for item in ("decimal", "numeric")):
        return "Decimal"
    if any(item in lowered for item in ("float", "double", "real")):
        return "float"
    if "datetime" in lowered or "timestamp" in lowered:
        return "datetime"
    if lowered.startswith("date"):
        return "date"
    if lowered.startswith("time"):
        return "time"
    if any(item in lowered for item in ("blob", "binary")):
        return "bytes"
    if "json" in lowered:
        return "Any"
    return "str"


def _sa_type(column: GeneratorColumnOut) -> str:
    lowered = column.data_type.lower()
    if "bool" in lowered or "tinyint(1)" in lowered:
        return "Boolean"
    if "bigint" in lowered:
        return "BigInteger"
    if "smallint" in lowered:
        return "SmallInteger"
    if "int" in lowered:
        return "Integer"
    if any(item in lowered for item in ("decimal", "numeric")):
        match = re.search(r"\((\d+)\s*,\s*(\d+)\)", column.data_type)
        return f"Numeric({match.group(1)}, {match.group(2)})" if match else "Numeric(12, 2)"
    if any(item in lowered for item in ("double", "float", "real")):
        return "Float"
    if "datetime" in lowered or "timestamp" in lowered:
        return "DateTime"
    if lowered.startswith("date"):
        return "Date"
    if lowered.startswith("time"):
        return "Time"
    if any(item in lowered for item in ("blob", "binary")):
        return "LargeBinary"
    if "json" in lowered:
        return "JSON"
    match = re.search(r"\((\d+)\)", column.data_type)
    if match and any(item in lowered for item in ("char", "varchar", "text")):
        return f"String({match.group(1)})" if "char" in lowered and "text" not in lowered else "Text"
    if "text" in lowered or "json" in lowered:
        return "Text"
    return "String(255)"


def _model_source(class_name: str, table: str, columns: list[GeneratorColumnOut]) -> str:
    used = sorted({"Column", *(_sa_type(column).split("(")[0] for column in columns)})
    lines = [
        "from __future__ import annotations",
        "",
        f"from sqlalchemy import {', '.join(used)}",
        "",
        "from cccms.db.base import Base",
        "",
        "",
        f"class {class_name}(Base):",
        f'    __tablename__ = "{table}"',
        '    __table_args__ = {"extend_existing": True}',
        "",
    ]
    for column in columns:
        attribute = column.name if column.name.isidentifier() and not keyword.iskeyword(column.name) else f"{column.name}_"
        args = [_sa_type(column)]
        if attribute != column.name:
            args.insert(0, _quote(column.name))
        if column.primary_key:
            args.append("primary_key=True")
        if not column.nullable and not column.primary_key:
            args.append("nullable=False")
        if column.comment:
            args.append(f"comment={_quote(column.comment)}")
        lines.append(f"    {attribute} = Column({', '.join(args)})")
    lines.append("")
    return "\n".join(lines)


def _schemas_source(class_name: str, columns: list[GeneratorColumnOut]) -> str:
    py_types = {_python_type(column.data_type) for column in columns}
    header = ["from __future__ import annotations", ""]
    if "Any" in py_types:
        header.append("from typing import Any")
    if "Decimal" in py_types:
        header.append("from decimal import Decimal")
    if py_types & {"date", "datetime", "time"}:
        header.append("from datetime import date, datetime, time")
    if len(header) > 2:
        header.append("")
    header.extend(["from cccms.schemas.base import StrictModel", "", ""])

    lines = header
    lines.append(f"class {class_name}Out(StrictModel):")
    for column in columns:
        py_type = _python_type(column.data_type)
        if column.nullable:
            lines.append(f"    {column.name}: {py_type} | None = None")
        else:
            lines.append(f"    {column.name}: {py_type}")
    if not columns:
        lines.append("    pass")
    lines.extend(["", "", f"class {class_name}Save(StrictModel):"])
    editable = [column for column in columns if column.name not in SKIP_SAVE]
    if not editable:
        lines.append("    pass")
    for column in editable:
        py_type = _python_type(column.data_type)
        if column.nullable:
            lines.append(f"    {column.name}: {py_type} | None = None")
        else:
            lines.append(f"    {column.name}: {py_type}")
    lines.append("")
    return "\n".join(lines)


def _service_source(module: str, class_name: str, columns: list[GeneratorColumnOut]) -> str:
    names = {column.name for column in columns}
    tenant_filter = f"    statement = statement.where({class_name}.tenant_id == actor.tenant_id)\n" if "tenant_id" in names else ""
    soft_delete = (
        f"    statement = statement.where({class_name}.delete_time.is_(None))\n" if "delete_time" in names else ""
    )
    soft_delete_row = (
        "    row.delete_time = now()\n" if "delete_time" in names else "    await session.delete(row)\n"
    )
    return f'''from __future__ import annotations

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.errors import NotFound
from cccms.core.time import now
from cccms.generated.{module}.model import {class_name}
from cccms.generated.{module}.schemas import {class_name}Out, {class_name}Save


def _base(actor: UserContext):
    statement = select({class_name})
{tenant_filter}{soft_delete}    return statement


async def list_items(
    session: AsyncSession, actor: UserContext, page: int = 1, limit: int = 15
) -> tuple[int, list[{class_name}Out]]:
    statement = _base(actor)
    total = int(await session.scalar(select(func.count()).select_from(statement.subquery())) or 0)
    rows = list(
        (
            await session.scalars(
                statement.order_by({class_name}.id.desc()).offset((page - 1) * limit).limit(limit)
            )
        ).all()
    )
    return total, [{class_name}Out.model_validate(row) for row in rows]


async def get_item(session: AsyncSession, actor: UserContext, item_id: int) -> {class_name}Out:
    row = await session.scalar(_base(actor).where({class_name}.id == item_id))
    if row is None:
        raise NotFound("记录不存在")
    return {class_name}Out.model_validate(row)


async def create_item(session: AsyncSession, actor: UserContext, payload: {class_name}Save) -> {class_name}Out:
    data = payload.model_dump(exclude_unset=True)
{"    data['tenant_id'] = actor.tenant_id" if "tenant_id" in names else ""}
    row = {class_name}(**data)
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return {class_name}Out.model_validate(row)


async def update_item(
    session: AsyncSession, actor: UserContext, item_id: int, payload: {class_name}Save
) -> {class_name}Out:
    row = await session.scalar(_base(actor).where({class_name}.id == item_id))
    if row is None:
        raise NotFound("记录不存在")
    for key, value in payload.model_dump(exclude_unset=True).items():
        setattr(row, key, value)
{"    row.update_time = now()" if "update_time" in names else ""}
    await session.commit()
    return {class_name}Out.model_validate(row)


async def delete_item(session: AsyncSession, actor: UserContext, item_id: int) -> None:
    row = await session.scalar(_base(actor).where({class_name}.id == item_id))
    if row is None:
        raise NotFound("记录不存在")
{soft_delete_row}    await session.commit()
'''


def _router_source(module: str, class_name: str) -> str:
    return f'''from __future__ import annotations

from fastapi import Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageResult, page_result
from cccms.core.auth_deps import get_current_user
from cccms.core.permissions import make_router, permission
from cccms.db.session import get_session
from cccms.generated.{module} import service
from cccms.generated.{module}.schemas import {class_name}Out, {class_name}Save

router = make_router(prefix="/{module}", tags=["{module}"])


@router.get("", response_model=ApiResponse[PageResult[{class_name}Out]])
@permission("cccms:{module}:index", "查看{class_name}列表", methods=["GET"])
async def index(
    page: int = Query(1, ge=1),
    limit: int = Query(15, ge=1, le=100),
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await service.list_items(session, user, page, limit)
    return ok(page_result(total, rows))


@router.get("/read", response_model=ApiResponse[{class_name}Out])
@permission("cccms:{module}:read", "查看{class_name}详情", methods=["GET"])
async def read(
    id: int = Query(..., ge=1),
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await service.get_item(session, user, id))


@router.post("/save", response_model=ApiResponse[{class_name}Out])
@permission("cccms:{module}:save", "新增{class_name}", methods=["POST"])
async def save(
    payload: {class_name}Save,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await service.create_item(session, user, payload))


@router.post("/update", response_model=ApiResponse[{class_name}Out])
@permission("cccms:{module}:update", "修改{class_name}", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    item_id = int(payload.pop("id"))
    data = {class_name}Save.model_validate(payload)
    return ok(await service.update_item(session, user, item_id, data))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:{module}:delete", "删除{class_name}", methods=["POST"])
async def delete(
    id: int = Query(..., ge=1),
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await service.delete_item(session, user, id)
    return ok(None)
'''


def _menu_snippet(module: str, class_name: str) -> str:
    return (
        f"菜单: {class_name}管理\n"
        f"组件: {module}\n"
        "权限节点:\n"
        f"  cccms:{module}:index\n"
        f"  cccms:{module}:read\n"
        f"  cccms:{module}:save\n"
        f"  cccms:{module}:update\n"
        f"  cccms:{module}:delete\n"
        "将以上节点登记到 sys_menu（type=2）后授予角色即可。"
    )


async def preview(payload: GeneratorRequest) -> GeneratorPreviewOut:
    columns = await list_columns(payload.table)
    class_name = _class_name(payload.table)
    module = _module_name(payload, class_name)
    files = _render(module, class_name, payload.table, columns)
    return GeneratorPreviewOut(module=module, files=files, menu_snippet=_menu_snippet(module, class_name))


def _render(
    module: str,
    class_name: str,
    table: str,
    columns: list[GeneratorColumnOut],
) -> dict[str, str]:
    return {
        f"cccms/generated/{module}/__init__.py": '"""Generated package."""\n',
        f"cccms/generated/{module}/model.py": _model_source(class_name, table, columns),
        f"cccms/generated/{module}/schemas.py": _schemas_source(class_name, columns),
        f"cccms/generated/{module}/service.py": _service_source(module, class_name, columns),
        f"cccms/generated/{module}/router.py": _router_source(module, class_name),
    }


async def generate(payload: GeneratorRequest, actor: UserContext) -> GeneratorResultOut:
    columns = await list_columns(payload.table)
    class_name = _class_name(payload.table)
    module = _module_name(payload, class_name)
    if not module.isidentifier():
        raise ValidationFailed("模块名不合法")
    target_dir = GENERATED_ROOT / module
    rendered = _render(module, class_name, payload.table, columns)

    conflicts = [name for name in rendered if (BACKEND_DIR / "src" / name).exists()]
    if conflicts and not payload.overwrite:
        raise Conflict(f"文件已存在：{', '.join(conflicts)}；如需覆盖请勾选 overwrite")

    written: list[str] = []
    skipped: list[str] = []
    target_dir.mkdir(parents=True, exist_ok=True)
    root_init = GENERATED_ROOT / "__init__.py"
    if not root_init.exists():
        root_init.write_text('"""Generated modules (review before importing)."""\n', encoding="utf-8")
    for relative, content in rendered.items():
        path = BACKEND_DIR / "src" / relative
        if path.exists() and not payload.overwrite:
            skipped.append(relative)
            continue
        path.write_text(content, encoding="utf-8")
        written.append(relative)
    return GeneratorResultOut(
        module=module,
        written=written,
        skipped=skipped,
        menu_snippet=_menu_snippet(module, class_name),
    )


async def render_to_temp(payload: GeneratorRequest) -> dict[str, str]:
    """Used by tests to render without touching the filesystem."""
    columns = await list_columns(payload.table)
    class_name = _class_name(payload.table)
    return _render(_module_name(payload, class_name), class_name, payload.table, columns)
