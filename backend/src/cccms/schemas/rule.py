from __future__ import annotations

from pydantic import Field, model_validator

from cccms.schemas.base import LocalDateTime, StrictModel


class DataRuleOut(StrictModel):
    id: int
    name: str
    user_id: int | None = None
    post_id: int | None = None
    role_id: int | None = None
    dept_ids: list[int] = Field(default_factory=list)
    bind_mode: str = "or"
    table_name: str | None = None
    action: str = "row"
    field: str | None = None
    operator: str | None = None
    value: str | None = None
    value_type: str = "static"
    status: int = 1
    user_name: str = ""
    post_name: str = ""
    role_name: str = ""
    dept_names: list[str] = Field(default_factory=list)
    bind_mode_label: str = ""
    table_label: str = ""
    conflicts: list[dict[str, str]] = Field(default_factory=list)
    create_time: LocalDateTime
    update_time: LocalDateTime


class DataRuleSave(StrictModel):
    name: str = Field(min_length=1, max_length=128)
    user_id: int | None = None
    post_id: int | None = None
    role_id: int | None = None
    dept_ids: list[int] = Field(default_factory=list)
    bind_mode: str = Field(default="or", pattern=r"^(and|or)$")
    table_name: str | None = Field(default=None, max_length=64)
    action: str = Field(default="row", pattern=r"^(row|hidden|readonly|mask|encrypt)$")
    field: str | None = Field(default=None, max_length=64)
    operator: str | None = Field(default=None, max_length=16)
    value: str | None = None
    value_type: str = Field(default="static", pattern=r"^(static|dynamic)$")
    status: int = Field(default=1, ge=0, le=1)


class ScopeTableOut(StrictModel):
    id: int
    table_name: str
    title: str
    # label 与 title 同值，兼容前端只提交 label 的旧表单
    label: str = ""
    status: int = 1
    remark: str | None = None
    rule_count: int = 0
    field_count: int = 0
    # 库中是否仍存在该表；表被改名或删除时规则会暂停生效
    exists: bool = True
    create_time: LocalDateTime
    update_time: LocalDateTime


class ScopeTableSave(StrictModel):
    # 更新时前端只提交 id/label/status/remark，表名保持不变
    table_name: str = Field(default="", max_length=64, pattern=r"^([A-Za-z_][A-Za-z0-9_]*)?$")
    title: str = Field(default="", max_length=128)
    label: str | None = Field(default=None, max_length=128)
    status: int = Field(default=1, ge=0, le=1)
    remark: str | None = Field(default=None, max_length=500)

    @model_validator(mode="after")
    def _title_from_label(self) -> ScopeTableSave:
        if not self.title and self.label:
            self.title = self.label
        if not self.title:
            raise ValueError("受控表名称不能为空")
        return self


class DataRuleOptionItem(StrictModel):
    id: int
    name: str
    extra: str = ""


class DataRuleFieldOption(StrictModel):
    field: str
    label: str = ""
    type: str = ""


class DataRuleTableOption(StrictModel):
    table: str
    full: str
    label: str = ""
    fields: list[DataRuleFieldOption] = Field(default_factory=list)


class DataRuleOptionsOut(StrictModel):
    users: list[DataRuleOptionItem] = Field(default_factory=list)
    posts: list[DataRuleOptionItem] = Field(default_factory=list)
    roles: list[DataRuleOptionItem] = Field(default_factory=list)
    depts: list[DataRuleOptionItem] = Field(default_factory=list)
    tables: list[DataRuleTableOption] = Field(default_factory=list)
    operators: list[str] = Field(default_factory=list)
    actions: list[str] = Field(default_factory=list)


class DataRuleQuery(StrictModel):
    keyword: str | None = None
    name: str | None = None
    table_name: str | None = None
    field: str | None = None
    action: str | None = None
    status: int | None = None
    trashed: int = 0
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)


class ScopeTableQuery(StrictModel):
    keyword: str | None = None
    status: int | None = None
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=50, ge=1, le=200)


class DataRuleUserItem(StrictModel):
    id: int
    username: str
    nickname: str
    dept_names: list[str] = Field(default_factory=list)


__all__ = [
    "DataRuleFieldOption",
    "DataRuleOptionItem",
    "DataRuleOptionsOut",
    "DataRuleOut",
    "DataRuleQuery",
    "DataRuleSave",
    "DataRuleTableOption",
    "DataRuleUserItem",
    "ScopeTableOut",
    "ScopeTableQuery",
    "ScopeTableSave",
]
