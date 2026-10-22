from __future__ import annotations

from pydantic import EmailStr, Field

from cccms.schemas.base import OptionalLocalDateTime, StrictModel


class LoginRequest(StrictModel):
    tenant_code: str | None = None
    username: str = Field(min_length=1, max_length=64)
    password: str = Field(min_length=1, max_length=128)
    captcha_id: str | None = None
    captcha: str | None = None


class TokenOut(StrictModel):
    token: str
    expires_in: int
    expires_at: int


class RoleBrief(StrictModel):
    id: int
    code: str
    name: str


class UserProfile(StrictModel):
    id: int
    username: str
    nickname: str
    avatar: str | None = None
    email: str | None = None
    phone: str | None = None
    status: int = 1
    remark: str | None = None
    super_admin: bool = False
    roles: list[RoleBrief] = Field(default_factory=list)
    depts: list[str] = Field(default_factory=list)
    permissions: list[str] = Field(default_factory=list)
    tenant_id: int
    home_tenant_id: int = 0
    tenant_code: str
    tenant_name: str
    tenant_switched: bool = False
    last_login_at: OptionalLocalDateTime = None
    login_time: OptionalLocalDateTime = None
    login_ip: str = ""
    create_time: OptionalLocalDateTime = None


class LoginOut(StrictModel):
    token: TokenOut
    user: UserProfile


class TenantOption(StrictModel):
    id: int
    code: str
    name: str


class LoginContextOut(StrictModel):
    tenant_locked: bool
    tenant: TenantOption | None = None
    tenants: list[TenantOption] = Field(default_factory=list)
    captcha_enabled: bool = False
    maintenance: bool = False
    maintenance_notice: str = ""
    reset_channels: list[str] = Field(default_factory=list)
    locale: str = "zh-CN"
    locales: list[str] = Field(default_factory=lambda: ["zh-CN", "en-US"])


class SwitchTenantRequest(StrictModel):
    tenant_id: int


class ResetPasswordRequest(StrictModel):
    tenant_code: str | None = Field(default=None, max_length=64)
    username: str = Field(min_length=1, max_length=64)
    password: str = Field(min_length=1, max_length=128)
    captcha_id: str | None = None
    captcha: str | None = None
    code: str | None = Field(default=None, max_length=16)


class SendResetCodeRequest(StrictModel):
    tenant_code: str | None = Field(default=None, max_length=64)
    username: str = Field(min_length=1, max_length=64)
    channel: str | None = Field(default=None, max_length=16)
    captcha_id: str | None = None
    captcha: str | None = None


class PasswordChangeRequest(StrictModel):
    old_password: str
    new_password: str
    # 前端只提交两次新密码中的一个值，缺省视为一致
    confirm_password: str | None = None


class ProfileUpdateRequest(StrictModel):
    nickname: str | None = Field(default=None, max_length=64)
    avatar: str | None = Field(default=None, max_length=1024)
    email: EmailStr | None = None
    phone: str | None = Field(default=None, max_length=32)


class SessionRevokeRequest(StrictModel):
    jti: str = Field(min_length=1, max_length=64)
