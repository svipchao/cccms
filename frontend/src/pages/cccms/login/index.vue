<template>
  <div class="login">
    <div class="login-theme">
      <el-tooltip :content="setting.isDark ? t('login.toLight') : t('login.toDark')" placement="bottom">
        <el-button text circle @click="setting.toggleDark()">
          <el-icon :size="18">
            <i class="ri-sun-line" v-if="setting.isDark" />
            <i class="ri-moon-line" v-else />
          </el-icon>
        </el-button>
      </el-tooltip>
    </div>

    <div class="login-card">
      <!-- 左侧品牌区（窄屏隐藏） -->
      <div class="login-brand">
        <div class="login-brand-blob is-a" />
        <div class="login-brand-blob is-b" />

        <div class="login-brand-head">
          <img v-if="appStore.logo" class="login-brand-img" :src="appStore.logo" :alt="t('login.brandTitle')" />
          <div v-else class="login-brand-logo">{{ brandInitial }}</div>
          <span class="login-brand-name">{{ appStore.systemName }}</span>
        </div>

        <h1 class="login-brand-title">{{ t('login.brandTitle') }}</h1>
        <p class="login-brand-desc">{{ t('login.brandDesc') }}</p>

        <ul class="login-brand-list">
          <li v-for="item in features" :key="item">
            <el-icon :size="14"><i class="ri-check-line" /></el-icon>
            <span>{{ item }}</span>
          </li>
        </ul>
      </div>

      <!-- 右侧表单 -->
      <div class="login-form">
        <div class="login-form-head">
          <h2 class="login-form-title">{{ t('login.welcome') }}</h2>
          <p class="login-form-desc">{{ t('login.welcomeDesc') }}</p>
        </div>

        <!-- 维护模式（system.maintenance）开启时，仅超管可登录 -->
        <el-alert
          v-if="appStore.maintenance"
          class="login-maintenance"
          type="warning"
          :closable="false"
          show-icon
          :title="appStore.notice"
        />

        <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="onSubmit">
          <el-form-item prop="username">
            <el-input v-model="form.username" :placeholder="t('login.username')" clearable>
              <template #prefix
                ><el-icon><i class="ri-user-line" /></el-icon
              ></template>
            </el-input>
          </el-form-item>

          <el-form-item prop="password">
            <el-input v-model="form.password" type="password" show-password :placeholder="t('login.password')">
              <template #prefix
                ><el-icon><i class="ri-lock-line" /></el-icon
              ></template>
            </el-input>
          </el-form-item>

          <el-form-item v-if="captchaImage" prop="captcha">
            <div class="login-captcha">
              <el-input v-model="form.captcha" :placeholder="t('login.captcha')">
                <template #prefix
                  ><el-icon><i class="ri-key-2-line" /></el-icon
                ></template>
              </el-input>
              <img class="login-captcha-img" :src="captchaImage" :alt="t('login.captcha')" @click="loadCaptcha" />
            </div>
          </el-form-item>

          <div class="login-row">
            <el-checkbox v-model="rememberMe">{{ t('login.remember') }}</el-checkbox>
            <!-- 找回密码：后台未开启任何渠道（security.reset_channel=off）时不展示 -->
            <el-button v-if="appStore.resetChannels.length > 0" link type="primary" @click="openReset">
              {{ t('login.forgot') }}
            </el-button>
          </div>

          <el-button type="primary" size="large" class="login-submit" :loading="loading" @click="onSubmit">
            {{ t('login.submit') }}
          </el-button>
        </el-form>

        <p v-if="appStore.icp || appStore.copyright" class="login-footer">
          <span v-if="appStore.copyright">{{ appStore.copyright }}</span>
          <span v-if="appStore.icp">{{ appStore.icp }}</span>
        </p>
      </div>
    </div>

    <!-- 找回密码弹窗：账号 → 渠道 → 验证码 → 新口令 -->
    <el-dialog
      v-model="resetVisible"
      :title="t('login.resetTitle')"
      width="420px"
      append-to-body
      @closed="onResetClosed"
    >
      <p class="login-reset-desc">{{ t('login.resetDesc') }}</p>

      <el-form ref="resetFormRef" :model="resetForm" :rules="resetRules" label-position="top">
        <el-form-item prop="account" :label="t('login.resetAccount')">
          <el-input v-model="resetForm.account" clearable :placeholder="t('login.resetAccountPlaceholder')" />
        </el-form-item>

        <el-form-item prop="channel" :label="t('login.resetChannel')">
          <el-radio-group v-model="resetForm.channel">
            <el-radio-button v-for="item in appStore.resetChannels" :key="item" :value="item">
              {{ channelLabel(item) }}
            </el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item prop="code" :label="t('login.resetCode')">
          <div class="login-reset-code">
            <el-input v-model="resetForm.code" :maxlength="6" :placeholder="t('login.resetCodePlaceholder')" />
            <el-button :disabled="countdown > 0" :loading="sending" @click="onSendCode">
              {{ countdown > 0 ? t('login.resetResend', { seconds: countdown }) : t('login.resetSend') }}
            </el-button>
          </div>
        </el-form-item>

        <el-form-item prop="password" :label="t('login.resetNewPassword')">
          <el-input v-model="resetForm.password" type="password" show-password />
        </el-form-item>

        <el-form-item prop="confirm" :label="t('login.resetConfirmPassword')">
          <el-input v-model="resetForm.confirm" type="password" show-password @keyup.enter="onReset" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="resetVisible = false">{{ t('login.resetCancel') }}</el-button>
        <el-button type="primary" :loading="resetting" @click="onReset">{{ t('login.resetSubmit') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { captcha as captchaApi, resetPassword, sendResetCode, type ResetChannel } from '@/api/auth'
import { passwordValidator } from '@/utils/password'
import { useAppStore } from '@/stores/app'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

const { t } = useI18n({ useScope: 'global' })

const REMEMBER_KEY = 'cccms_remember_username'

/** 品牌区卖点：用 computed 包住，切换语言时能实时重渲染 */
const features = computed(() => [t('login.feature1'), t('login.feature2'), t('login.feature3')])

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const setting = useSettingStore()
const appStore = useAppStore()

const brandInitial = computed(() => (appStore.systemName || 'C').charAt(0).toUpperCase())

const loading = ref(false)
const rememberMe = ref(false)
const captchaImage = ref('')
const captchaId = ref('')
const formRef = ref<FormInstance>()

const form = reactive({ username: '', password: '', captcha: '' })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: t('login.usernameRequired'), trigger: 'blur' }],
  password: [{ required: true, message: t('login.passwordRequired'), trigger: 'blur' }],
}))

