<template>
  <div class="cron-editor">
    <!-- 执行周期 + 具体时间（宝塔式：选多久一次，再填几点几分） -->
    <div class="cron-row">
      <el-select v-model="cycle" class="cron-cycle">
        <el-option v-for="c in CYCLES" :key="c.value" :label="t(c.labelKey)" :value="c.value" />
      </el-select>

      <!-- 每 N 秒 / 每 N 分钟 / 每 N 小时 -->
      <i18n-t
        v-if="cycle === 'every_n_seconds' || cycle === 'every_n_minutes' || cycle === 'every_n_hours'"
        keypath="crontab.editor.intervalText"
        scope="global"
        tag="span"
        class="cron-sep"
      >
        <template #n>
          <el-input-number v-model="state.n" :min="1" :max="nMax" size="small" controls-position="right" />
        </template>
        <template #unit>{{ intervalUnit }}</template>
      </i18n-t>

      <span v-else-if="cycle === 'every_second'" class="cron-sep">{{ t('crontab.editor.everySecondText') }}</span>

      <!-- 每周 -->
      <i18n-t
        v-else-if="cycle === 'weekly'"
        keypath="crontab.editor.weeklyText"
        scope="global"
        tag="span"
        class="cron-sep"
      >
        <template #week>
          <el-select v-model="state.week" size="small" class="cron-week">
            <el-option v-for="(w, i) in WEEK_LABELS" :key="i" :label="w" :value="i" />
          </el-select>
        </template>
        <template #hour>
          <el-input-number v-model="state.hour" :min="0" :max="23" size="small" controls-position="right" />
        </template>
        <template #minute>
          <el-input-number v-model="state.minute" :min="0" :max="59" size="small" controls-position="right" />
        </template>
        <template #second>
          <el-input-number v-model="state.second" :min="0" :max="59" size="small" controls-position="right" />
        </template>
      </i18n-t>

      <!-- 每月 -->
      <i18n-t
        v-else-if="cycle === 'monthly'"
        keypath="crontab.editor.monthlyText"
        scope="global"
        tag="span"
        class="cron-sep"
      >
        <template #day>
          <el-input-number v-model="state.day" :min="1" :max="31" size="small" controls-position="right" />
        </template>
        <template #hour>
          <el-input-number v-model="state.hour" :min="0" :max="23" size="small" controls-position="right" />
        </template>
        <template #minute>
          <el-input-number v-model="state.minute" :min="0" :max="59" size="small" controls-position="right" />
        </template>
        <template #second>
          <el-input-number v-model="state.second" :min="0" :max="59" size="small" controls-position="right" />
        </template>
      </i18n-t>

      <!-- 每天 -->
      <i18n-t
        v-else-if="cycle === 'daily'"
        keypath="crontab.editor.dailyText"
        scope="global"
        tag="span"
        class="cron-sep"
      >
        <template #hour>
          <el-input-number v-model="state.hour" :min="0" :max="23" size="small" controls-position="right" />
        </template>
        <template #minute>
          <el-input-number v-model="state.minute" :min="0" :max="59" size="small" controls-position="right" />
        </template>
        <template #second>
          <el-input-number v-model="state.second" :min="0" :max="59" size="small" controls-position="right" />
        </template>
      </i18n-t>

      <!-- 自定义 -->
      <el-input v-else v-model="expression" class="cron-custom" :placeholder="t('crontab.editor.customPlaceholder')" />
    </div>

    <div class="cron-result">
      <div class="cron-result-row">
        <span class="cron-result-label">{{ t('crontab.editor.labelExpression') }}</span>
        <code class="cron-expr">{{ expression }}</code>
      </div>
      <div class="cron-result-row">
        <span class="cron-result-label">{{ t('crontab.editor.labelDescription') }}</span>
        <span class="cron-desc">{{ description }}</span>
      </div>
      <div class="cron-result-row">
        <span class="cron-result-label">{{ t('crontab.editor.labelRecentRuns') }}</span>
        <span v-if="previews.length" class="cron-previews">
          <span v-for="item in previews" :key="item" class="cron-preview">{{ item }}</span>
        </span>
        <span v-else class="cron-desc">{{ t('crontab.editor.previewUnavailable') }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCronDescription } from '@/composables/useCronDescription'
import {
  buildFromCycle,
  detectCycle,
  formatCronTime,
  nextRunTimes,
  type CronCycle,
  type CronCycleState,
} from '@/utils/cron'

const { t } = useI18n({ useScope: 'global' })
const { describe } = useCronDescription()

