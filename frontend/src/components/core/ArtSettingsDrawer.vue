<template>
  <el-drawer v-model="visible" :title="t('setting.title')" :size="320" append-to-body>
    <div class="setting-block">
      <div class="setting-label">{{ t('setting.mode') }}</div>
      <el-segmented v-model="mode" :options="MODE_OPTIONS" block />
    </div>

    <div class="setting-block">
      <div class="setting-label">{{ t('setting.primary') }}</div>
      <div class="setting-colors">
        <button
          v-for="c in PRESET_COLORS"
          :key="c.value"
          class="setting-color"
          :class="{ 'is-active': isCurrent(c.value) }"
          :style="{ background: c.value }"
          :title="c.name"
          type="button"
          @click="setting.setPrimary(c.value)"
        />
        <el-color-picker
          :model-value="setting.theme.primary"
          :predefine="PRESET_COLORS.map((c) => c.value)"
          @change="onCustomColor"
        />
      </div>
    </div>

    <div class="setting-block">
      <div class="setting-label">
        {{ t('setting.radius') }}
        <span class="setting-value">{{ setting.theme.radius }}px</span>
      </div>
      <el-slider :model-value="setting.theme.radius" :min="0" :max="20" :step="1" @input="setting.setRadius" />
    </div>

    <div class="setting-block">
      <div class="setting-label">
        {{ t('setting.containerWidth') }}
        <span class="setting-value">
          {{ setting.theme.containerWidth > 0 ? `${setting.theme.containerWidth}px` : t('setting.fullWidth') }}
        </span>
      </div>
      <el-slider
        :model-value="setting.theme.containerWidth"
        :min="0"
        :max="2400"
        :step="120"
        @input="setting.setContainerWidth"
      />
    </div>

    <p class="setting-hint">
      {{ setting.hasOwnPref ? t('setting.ownPref') : t('setting.systemPref') }}
    </p>
    <el-button class="setting-reset" @click="onResetToSystem">{{ t('setting.resetToSystem') }}</el-button>
  </el-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { PRESET_COLORS, type ThemeMode } from '@/utils/theme'
import { useAppStore } from '@/stores/app'
import { useSettingStore } from '@/stores/setting'

const { t } = useI18n({ useScope: 'global' })

const visible = defineModel<boolean>({ required: true })

const setting = useSettingStore()
const appStore = useAppStore()

/** 清除本机偏好，回到后台下发的默认主题 */
function onResetToSystem(): void {
  setting.resetToSystem(appStore.config.ui)
}

const MODE_OPTIONS = computed(() => [
  { label: t('setting.light'), value: 'light' },
  { label: t('setting.dark'), value: 'dark' },
  { label: t('setting.auto'), value: 'auto' },
])

const mode = computed<ThemeMode>({
  get: () => setting.theme.mode,
  set: (value) => setting.setMode(value),
})

function isCurrent(color: string): boolean {
  return setting.theme.primary.toLowerCase() === color.toLowerCase()
}

function onCustomColor(value: string | null): void {
  if (value) {
    setting.setPrimary(value)
  }
}
</script>

<style scoped>
.setting-block {
  margin-bottom: var(--art-sp-5);
}

.setting-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  font-size: var(--art-fs-dense);
  color: var(--art-sub);
}

.setting-value {
  font-size: 12px;
  color: var(--art-muted);
}

.setting-colors {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

/* 色块：焦点环由 index.css 的统一规则提供（原本的 outline: none 会让
 * 键盘用户完全看不到当前选中哪个色块）。 */
.setting-color {
  width: 24px;
  height: 24px;
  padding: 0;
  cursor: pointer;
  border: 2px solid transparent;
  border-radius: var(--art-radius-sm);
  transition: transform 0.15s ease;
}

.setting-color:hover {
  transform: scale(1.1);
}

.setting-color.is-active {
  border-color: var(--art-main);
}

.setting-hint {
  margin: 0 0 10px;
  font-size: 12px;
  color: var(--art-muted);
}

.setting-reset {
  width: 100%;
}
</style>