/** 验证码：后端未实现时 data 为 null，此处静默隐藏该输入项 */
async function loadCaptcha(): Promise<void> {
  try {
    const res = await captchaApi()
    if (res?.image) {
      captchaImage.value = res.image
      captchaId.value = res.captcha_id
    }
  } catch {
    captchaImage.value = ''
  }
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  loading.value = true
  try {
    await userStore.login({
      username: form.username,
      password: form.password,
      ...(captchaImage.value ? { captcha: form.captcha, captcha_id: captchaId.value } : {}),
    })

    if (rememberMe.value) {
      localStorage.setItem(REMEMBER_KEY, form.username)
    } else {
      localStorage.removeItem(REMEMBER_KEY)
    }

    ElMessage.success(t('login.success'))
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.replace(redirect)
  } catch {
    // 错误提示由 axios 拦截器统一处理；刷新验证码避免复用
    if (captchaImage.value) {
      void loadCaptcha()
    }
  } finally {
    loading.value = false
  }
}

/* ---- 找回密码 ---- */
/** 与后端默认发送间隔（security.reset_send_interval）保持一致 */
const RESET_COUNTDOWN = 60

const resetVisible = ref(false)
const resetFormRef = ref<FormInstance>()
const sending = ref(false)
const resetting = ref(false)
const countdown = ref(0)
let countdownTimer: number | undefined

const resetForm = reactive({ account: '', channel: '', code: '', password: '', confirm: '' })