/**
 * Cron 表达式可视化编辑器（宝塔面板风格）。
 *
 * 不要求懂表达式：先选「执行周期」（每秒 / 每 N 秒 / 每 N 分钟 / 每 N 小时 /
 * 每天 / 每周 / 每月），再补具体时间（几点几分 / 几号 / 星期几）。
 * 真的需要复杂规则（如每周一三五）时，切「自定义表达式」直接写六段。
 *
 * 底部固定回显：生成的表达式 + 说明（与任务列表同源）+ 最近 3 次运行时间。
 * 编辑老任务 / 点预设时会反向识别成对应周期（能识别的才切，其余进「自定义」）。
 */

const CYCLES: Array<{ value: CronCycle; labelKey: string }> = [
  { value: 'every_second', labelKey: 'crontab.editor.cycleEverySecond' },
  { value: 'every_n_seconds', labelKey: 'crontab.editor.cycleEveryNSeconds' },
  { value: 'every_n_minutes', labelKey: 'crontab.editor.cycleEveryNMinutes' },
  { value: 'every_n_hours', labelKey: 'crontab.editor.cycleEveryNHours' },
  { value: 'daily', labelKey: 'crontab.editor.cycleDaily' },
  { value: 'weekly', labelKey: 'crontab.editor.cycleWeekly' },
  { value: 'monthly', labelKey: 'crontab.editor.cycleMonthly' },
  { value: 'custom', labelKey: 'crontab.editor.cycleCustom' },
]

const WEEK_LABELS = computed(() => [
  t('crontab.editor.weekSunday'),
  t('crontab.editor.weekMonday'),
  t('crontab.editor.weekTuesday'),
  t('crontab.editor.weekWednesday'),
  t('crontab.editor.weekThursday'),
  t('crontab.editor.weekFriday'),
  t('crontab.editor.weekSaturday'),
])

const expression = defineModel<string>({ required: true })

/** 周期 + 具体时间（buildFromCycle / detectCycle 的可变副本） */
const state = reactive<CronCycleState>({ cycle: 'daily', n: 10, hour: 2, minute: 0, second: 0, day: 1, week: 1 })

const cycle = computed<CronCycle>({
  get: () => state.cycle,
  set: (v) => {
    state.cycle = v
  },
})

const intervalUnit = computed(() =>
  state.cycle === 'every_n_seconds'
    ? t('crontab.editor.unitSeconds')
    : state.cycle === 'every_n_minutes'
      ? t('crontab.editor.unitMinutes')
      : t('crontab.editor.unitHours'),
)
const nMax = computed(() => (state.cycle === 'every_n_hours' ? 23 : 59))

/* ---- 周期 + 时间 → 表达式（custom 直通，不经过 buildFromCycle） ---- */

const built = computed(() => (state.cycle === 'custom' ? expression.value : buildFromCycle(state)))

/* ---- 说明与预览 ---- */

/**
 * 说明与任务列表**完全同源**：都调 `describeCron()` + `useCronDescription()`，
 * 直接看表达式本身（而不是编辑器自己的周期状态），因此手写自定义表达式时也能给出准确说明。
 */
const description = computed(() => describe(expression.value))

const previews = computed(() => {
  try {
    return nextRunTimes(expression.value, 3).map(formatCronTime)
  } catch {
    return []
  }
})

/* ---- 双向同步 ---- */

// 周期 / 时间变了 → 非自定义模式下重新生成表达式
watch(built, (v) => {
  if (state.cycle !== 'custom') {
    expression.value = v
  }
})

// 外部值变化（编辑回显 / 点预设）→ 反向识别周期；自己刚生成的值、以及自定义编辑中都不回灌
watch(
  expression,
  (v) => {
    if (v === built.value) {
      return
    }
    Object.assign(state, detectCycle(v ?? ''))
  },
  { immediate: true },
)
</script>

<style scoped>
.cron-editor {
  width: 100%;
}

.cron-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cron-cycle {
  width: 160px;
  flex-shrink: 0;
}

.cron-week {
  width: 96px;
}

.cron-custom {
  flex: 1;
  min-width: 240px;
}

.cron-sep {
  font-size: var(--art-fs-dense);
  color: var(--art-sub);
  white-space: nowrap;
}

.cron-row :deep(.el-input-number) {
  width: 92px;
}

.cron-result {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  margin-top: 12px;
  background: var(--art-primary-light, var(--el-color-primary-light-9));
  border-radius: 6px;
}

.cron-result-row {
  display: flex;
  gap: 10px;
  align-items: baseline;
}

.cron-result-label {
  flex-shrink: 0;
  width: 56px;
  font-size: 12px;
  color: var(--art-muted);
}

.cron-expr {
  font-family: Consolas, Monaco, monospace;
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
}

.cron-desc {
  font-size: 12px;
  color: var(--art-sub);
}

.cron-previews {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
}

.cron-preview {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-sub);
}
</style>
