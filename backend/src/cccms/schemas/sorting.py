from __future__ import annotations

from typing import Literal

from cccms.schemas.base import StrictModel


class SortQuery(StrictModel):
    sort: str | None = None
    order: Literal["asc", "desc"] = "asc"
