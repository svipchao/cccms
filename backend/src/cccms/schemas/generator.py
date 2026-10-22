from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import StrictModel

IDENTIFIER = r"^[A-Za-z_][A-Za-z0-9_]{0,63}$"


class GeneratorTableOut(StrictModel):
    name: str
    comment: str = ""


class GeneratorColumnOut(StrictModel):
    name: str
    data_type: str
    type: str = ""
    nullable: bool = True
    default: str = ""
    comment: str = ""
    primary_key: bool = False
    primary: bool = False


class GeneratorRequest(StrictModel):
    table: str = Field(min_length=1, max_length=64)
    module: str = Field(default="", max_length=40)
    overwrite: bool = False

    @field_validator("table")
    @classmethod
    def _table(cls, value: str) -> str:
        import re

        if not re.match(IDENTIFIER, value):
            raise ValueError("表名不合法")
        return value

    @field_validator("module")
    @classmethod
    def _module(cls, value: str) -> str:
        import re

        if value and not re.match(IDENTIFIER, value):
            raise ValueError("模块名不合法")
        return value


class GeneratorPreviewOut(StrictModel):
    module: str
    files: dict[str, str] = Field(default_factory=dict)
    menu_snippet: str = ""


class GeneratorResultOut(StrictModel):
    module: str
    written: list[str] = Field(default_factory=list)
    skipped: list[str] = Field(default_factory=list)
    menu_snippet: str = ""
