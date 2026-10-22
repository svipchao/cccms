from __future__ import annotations

import re

from fastapi import Depends, Query

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.errors import ValidationFailed
from cccms.core.permissions import make_router, permission
from cccms.schemas.generator import (
    GeneratorColumnOut,
    GeneratorPreviewOut,
    GeneratorRequest,
    GeneratorResultOut,
    GeneratorTableOut,
)
from cccms.services import generator as generator_service

router = make_router(prefix="/generator", tags=["generator"])
TABLE_PATTERN = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,63}$")


@router.get("/tables", response_model=ApiResponse[list[GeneratorTableOut]])
@permission("cccms:generator:tables", "查看可生成数据表", methods=["GET"])
async def tables(user: UserContext = Depends(get_current_user)) -> dict[str, object]:
    generator_service.ensure_available()
    return ok(await generator_service.list_tables())


@router.get("/columns", response_model=ApiResponse[list[GeneratorColumnOut]])
@permission("cccms:generator:columns", "查看数据表字段", methods=["GET"])
async def columns(
    table: str = Query(..., min_length=1, max_length=64),
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    generator_service.ensure_available()
    if not TABLE_PATTERN.match(table):
        raise ValidationFailed("表名不合法")
    return ok(await generator_service.list_columns(table))


@router.post("/preview", response_model=ApiResponse[GeneratorPreviewOut])
@permission("cccms:generator:preview", "预览生成代码", methods=["POST"])
async def preview(
    payload: GeneratorRequest,
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    generator_service.ensure_available()
    return ok(await generator_service.preview(payload))


@router.post("/generate", response_model=ApiResponse[GeneratorResultOut])
@permission("cccms:generator:generate", "生成代码", methods=["POST"])
async def generate(
    payload: GeneratorRequest,
    user: UserContext = Depends(get_current_user),
) -> dict[str, object]:
    generator_service.ensure_available()
    return ok(await generator_service.generate(payload, user))
