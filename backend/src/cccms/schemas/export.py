from __future__ import annotations

from cccms.schemas.base import LocalDateTime, OptionalLocalDateTime, StrictModel


class ExportTaskOut(StrictModel):
    id: int
    type: str
    status: int
    progress: int
    file_size: int
    row_count: int
    error: str | None = None
    expires_at: OptionalLocalDateTime = None
    create_time: LocalDateTime
    update_time: LocalDateTime
