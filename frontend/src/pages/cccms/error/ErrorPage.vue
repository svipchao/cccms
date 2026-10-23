<template>
  <div class="error-page">
    <div class="error-page-inner">
      <div class="error-page-code">
        <span class="error-page-digit">4</span>
        <span class="error-page-zero" />
        <span class="error-page-digit">{{ lastDigit }}</span>
      </div>
      <h1 class="error-page-title">{{ t(titleKey) }}</h1>
      <p class="error-page-desc">{{ t(descKey) }}</p>
      <div class="error-page-actions">
        <el-button type="primary" @click="goHome">{{ t('core.error.backHome') }}</el-button>
        <el-button @click="goBack">{{ t('core.error.backPrev') }}</el-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { HOME_PATH } from '@/stores/worktab'

const props = defineProps<{
  /** 错误码，如 403 / 404（用于展示末位数字） */
  code: number
  /** 标题文案 i18n key */
  titleKey: string
  /** 描述文案 i18n key */
  descKey: string
}>()

const { t } = useI18n({ useScope: 'global' })
const router = useRouter()

const lastDigit = computed(() => String(props.code).slice(-1))

function goHome(): void {
  router.push(HOME_PATH)
}

function goBack(): void {
  router.back()
}
</script>

<style scoped>
.error-page {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 100vh;
  background: var(--art-body-bg);
}

.error-page-inner {
  padding: 40px;
  text-align: center;
}

.error-page-code {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  font-size: 110px;
  font-weight: 800;
  line-height: 1;
  color: var(--art-primary);
  letter-spacing: 4px;
}

.error-page-zero {
  display: block;
  width: 92px;
  height: 92px;
  border: 10px solid var(--art-primary);
  border-radius: 50%;
  opacity: 0.9;
}

.error-page-title {
  margin: 28px 0 8px;
  font-size: 22px;
  font-weight: 600;
  color: var(--art-main);
}

.error-page-desc {
  margin: 0 0 28px;
  font-size: 14px;
  color: var(--art-sub);
}

.error-page-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
}
</style>
