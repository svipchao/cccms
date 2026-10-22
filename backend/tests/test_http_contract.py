"""HTTP 契约测试：不需要 MySQL / Redis。

这些断言盯着评审里最容易回退的几条不变量：
* 业务路由默认 fail-closed（没令牌就是 401，而不是 500 或 200）；
* 附件不再匿名可读；
* 错误响应的 `code` 是业务码，HTTP 状态码保持协议语义；
* `/metrics` 不在白名单内一律 404；
* 安全响应头始终存在。

注意：不进入 ``TestClient`` 的上下文管理器，跳过 lifespan，
避免测试进程去连数据库与 Redis。
"""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from cccms.core import auth_deps
from cccms.core.context import UserContext, current_context
from cccms.db.session import get_session
from cccms.main import app
from cccms.models.file import FileRecord
from cccms.services.storage import LocalStorageDriver

# /storage 契约测试用的"已登录超管"：超管绕过数据范围，断言只聚焦鉴权是否生效。
_LOGGED_IN_USER = UserContext(
    id=1,
    username="tester",
    nickname="Tester",
    tenant_id=1,
    home_tenant_id=1,
    is_super_admin=True,
    permissions=frozenset(),
    role_ids=(),
    effective_role_ids=(),
    dept_ids=(),
    dept_subtree_ids=(),
    post_ids=(),
    data_scope=1,
    jti="test",
    token_exp=0,
    token_iat=0,
)

PROTECTED_PATHS = (
    "/api/user",
    "/api/role",
    "/api/log",
    "/api/dict",
    "/api/file",
    "/api/notice",
)


@pytest.fixture(scope="module")
def client() -> TestClient:
    return TestClient(app)


def test_ping_is_public(client: TestClient) -> None:
    response = client.get("/ping")
    assert response.status_code == 200
    assert response.json()["code"] == 0


@pytest.mark.parametrize("path", PROTECTED_PATHS)
def test_business_routes_fail_closed(client: TestClient, path: str) -> None:
    response = client.get(path)
    assert response.status_code == 401
    body = response.json()
    # 业务码不再等于 HTTP 状态码
    assert body["code"] == 10101
    assert body["message"]


def test_api_v1_alias_behaves_like_api(client: TestClient) -> None:
    legacy = client.get("/api/user")
    versioned = client.get("/api/v1/user")
    assert legacy.status_code == versioned.status_code == 401
    assert legacy.json()["code"] == versioned.json()["code"] == 10101


def test_tenant_resolution_failure_fails_closed(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
) -> None:
    """域名→租户解析失败时不能退回"无租户上下文"继续处理。

    退回等于让后续查询失去 tenant_id 约束（跨租户读），所以必须 503。
    这里覆盖掉 conftest 里"解析恒为 None"的桩。
    """

    async def _boom(host: str | None) -> None:
        raise RuntimeError("redis down")

    monkeypatch.setattr("cccms.core.middleware.tenant_from_host", _boom)

    response = client.get("/api/user")
    assert response.status_code == 503
    assert response.json()["code"] == 10503
    assert response.json()["message"]


def test_probe_paths_do_not_depend_on_tenant_resolution(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
) -> None:
    """/ping 不该因为租户解析故障而变红，否则 Redis 抖动会摘掉全部实例。"""

    async def _boom(host: str | None) -> None:
        raise RuntimeError("redis down")

    monkeypatch.setattr("cccms.core.middleware.tenant_from_host", _boom)

    assert client.get("/ping").status_code == 200


def test_storage_is_not_anonymously_readable(client: TestClient) -> None:
    response = client.get("/storage/2026/01/01/anything.png")
    assert response.status_code == 401
    assert response.json()["code"] == 10101


class _FakeSession:
    """只实现附件读取用到的 ``scalar``，避免契约测试依赖 MySQL。"""

    def __init__(self, record: FileRecord | None) -> None:
        self.record = record

    async def scalar(self, statement: object) -> FileRecord | None:
        return self.record


@pytest.fixture
def fake_backend(client: TestClient, monkeypatch: pytest.MonkeyPatch, tmp_path: Path):
    """伪造"已登录 + 有一条可见附件"，供 /storage 与 /api/file/{id}/download 共用。"""
    blob = tmp_path / "anything.png"
    blob.write_bytes(b"\x89PNG")

    def _install(record: FileRecord | None) -> None:
        async def fake_load_user() -> None:
            ctx = current_context()
            assert ctx is not None
            ctx.user = _LOGGED_IN_USER

        async def fake_session():
            yield _FakeSession(record)

        def fake_blob_path(driver: object, relative: str) -> Path:
            return blob

        app.dependency_overrides[auth_deps.load_user] = fake_load_user
        app.dependency_overrides[get_session] = fake_session
        # 路径解析已经收口到驱动：打桩驱动本身即可覆盖 /storage 与 /api/file/{id}/download 两处。
        monkeypatch.setattr(LocalStorageDriver, "blob_path", fake_blob_path)

    yield _install
    app.dependency_overrides.clear()


