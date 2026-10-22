from __future__ import annotations

from typing import Annotated

from fastapi import Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from cccms.core.auth_deps import get_current_user
from cccms.core.context import UserContext
from cccms.core.envelope import ApiResponse, ok
from cccms.core.pagination import PageResult, page_result
from cccms.core.params import as_int
from cccms.core.permissions import make_router, permission
from cccms.db.base import Base
from cccms.db.session import get_session
from cccms.schemas.base import IdRequest
from cccms.schemas.rule import (
    DataRuleOptionsOut,
    DataRuleOut,
    DataRuleQuery,
    DataRuleSave,
    DataRuleUserItem,
    ScopeTableOut,
    ScopeTableQuery,
    ScopeTableSave,
)
from cccms.services import rule as rule_service

router = make_router(prefix="/data_rule", tags=["data_rule"])


@router.get("", response_model=ApiResponse[PageResult[DataRuleOut]])
@permission("cccms:data_rule:index", "数据规则列表")
async def index(
    params: Annotated[DataRuleQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await rule_service.list_rules(session, user, params)
    return ok(page_result(total, rows))


@router.get("/options", response_model=ApiResponse[DataRuleOptionsOut])
@permission("cccms:data_rule:options", "数据规则候选")
async def options(
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await rule_service.options(session, user))


@router.get("/users", response_model=ApiResponse[list[DataRuleUserItem]])
@permission("cccms:data_rule:users", "用户搜索")
async def users(
    keyword: str | None = None,
    ids: str | None = None,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    wanted = [int(item) for item in ids.split(",") if item.strip().isdigit()] if ids else None
    return ok(await rule_service.search_users(session, user, keyword, wanted))


@router.post("/save", response_model=ApiResponse[DataRuleOut])
@permission("cccms:data_rule:save", "新增数据规则", methods=["POST"])
async def save(
    payload: DataRuleSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await rule_service.create_rule(session, user, payload))


@router.post("/update", response_model=ApiResponse[DataRuleOut])
@permission("cccms:data_rule:update", "修改数据规则", methods=["POST"])
async def update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    rule_id = as_int(payload.pop("id"))
    return ok(await rule_service.update_rule(session, user, rule_id, DataRuleSave.model_validate(payload)))


@router.post("/delete", response_model=ApiResponse[None])
@permission("cccms:data_rule:delete", "删除数据规则", methods=["POST"])
async def delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    await rule_service.delete_rule(session, user, payload.id)
    return ok(None)


@router.get("/table", response_model=ApiResponse[dict[str, object]])
@permission("cccms:data_rule:table_index", "受控表列表")
async def table_index(
    params: Annotated[ScopeTableQuery, Query()],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    total, rows = await rule_service.list_tables(session, user, params)
    registered = {row.table_name for row in rows}
    available = [
        {"table": name, "full": name, "label": str(table.comment or "")}
        for name, table in Base.metadata.tables.items()
        if name not in registered
    ]
    return ok({"total": total, "list": rows, "available": available})


@router.post("/table/save", response_model=ApiResponse[ScopeTableOut])
@permission("cccms:data_rule:table_save", "新增受控表", methods=["POST"])
async def table_save(
    payload: ScopeTableSave,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    return ok(await rule_service.create_table(session, user, payload))


@router.post("/table/update", response_model=ApiResponse[ScopeTableOut])
@permission("cccms:data_rule:table_update", "修改受控表", methods=["POST"])
async def table_update(
    payload: dict[str, object],
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    table_id = as_int(payload.pop("id"))
    return ok(await rule_service.update_table(session, user, table_id, ScopeTableSave.model_validate(payload)))


@router.post("/table/delete", response_model=ApiResponse[dict[str, int]])
@permission("cccms:data_rule:table_delete", "删除受控表", methods=["POST"])
async def table_delete(
    payload: IdRequest,
    user: UserContext = Depends(get_current_user),
    session: AsyncSession = Depends(get_session),
) -> dict[str, object]:
    _, rule_count = await rule_service.delete_table(session, user, payload.id)
    return ok({"rules": rule_count, "rule_count": rule_count})
