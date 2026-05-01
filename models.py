"""Pydantic 请求/响应模型 — 统一 API 契约"""

from pydantic import BaseModel, RootModel, field_validator, Field
from typing import Optional

from config import PLATFORMS


# ============ 认证相关 ============

class PasswordRequest(BaseModel):
    """设置密码 / 登录共用"""
    password: str = Field(..., min_length=1, description="管理密码")

    @field_validator("password")
    @classmethod
    def not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("密码不能为空")
        return v.strip()


class ChangePasswordRequest(BaseModel):
    """修改密码"""
    old_password: str = Field(..., min_length=1, description="原密码")
    new_password: str = Field(..., min_length=1, description="新密码")

    @field_validator("old_password", "new_password")
    @classmethod
    def not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("密码不能为空")
        return v.strip()


# ============ 凭证管理 ============

class CredentialSaveRequest(BaseModel):
    """保存/更新凭证"""
    platform: str = Field(..., description="平台标识")
    credential_type: str = Field(default="cookie", description="凭证类型")
    credential_data: str = Field(default="", description="凭证数据")
    note: str = Field(default="", description="备注")

    @field_validator("platform")
    @classmethod
    def valid_platform(cls, v: str) -> str:
        if v not in PLATFORMS:
            raise ValueError(f"不支持的平台: {v}")
        return v


class CredentialResponse(BaseModel):
    """凭证详情响应（含脱敏数据）"""
    platform: str
    credential_type: str
    credential_data_masked: str
    note: str
    updated_at: str


class CredentialListItem(BaseModel):
    """凭证列表项（不含凭证数据）"""
    platform: str
    credential_type: str
    note: str
    updated_at: str


# ============ 调度器 ============

class SchedulerActionRequest(BaseModel):
    """调度器操作 — action 可选（仅 interval 时只设间隔）"""
    action: str = Field(default="", description="操作: start / stop / restart")
    interval: Optional[int] = Field(default=None, ge=10, le=120, description="刷新间隔(秒)")

    @field_validator("action")
    @classmethod
    def valid_action(cls, v: str) -> str:
        if v and v not in ("start", "stop", "restart"):
            raise ValueError(f"未知操作: {v}")
        return v


# ============ 排序模式 ============

class SortModeRequest(BaseModel):
    """切换排序模式"""
    mode: str = Field(default="weight", description="排序模式: realtime / weight")

    @field_validator("mode")
    @classmethod
    def valid_mode(cls, v: str) -> str:
        if v not in ("realtime", "weight"):
            raise ValueError(f"无效的排序模式: {v}")
        return v


class SortWeightsRequest(RootModel[dict[str, int]]):
    """批量设置排序权重 — 键为平台标识，值为权重"""
    pass


# ============ 服务可见性 ============

class ServiceVisibilityRequest(BaseModel):
    """隐藏/显示子服务"""
    sub_platform: str = Field(..., min_length=1, description="子平台标识")

    @field_validator("sub_platform")
    @classmethod
    def not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("sub_platform 不能为空")
        return v.strip()


# ============ 通用响应 ============

class StatusResponse(BaseModel):
    """通用状态响应"""
    status: str
    message: Optional[str] = None


class ErrorResponse(BaseModel):
    """通用错误响应（由 HTTPException 自动生成，此处为文档用途）"""
    detail: str