def test_storage_is_readable_by_logged_in_owner(client: TestClient, fake_backend) -> None:
    fake_backend(FileRecord(path="2026/01/01/anything.png", url="/storage/x", original_name="a.png", mime="image/png"))
    response = client.get("/storage/2026/01/01/anything.png")
    assert response.status_code == 200
    assert response.content == b"\x89PNG"
    assert response.headers["cache-control"] == "private, max-age=300"


def test_storage_returns_not_found_for_unscoped_path(client: TestClient, fake_backend) -> None:
    # 记录不存在或不在当前用户数据权限内：404 而不是 401/200。
    fake_backend(None)
    response = client.get("/storage/2026/01/01/other.png")
    assert response.status_code == 404
    assert response.json()["code"] == 10401


def test_file_download_requires_login(client: TestClient) -> None:
    # 新增端点同样 fail-closed：没有令牌就是 401，而不是 404 或 200。
    response = client.get("/api/file/1/download")
    assert response.status_code == 401
    assert response.json()["code"] == 10101


def test_file_download_returns_attachment(client: TestClient, fake_backend) -> None:
    # storage 在未 flush 的对象上是 None（server_default 只在 INSERT 生效），显式置为 local。
    fake_backend(
        FileRecord(
            storage="local",
            path="2026/01/01/anything.png",
            url="/storage/x",
            original_name="报价单.png",
            mime="image/png",
        )
    )
    response = client.get("/api/file/1/download")
    assert response.status_code == 200
    assert response.content == b"\x89PNG"
    assert response.headers["content-type"] == "image/png"
    # 以附件形式下载，文件名取 original_name（非 ASCII 走 RFC 5987 的 filename*）。
    disposition = response.headers["content-disposition"]
    assert disposition.startswith("attachment")
    assert "filename*=utf-8''" in disposition.lower()


def test_file_download_sets_length_and_accept_ranges(client: TestClient, fake_backend) -> None:
    """改成流式后 Content-Length 要自己算：少了它会变成 chunked，下载没有进度也不可续传。"""
    fake_backend(
        FileRecord(storage="local", path="p.png", url="/storage/p.png", original_name="p.png", mime="image/png")
    )
    response = client.get("/api/file/1/download")
    assert response.status_code == 200
    assert response.headers["content-length"] == "4"
    assert response.headers["accept-ranges"] == "bytes"


def test_file_download_serves_range(client: TestClient, fake_backend) -> None:
    fake_backend(
        FileRecord(storage="local", path="p.png", url="/storage/p.png", original_name="p.png", mime="image/png")
    )
    response = client.get("/api/file/1/download", headers={"Range": "bytes=1-2"})

    assert response.status_code == 206
    assert response.content == b"PN"
    assert response.headers["content-range"] == "bytes 1-2/4"
    assert response.headers["content-length"] == "2"


def test_file_download_rejects_unsatisfiable_range(client: TestClient, fake_backend) -> None:
    fake_backend(
        FileRecord(storage="local", path="p.png", url="/storage/p.png", original_name="p.png", mime="image/png")
    )
    response = client.get("/api/file/1/download", headers={"Range": "bytes=99-"})

    assert response.status_code == 416
    # 416 必须告诉客户端总长度，否则播放器无从重试。
    assert response.headers["content-range"] == "bytes */4"


def test_file_download_hides_invisible_files(client: TestClient, fake_backend) -> None:
    # 不存在与"无权看"必须同码：否则可以用 id 探测别人的附件。
    fake_backend(None)
    response = client.get("/api/file/2/download")
    assert response.status_code == 404
    assert response.json()["code"] == 10401


def test_unknown_path_returns_business_not_found(client: TestClient) -> None:
    response = client.get("/api/definitely-not-a-route")
    assert response.status_code == 404
    assert response.json()["code"] == 10401


def test_error_message_follows_accept_language(client: TestClient) -> None:
    response = client.get("/api/definitely-not-a-route", headers={"accept-language": "en-US"})
    assert response.json()["message"] == "Not found"


def test_metrics_is_hidden_from_unlisted_ip(client: TestClient) -> None:
    response = client.get("/metrics")
    assert response.status_code == 404
    assert response.json()["code"] == 10401


def test_security_headers_are_always_present(client: TestClient) -> None:
    headers = client.get("/ping").headers
    assert headers["x-content-type-options"] == "nosniff"
    assert headers["x-frame-options"] == "SAMEORIGIN"
    assert headers["referrer-policy"] == "strict-origin-when-cross-origin"
    assert headers.get("x-trace-id")
