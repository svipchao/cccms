from __future__ import annotations

from datetime import datetime

from sqlalchemy import BigInteger, Computed, DateTime, ForeignKey, MetaData, SmallInteger, func
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column

NAMING_CONVENTION = {
    "ix": "ix_%(column_0_label)s",
    "uq": "uq_%(table_name)s_%(column_0_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}


class Base(DeclarativeBase):
    metadata = MetaData(naming_convention=NAMING_CONVENTION)


class PKMixin:
    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)


class TimestampMixin:
    create_time: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, server_default=func.now(), index=True
    )
    update_time: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, server_default=func.now(), onupdate=func.now()
    )


class SoftDeleteMixin:
    delete_time: Mapped[datetime | None] = mapped_column(DateTime, nullable=True, index=True)

    @property
    def is_deleted(self) -> bool:
        return self.delete_time is not None


class ActiveFlagMixin:
    """Generated NULL/1 flag used for partial unique indexes on MySQL.

    MySQL has no partial unique indexes.  A generated column that is ``1`` for
    live rows and ``NULL`` for deleted rows, included in the unique key, gives
    exactly the semantics we need: many deleted duplicates, one live row.
    """

    active_flag: Mapped[int | None] = mapped_column(
        SmallInteger,
        Computed("(case when delete_time is null then 1 else null end)", persisted=True),
        nullable=True,
    )


class TenantMixin:
    tenant_id: Mapped[int] = mapped_column(
        BigInteger,
        ForeignKey("sys_tenant.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )


class BaseModel(Base, PKMixin):
    __abstract__ = True

    # ``None`` means the model is not subject to data scope at all.  Business
    # models must set this explicitly; the scope checker fails otherwise.
    __data_scope__: object | None = None