const resetRules = computed<FormRules>(() => ({
  account: [{ required: true, message: t('login.resetAccountRequired'), trigger: 'blur' }],
  channel: [{ required: true, message: t('login.resetChannelRequired'), trigger: 'change' }],
  code: [
    { required: true, message: t('login.resetCodeRequired'), trigger: 'blur' },
    { pattern: /^\d{6}$/, message: t('login.resetCodeFormat'), trigger: 'blur' },
  ],
  password: [
    { required: true, message: t('login.resetPasswordRequired'), trigger: 'blur' },
    { validator: passwordValidator(() => ({ username: resetForm.account })), trigger: 'blur' },
  ],
  confirm: [
    { required: true, message: t('login.resetConfirmRequired'), trigger: 'blur' },
    {
      validator: (_rule, value, callback) => {
        if (value !== resetForm.password) {
          callback(new Error(t('login.resetConfirmMismatch')))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}))

function channelLabel(channel: string): string {
  return channel === 'email' ? t('login.resetChannelEmail') : t('login.resetChannelSms')
}

function openReset(): void {
  resetForm.account = form.username
  // 默认选中后台开启的第一个渠道（off 时入口本身不展示）
  resetForm.channel = appStore.resetChannels[0] ?? ''
  resetVisible.value = true
}

/** 倒计时与表单状态在关闭后清理，避免下次打开残留 */
function onResetClosed(): void {
  window.clearInterval(countdownTimer)
  countdownTimer = undefined
  countdown.value = 0
  resetForm.password = ''
  resetForm.confirm = ''
  resetFormRef.value?.resetFields()
}

async function onSendCode(): Promise<void> {
  const valid = await resetFormRef.value
    ?.validateField(['account', 'channel'])
    .then(() => true)
    .catch(() => false)
  if (!valid) {
    return
  }

  sending.value = true
  try {
    await sendResetCode({ account: resetForm.account, channel: resetForm.channel as ResetChannel })
    ElMessage.success(t('login.resetCodeSent'))
    countdown.value = RESET_COUNTDOWN
    window.clearInterval(countdownTimer)
    countdownTimer = window.setInterval(() => {
      countdown.value -= 1
      if (countdown.value <= 0) {
        window.clearInterval(countdownTimer)
        countdownTimer = undefined
      }
    }, 1000)
  } finally {
    sending.value = false
  }
}

async function onReset(): Promise<void> {
  const valid = await resetFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  resetting.value = true
  try {
    await resetPassword({
      account: resetForm.account,
      channel: resetForm.channel as ResetChannel,
      code: resetForm.code,
      password: resetForm.password,
    })
    ElMessage.success(t('login.resetSuccess'))
    resetVisible.value = false
  } finally {
    resetting.value = false
  }
}

onBeforeUnmount(() => {
  window.clearInterval(countdownTimer)
})

onMounted(() => {
  const saved = localStorage.getItem(REMEMBER_KEY)
  if (saved) {
    form.username = saved
    rememberMe.value = true
  }
  void loadCaptcha()
})
</script>

<style scoped>
.login {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100vh;
  padding: 24px;
  overflow: hidden;
  background:
    radial-gradient(circle at 12% 18%, rgb(43 108 255 / 14%), transparent 42%),
    radial-gradient(circle at 88% 82%, rgb(43 108 255 / 10%), transparent 40%), var(--art-body-bg);
}

.login-theme {
  position: absolute;
  top: 18px;
  right: 22px;
}

.login-card {
  display: flex;
  width: 100%;
  max-width: 940px;
  overflow: hidden;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) + 4px);
  box-shadow: var(--art-shadow-2);
}

/* ---- 品牌区 ---- */
.login-brand {
  position: relative;
  display: flex;
  flex: 1 1 52%;
  flex-direction: column;
  /* 品牌头贴顶：原来整组垂直居中，logo 会被顶到区块中间 */
  justify-content: flex-start;
  padding: 44px 44px 48px;
  overflow: hidden;
  color: #fff;
  background: linear-gradient(140deg, var(--art-primary) 0%, #1b3fa8 100%);
}

.login-brand-blob {
  position: absolute;
  background: rgb(255 255 255 / 12%);
  border-radius: 50%;
}

.login-brand-blob.is-a {
  top: -70px;
  right: -60px;
  width: 220px;
  height: 220px;
}

.login-brand-blob.is-b {
  bottom: -90px;
  left: -50px;
  width: 280px;
  height: 280px;
  background: rgb(255 255 255 / 8%);
}

.login-brand-head,
.login-brand-title,
.login-brand-desc,
.login-brand-list {
  position: relative;
  z-index: 1;
}

/* 盾牌 + 系统名：在蓝色品牌区内水平居中、贴顶显示。
   与标题之间留 32px（原来 60px，会在头部造成一块无内容的空洞）。 */
.login-brand-head {
  display: flex;
  gap: var(--art-sp-2);
  align-items: center;
  justify-content: center;
  margin-bottom: var(--art-sp-6);
}

/* 兜底字母标：尺寸与 logo 图保持一致，换图前后占位不跳 */
.login-brand-logo {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  font-size: 12px;
  font-weight: 700;
  background: rgb(255 255 255 / 20%);
  border-radius: 6px;
}

.login-brand-img {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  object-fit: contain;
  border-radius: 6px;
}

.login-maintenance {
  margin-bottom: 18px;
}

.login-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  margin: 10px 0 0;
  font-size: 12px;
  color: var(--art-muted);
}

.login-brand-name {
  font-size: 16px;
  font-weight: 700;
  /* 字距会在末尾多出一格，用负 margin 抵掉，居中的才是视觉中心 */
  margin-right: -1px;
  letter-spacing: 1px;
}

.login-brand-title {
  margin: 0 0 var(--art-sp-3);
  font-size: var(--art-fs-h1);
  font-weight: 700;
  line-height: var(--art-lh-h1);
}

.login-brand-desc {
  margin: 0 0 var(--art-sp-6);
  font-size: var(--art-fs-body);
  line-height: var(--art-lh-body);
  opacity: 0.82;
}

.login-brand-list {
  padding: 0;
  margin: 0;
  list-style: none;
}

.login-brand-list li {
  display: flex;
  gap: var(--art-sp-2);
  align-items: center;
  margin-bottom: var(--art-sp-3);
  font-size: var(--art-fs-dense);
  opacity: 0.92;
}

/* ---- 表单区 ---- */
.login-form {
  display: flex;
  flex: 1 1 48%;
  flex-direction: column;
  justify-content: center;
  padding: 48px 44px;
}

.login-form-head {
  margin-bottom: var(--art-sp-5);
}

.login-form-title {
  margin: 0;
  font-size: var(--art-fs-h2);
  font-weight: 700;
  line-height: var(--art-lh-h2);
  color: var(--art-main);
}

.login-form-desc {
  margin: var(--art-sp-1) 0 0;
  font-size: var(--art-fs-body);
  line-height: var(--art-lh-body);
  color: var(--art-muted);
}

.login-captcha {
  display: flex;
  gap: 10px;
  width: 100%;
}

.login-captcha-img {
  flex-shrink: 0;
  width: 120px;
  height: 40px;
  cursor: pointer;
  object-fit: contain;
  background: var(--art-hover-bg);
  border: 1px solid var(--art-line-control);
  border-radius: var(--el-border-radius-base);
}

.login-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

/* 中文按钮不需要额外字距：2px 会把「登录」两字拉散，与全站按钮也不一致 */
.login-submit {
  width: 100%;
  font-weight: 500;
}

/* ---- 找回密码弹窗 ---- */
.login-reset-desc {
  margin: 0 0 16px;
  font-size: var(--art-fs-dense);
  color: var(--art-muted);
}

.login-reset-code {
  display: flex;
  gap: 10px;
  width: 100%;
}

.login-reset-code .el-button {
  flex-shrink: 0;
  min-width: 120px;
}

.login-tip {
  margin: 20px 0 0;
  font-size: 12px;
  text-align: center;
  color: var(--art-muted);
}

/* ---- 窄屏：隐藏品牌区 ---- */
@media (max-width: 860px) {
  .login-card {
    max-width: 420px;
  }

  .login-brand {
    display: none;
  }

  .login-form {
    padding: 40px 28px;
  }
}
</style>
