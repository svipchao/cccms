from __future__ import annotations

from collections.abc import Callable, Iterable, Iterator, Sequence
from dataclasses import dataclass
from typing import Any

from fastapi import APIRouter, Depends, Request
from fastapi.routing import APIRoute

from cccms.core.context import require_context
from cccms.core.errors import Forbidden, MethodNotAllowed, Unauthorized
from cccms.core.rbac import has_permission

Endpoint = Callable[..., Any]


@dataclass(frozen=True, slots=True)
class PermissionMeta:
    slug: str | None
    title: str
    methods: tuple[str, ...] = ()
    no_auth: bool = False
    no_login: bool = False
    group: str | None = None


def _attach(endpoint: Endpoint, meta: PermissionMeta) -> Endpoint:
    # 装饰器把元数据挂在函数对象上, Endpoint 是任意 callable, 只能动态赋值。
    setattr(endpoint, "__cccms_meta__", meta)  # noqa: B010
    return endpoint


def permission(
    slug: str,
    title: str,
    *,
    methods: Sequence[str] | None = None,
    group: str | None = None,
) -> Callable[[Endpoint], Endpoint]:
    def decorator(endpoint: Endpoint) -> Endpoint:
        return _attach(
            endpoint,
            PermissionMeta(
                slug=slug,
                title=title,
                methods=tuple(item.upper() for item in methods or ()),
                group=group,
            ),
        )

    return decorator


def no_auth(title: str) -> Callable[[Endpoint], Endpoint]:
    def decorator(endpoint: Endpoint) -> Endpoint:
        return _attach(endpoint, PermissionMeta(slug=None, title=title, no_auth=True))

    return decorator


def no_login(title: str) -> Callable[[Endpoint], Endpoint]:
    def decorator(endpoint: Endpoint) -> Endpoint:
        return _attach(endpoint, PermissionMeta(slug=None, title=title, no_login=True))

    return decorator


def _endpoint_meta(endpoint: Endpoint) -> PermissionMeta | None:
    meta = getattr(endpoint, "__cccms_meta__", None)
    if isinstance(meta, PermissionMeta):
        return meta
    owner = getattr(endpoint, "__self__", None)
    if owner is not None:
        meta = getattr(type(owner), "__cccms_meta__", None)
        if isinstance(meta, PermissionMeta):
            return meta
    return None


def permission_dependency(meta: PermissionMeta) -> Callable[..., Any]:
    async def _check(request: Request) -> None:
        if meta.no_login:
            return
        ctx = require_context()
        if ctx.user is None:
            raise Unauthorized(ctx.auth_error or "未登录")
        if meta.methods and request.method.upper() not in meta.methods:
            raise MethodNotAllowed(f"接口仅支持 {', '.join(meta.methods)}")
        if meta.slug and not has_permission(ctx.user, meta.slug):
            raise Forbidden(f"权限不足：{meta.slug}")

    return _check


class GuardedRoute(APIRoute):
    """Every route must carry an explicit permission declaration.

    Forgetting a decorator turns into a startup failure (fail-closed), instead
    of an endpoint that silently allows everyone.
    """

    def __init__(self, path: str, endpoint: Endpoint, **kwargs: Any) -> None:
        meta = _endpoint_meta(endpoint)
        if meta is None:
            raise RuntimeError(
                f"route {path} -> {getattr(endpoint, '__name__', endpoint)} has no "
                "@permission/@no_auth/@no_login declaration"
            )
        declared = sum((bool(meta.slug), meta.no_auth, meta.no_login))
        if declared > 1:
            raise RuntimeError(f"route {path} declares more than one of permission/no_auth/no_login")

        self.cccms_meta = meta
        dependencies = list(kwargs.pop("dependencies", ()) or ())
        if not meta.no_login:
            dependencies.append(Depends(permission_dependency(meta)))
        super().__init__(path, endpoint, dependencies=dependencies, **kwargs)


def make_router(
    *,
    prefix: str = "",
    tags: Sequence[str] | None = None,
    auth: bool = True,
) -> APIRouter:
    dependencies: list[Any] = []
    if auth:
        from cccms.core.auth_deps import load_user

        dependencies.append(Depends(load_user))
    return APIRouter(
        prefix=prefix,
        tags=list(tags or ()),
        route_class=GuardedRoute,
        dependencies=dependencies,
    )


def _flatten_routes(routes: Iterable[Any]) -> Iterator[Any]:
    """Newer FastAPI wraps included routers lazily; unwrap before checking."""
    for route in routes:
        original = getattr(route, "original_router", None)
        if original is not None:
            yield from _flatten_routes(getattr(original, "routes", ()))
            continue
        yield route


def assert_route_declarations(app: Any) -> None:
    bad: list[str] = []
    for route in _flatten_routes(getattr(app, "routes", ())):
        if isinstance(route, APIRoute) and not isinstance(route, GuardedRoute):
            bad.append(f"{getattr(route, 'path', '?')} uses plain APIRoute")
        if isinstance(route, GuardedRoute) and getattr(route, "cccms_meta", None) is None:
            bad.append(f"{route.path} has no permission metadata")
    if bad:
        raise RuntimeError("route declaration violations:\n" + "\n".join(bad))
