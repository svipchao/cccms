from __future__ import annotations

from pydantic import Field, field_validator

from cccms.schemas.base import LocalDateTime, StrictModel, blank_to_none


class FileQuery(StrictModel):
    keyword: str | None = None
    original_name: str | None = None
    category_id: int | None = None
    ext: str | None = None
    start: str | None = None
    end: str | None = None
    trashed: int = 0
    page: int = Field(default=1, ge=1)
    limit: int = Field(default=15, ge=1, le=200)


class FileOut(StrictModel):
    id: int
    category_id: int | None = None
    category_name: str = ""
    storage: str
    url: str
    original_name: str
    ext: str
    mime: str
    size: int
    sha1: str
    create_by: int
    create_by_name: str = ""
    is_image: bool = False
    is_pdf: bool = False
    create_time: LocalDateTime
    update_time: LocalDateTime


class FileMoveRequest(StrictModel):
    ids: list[int] = Field(min_length=1)
    category_id: int | None = None

    @field_validator("category_id", mode="before")
    @classmethod
    def _category(cls, value: object) -> object:
        return blank_to_none(value)


class UploadInitRequest(StrictModel):
    filename: str = Field(min_length=1, max_length=255)
    size: int = Field(ge=1)
    sha1: str | None = Field(default=None, min_length=40, max_length=40)
    category_id: int | None = None

    @field_validator("category_id", mode="before")
    @classmethod
    def _category(cls, value: object) -> object:
        return blank_to_none(value)


class UploadInitOut(StrictModel):
    upload_id: str
    instant: bool = False
    received: list[int] = Field(default_factory=list)
    chunk_size: int = 0
    total_chunks: int = 0
    file: FileOut | None = None


class UploadCompleteRequest(StrictModel):
    upload_id: str = Field(min_length=1, max_length=64)
    sha1: str | None = Field(default=None, min_length=40, max_length=40)
