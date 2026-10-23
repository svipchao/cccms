<template>
  <div class="art-scroll">
    <el-row :gutter="16">
      <!-- 账号信息（只读） -->
      <el-col :xs="24" :md="10">
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">{{ t('profile.accountInfo') }}</span>
          </template>

          <div class="profile-identity">
            <el-avatar :size="56" :src="detail.avatar || undefined">
              {{ avatarText }}
            </el-avatar>
            <div class="profile-identity-text">
              <div class="profile-name">{{ detail.nickname || detail.username || '—' }}</div>
              <div class="profile-account">@{{ detail.username || '—' }}</div>
            </div>
          </div>

          <el-descriptions :column="1" border size="small" class="profile-desc">
            <el-descriptions-item :label="t('profile.role')">
              {{ detail.roles?.length ? detail.roles.join('、') : '—' }}
            </el-descriptions-item>
            <el-descriptions-item :label="t('profile.dept')">
              {{ detail.depts?.length ? detail.depts.join('、') : '—' }}
            </el-descriptions-item>
            <el-descriptions-item :label="t('profile.status')">
              <el-tag :type="detail.status === 1 ? 'success' : 'info'" size="small" effect="light">
                {{ detail.status === 1 ? t('profile.enabled') : t('profile.disabled') }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item :label="t('profile.lastLogin')">{{ detail.login_time || '—' }}</el-descriptions-item>
            <el-descriptions-item :label="t('profile.loginIp')">{{ detail.login_ip || '—' }}</el-descriptions-item>
            <el-descriptions-item :label="t('profile.createTime')">{{
              detail.create_time || '—'
            }}</el-descriptions-item>
          </el-descriptions>
        </el-card>

        <!-- 登录设备（自助查看 / 注销自己的会话） -->
        <el-card shadow="never" class="profile-card">
          <template #header>
            <div class="profile-card-head">
              <span class="profile-card-title">{{ t('profile.devices') }}</span>
              <el-button link type="primary" :loading="sessionsLoading" @click="loadSessions">
                {{ t('common.refresh') }}
              </el-button>
            </div>
          </template>

          <el-table v-loading="sessionsLoading" :data="sessions" row-key="jti" size="small">
            <el-table-column :label="t('profile.device')" width="150">
              <template #default="{ row }">
                <el-space>
                  {{ row.device || '—' }}
                  <el-tag v-if="row.current" type="success" size="small" effect="light">
                    {{ t('profile.currentDevice') }}
                  </el-tag>
                </el-space>
              </template>
            </el-table-column>
            <el-table-column prop="os" :label="t('profile.os')" width="120" />
            <el-table-column prop="browser" :label="t('profile.browser')" width="110" />
            <el-table-column prop="ip" label="IP" width="150" />
            <el-table-column prop="login_at" :label="t('profile.loginTime')" width="170" />
            <el-table-column prop="last_at" :label="t('profile.lastActive')" width="170" />
            <el-table-column :label="t('table.action')" width="100">
              <template #default="{ row }">
                <el-button link type="danger" :disabled="row.current" @click="onRevoke(row)">
                  {{ t('profile.revoke') }}
                </el-button>
              </template>
            </el-table-column>
            <template #empty>{{ t('profile.noOtherDevices') }}</template>
          </el-table>

          <div class="profile-tip profile-session-tip">
            {{ t('profile.revokeTip') }}
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="14">
        <!-- 基本资料 -->
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">{{ t('profile.basicInfo') }}</span>
          </template>

          <el-form ref="infoRef" :model="infoForm" :rules="infoRules" label-width="80px">
            <el-form-item :label="t('profile.nickname')" prop="nickname">
              <el-input
                v-model="infoForm.nickname"
                maxlength="64"
                show-word-limit
                :placeholder="t('profile.nicknamePlaceholder')"
              />
            </el-form-item>
            <el-form-item :label="t('profile.email')" prop="email">
              <el-input v-model="infoForm.email" :placeholder="t('profile.optional')" />
            </el-form-item>
            <el-form-item :label="t('profile.phone')" prop="phone">
              <el-input v-model="infoForm.phone" :placeholder="t('profile.optional')" />
            </el-form-item>
            <el-form-item :label="t('profile.avatar')" prop="avatar">
              <el-input v-model="infoForm.avatar" :placeholder="t('profile.avatarPlaceholder')" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="onSaveInfo">{{ t('common.save') }}</el-button>
              <el-button @click="load">{{ t('common.reset') }}</el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 修改密码 -->
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">{{ t('profile.changePassword') }}</span>
          </template>

          <el-form ref="pwdRef" :model="pwdForm" :rules="pwdRules" label-width="80px">
            <el-form-item :label="t('profile.oldPassword')" prop="old_password">
              <el-input v-model="pwdForm.old_password" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item :label="t('profile.newPassword')" prop="new_password">
              <el-input v-model="pwdForm.new_password" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item :label="t('profile.confirmPassword')" prop="confirm">
              <el-input v-model="pwdForm.confirm" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="changing" @click="onChangePassword">
                {{ t('profile.changePassword') }}
              </el-button>
              <span class="profile-tip">{{ t('profile.passwordRuleTip') }}</span>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
// 组件名与静态路由名保持一致，keep-alive 的 include 才能匹配上
defineOptions({ name: 'profile' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  profileChangePassword,
  profileRead,
  profileRevokeSession,
  profileSessions,
  profileUpdate,
  type MySession,
  type ProfileDetail,
} from '@/api/profile'
import { useUserStore } from '@/stores/user'
import { passwordValidator } from '@/utils/password'

const { t } = useI18n({ useScope: 'global' })

const userStore = useUserStore()

/** 只读信息 */
const detail = ref<ProfileDetail>({} as ProfileDetail)
const avatarText = computed(() => (detail.value.nickname || detail.value.username || 'U').charAt(0).toUpperCase())

/* ---- 基本资料 ---- */
const infoRef = ref<FormInstance>()
const saving = ref(false)
const infoForm = reactive({ nickname: '', email: '', phone: '', avatar: '' })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const infoRules = computed<FormRules>(() => ({
  nickname: [{ required: true, message: t('profile.nicknameRequired'), trigger: 'blur' }],
  email: [{ type: 'email', message: t('profile.emailInvalid'), trigger: 'blur' }],
}))

async function load(): Promise<void> {
  const data = await profileRead()
  detail.value = data
  infoForm.nickname = data.nickname || ''
  infoForm.email = data.email || ''
  infoForm.phone = data.phone || ''
  infoForm.avatar = data.avatar || ''
}

async function onSaveInfo(): Promise<void> {
  const valid = await infoRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  saving.value = true
  try {
    await profileUpdate({ ...infoForm })
    ElMessage.success(t('profile.saveSuccess'))
    await load()
    // 顶栏的昵称 / 头像要跟着变
    await userStore.fetchProfile()
  } finally {
    saving.value = false
  }
}

/* ---- 修改密码 ---- */
const pwdRef = ref<FormInstance>()
const changing = ref(false)
const pwdForm = reactive({ old_password: '', new_password: '', confirm: '' })

const pwdRules = computed<FormRules>(() => ({
  old_password: [{ required: true, message: t('profile.oldPasswordRequired'), trigger: 'blur' }],
  new_password: [
    { required: true, message: t('profile.newPasswordRequired'), trigger: 'blur' },
    {
      validator: passwordValidator(() => ({
        username: userStore.profile?.username,
        nickname: userStore.profile?.nickname,
      })),
      trigger: 'blur',
    },
  ],
  confirm: [
    { required: true, message: t('profile.confirmPasswordRequired'), trigger: 'blur' },
    {
      validator: (_rule, value, callback) => {
        if (value !== pwdForm.new_password) {
          callback(new Error(t('profile.passwordMismatch')))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}))

async function onChangePassword(): Promise<void> {
  const valid = await pwdRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  changing.value = true
  try {
    await profileChangePassword({
      old_password: pwdForm.old_password,
      new_password: pwdForm.new_password,
    })
    ElMessage.success(t('profile.passwordChanged'))
    pwdRef.value?.resetFields()
  } finally {
    changing.value = false
  }
}

onMounted(load)

/* ---- 登录设备 ---- */
const sessions = ref<MySession[]>([])
const sessionsLoading = ref(false)

async function loadSessions(): Promise<void> {
  sessionsLoading.value = true
  try {
    sessions.value = await profileSessions()
  } catch {
    // 失败提示由请求拦截器统一处理，这里吞掉异常避免 unhandled rejection
  } finally {
    sessionsLoading.value = false
  }
}

async function onRevoke(row: MySession): Promise<void> {
  try {
    await ElMessageBox.confirm(
      t('profile.revokeConfirm', {
        device: row.device || t('profile.thisDevice'),
        ip: row.ip || t('profile.unknownIp'),
      }),
      t('profile.revokeTitle'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await profileRevokeSession(row.jti)
  } catch {
    // 错误提示由 axios 拦截器统一处理，这里只吞掉 rejection 避免冒泡到 Vue
    return
  }
  ElMessage.success(t('profile.revoked'))
  await loadSessions()
}

onMounted(loadSessions)
</script>

<style scoped>
.profile-card + .profile-card {
  margin-top: 16px;
}

/* 登录设备卡片直接跟在 el-row 之后（与上方两栏拉开间距） */
.el-row + .profile-card {
  margin-top: 16px;
}

.profile-card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
}

.profile-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.profile-session-tip {
  display: block;
  margin: 10px 0 0;
}

.profile-identity {
  display: flex;
  gap: 14px;
  align-items: center;
  padding-bottom: 16px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--art-card-border);
}

.profile-identity-text {
  min-width: 0;
}

.profile-name {
  font-size: 16px;
  font-weight: 600;
  color: var(--art-main);
}

.profile-account {
  margin-top: 2px;
  font-size: 12px;
  color: var(--art-muted);
}

.profile-desc :deep(.el-descriptions__label) {
  width: 92px;
}

.profile-tip {
  margin-left: 10px;
  font-size: 12px;
  color: var(--art-muted);
}
</style>
