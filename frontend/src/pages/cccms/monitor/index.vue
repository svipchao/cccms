<template>
  <div class="art-fill monitor">
    <div class="monitor__head">
      <div class="monitor__title">
        <h2>{{ isServer ? t('cccms.monitor.tabServer') : t('cccms.monitor.title') }}</h2>
        <p v-if="isServer">{{ t('cccms.monitor.serverSubtitle') }}</p>
        <p v-else>{{ t('cccms.monitor.subtitle', { window: overview?.window ?? 300 }) }}</p>
      </div>
      <div class="monitor__tools">
        <el-radio-group v-if="!isServer" v-model="minutes" size="small" @change="loadTrend">
          <el-radio-button :value="60">{{ t('cccms.monitor.minutes', { n: 60 }) }}</el-radio-button>
          <el-radio-button :value="180">{{ t('cccms.monitor.minutes', { n: 180 }) }}</el-radio-button>
          <el-radio-button :value="720">{{ t('cccms.monitor.minutes', { n: 720 }) }}</el-radio-button>
        </el-radio-group>
        <el-switch v-model="autoRefresh" :active-text="t('cccms.monitor.autoRefresh')" size="small" />
        <el-button :loading="loading" size="small" @click="load">{{ t('cccms.monitor.refresh') }}</el-button>
      </div>
    </div>

    <!--
      服务器资源是独立权限节点（cccms:monitor:server）：没有该权限时连入口都不渲染，
      避免「点进去 403」。bar-only 只出切换条，内容仍由下面两块自己跟随 activeTab 显示。
    -->
    <ArtTabs v-if="canServer" v-model="activeTab" class="monitor__tabs" bar-only>
      <el-tab-pane :label="t('cccms.monitor.tabApi')" name="api" />
      <el-tab-pane :label="t('cccms.monitor.tabServer')" name="server" />
    </ArtTabs>

    <el-scrollbar v-show="!isServer" class="monitor__scroll">
      <!-- 读接口不落库时请求量天然偏低，不提示会被误判成流量异常 -->
      <el-alert
        v-if="overview && !overview.record_read"
        type="info"
        :closable="false"
        show-icon
        :title="t('cccms.monitor.recordReadOff')"
        class="monitor__alert"
      />

      <div class="monitor__cards">
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.requests') }}</span>
          <b>{{ overview?.requests ?? '—' }}</b>
        </div>
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.errors') }}</span>
          <b :class="{ 'is-bad': (overview?.errors ?? 0) > 0 }">{{ overview?.errors ?? '—' }}</b>
        </div>
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.errorRate') }}</span>
          <b :class="{ 'is-bad': (overview?.error_rate ?? 0) > 0 }">
            {{ overview ? `${overview.error_rate}%` : '—' }}
          </b>
        </div>
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.p95') }}</span>
          <b>{{ p95Text }}</b>
        </div>
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.online') }}</span>
          <b>{{ overview?.online ?? '—' }}</b>
        </div>
        <div class="monitor__card">
          <span class="monitor__card-label">{{ t('cccms.monitor.mysql') }} / {{ t('cccms.monitor.redis') }}</span>
          <b>
            <span :class="overview?.components.mysql ? 'is-ok' : 'is-bad'">
              {{ overview?.components.mysql ? t('cccms.monitor.up') : t('cccms.monitor.down') }}
            </span>
            /
            <span :class="overview?.components.redis ? 'is-ok' : 'is-bad'">
              {{ overview?.components.redis ? t('cccms.monitor.up') : t('cccms.monitor.down') }}
            </span>
          </b>
        </div>
      </div>

      <div class="monitor__charts">
        <div class="monitor__panel monitor__panel--wide">
          <div class="monitor__panel-head">
            <span>{{ t('cccms.monitor.trend') }}</span>
            <small v-if="overview">{{ t('cccms.monitor.updatedAt', { time: updatedAt }) }}</small>
          </div>
          <div ref="trendRef" class="monitor__chart" />
        </div>
        <div class="monitor__panel">
          <div class="monitor__panel-head">
            <span>{{ t('cccms.monitor.statusDist') }}</span>
          </div>
          <div ref="pieRef" class="monitor__chart" />
        </div>
      </div>

      <div class="monitor__panel">
        <div class="monitor__panel-head">
          <span>{{ t('cccms.monitor.paths') }}</span>
        </div>
        <el-table :data="paths" size="small" border class="monitor__table">
          <el-table-column prop="path" :label="t('cccms.monitor.path')" min-width="220" show-overflow-tooltip />
          <el-table-column prop="total" :label="t('cccms.monitor.count')" width="100" align="right" />
          <el-table-column prop="errors" :label="t('cccms.monitor.errors')" width="100" align="right" />
          <el-table-column :label="t('cccms.monitor.errorRateCol')" width="110" align="right">
            <template #default="{ row }">
              <span :class="{ 'is-bad': row.total > 0 && row.errors / row.total >= 0.05 }">
                {{ row.total > 0 ? `${((row.errors / row.total) * 100).toFixed(1)}%` : '—' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column :label="t('cccms.monitor.avg')" width="120" align="right">
            <template #default="{ row }">{{ row.avg_cost }} ms</template>
          </el-table-column>
          <el-table-column :label="t('cccms.monitor.p95Col')" width="110" align="right">
            <template #default="{ row }">{{ row.p95 }} ms</template>
          </el-table-column>
          <template #empty>
            <span class="monitor__muted">{{ t('cccms.monitor.noData') }}</span>
          </template>
        </el-table>
      </div>
    </el-scrollbar>

    <!-- `v-if` 而不是 `v-show`：没进过这个 tab 就不该多一次 200ms 的 CPU 采样 -->
    <ServerPanel v-if="canServer && serverVisited" v-show="isServer" ref="serverRef" class="monitor__server" />
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:monitor' })

import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { echarts } from '@/utils/echarts'
import { ElMessage } from 'element-plus'
import ArtTabs from '@/components/core/ArtTabs.vue'
import ServerPanel from './ServerPanel.vue'
import {
  monitorOverview,
  monitorPaths,
  monitorTrend,
  type MonitorOverview,
  type MonitorPathRow,
  type MonitorTrend,
} from '@/api/monitor'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

const { t, locale } = useI18n({ useScope: 'global' })
const setting = useSettingStore()
const userStore = useUserStore()

/** 服务器资源是独立权限节点：没有权限时连入口都不渲染，避免「点进去 403」 */
const canServer = computed(() => userStore.hasAuth('cccms:monitor:server'))
const activeTab = ref<'api' | 'server'>('api')
const isServer = computed(() => activeTab.value === 'server')
/** 首次切到服务器资源才挂载面板：它一挂载就会触发 200ms 的 CPU 采样 */
const serverVisited = ref(false)
const serverRef = ref<InstanceType<typeof ServerPanel>>()

const overview = ref<MonitorOverview | null>(null)
const trend = ref<MonitorTrend | null>(null)
const paths = ref<MonitorPathRow[]>([])
const loading = ref(false)
const minutes = ref(60)
const autoRefresh = ref(false)

const trendRef = ref<HTMLElement>()
const pieRef = ref<HTMLElement>()
let trendChart: echarts.ECharts | null = null
let pieChart: echarts.ECharts | null = null
let timer: number | null = null

const p95Text = computed(() => {
  const value = overview.value?.quantiles?.['0.95']

  return value === undefined ? '—' : `${value} ms`
})

const updatedAt = computed(() => (overview.value ? new Date(overview.value.scrape_at * 1000).toLocaleTimeString() : ''))

function cssVar(name: string, fallback: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback
}

async function loadOverview() {
  overview.value = await monitorOverview()
}

async function loadTrend() {
  trend.value = await monitorTrend(minutes.value)
  await nextTick()
  renderTrend()
}

async function loadPaths() {
  paths.value = (await monitorPaths()).rows
}

/**
 * 三个接口独立加载：权限是拆开的（index / paths / trend），
 * 用 allSettled 保证「没有明细权限」的角色仍能看到总览，而不是整页报错。
 */
async function load() {
  loading.value = true
  try {
    // 服务器资源走独立接口与独立权限，不和接口监控的三个请求混在 allSettled 里
    if (isServer.value) {
      await serverRef.value?.refresh()
      return
    }
    const results = await Promise.allSettled([loadOverview(), loadTrend(), loadPaths()])
    if (results.every((item) => item.status === 'rejected')) {
      ElMessage.error(t('cccms.monitor.loadFailed'))
      return
    }
    await nextTick()
    renderTrend()
    renderPie()
  } finally {
    loading.value = false
  }
}

function renderTrend() {
  if (!trendRef.value || !trend.value) {
    return
  }
  trendChart ??= echarts.init(trendRef.value)
  const primary = cssVar('--art-primary', '#2b6cff')
  const danger = '#f56c6c'
  const line = cssVar('--art-card-border', '#e9edf5')
  const sub = cssVar('--art-sub', '#5b6474')

  trendChart.setOption(
    {
      grid: { left: 44, right: 16, top: 36, bottom: 28 },
      tooltip: { trigger: 'axis' },
      legend: { top: 4, textStyle: { color: sub, fontSize: 11 } },
      xAxis: {
        type: 'category',
        data: trend.value.labels,
        axisLine: { lineStyle: { color: line } },
        axisLabel: { color: sub, fontSize: 11 },
      },
      yAxis: {
        type: 'value',
        minInterval: 1,
        splitLine: { lineStyle: { color: line } },
        axisLabel: { color: sub, fontSize: 11 },
      },
      series: [
        {
          name: t('cccms.monitor.trendRequests'),
          type: 'line',
          smooth: true,
          showSymbol: false,
          data: trend.value.requests,
          lineStyle: { color: primary },
          itemStyle: { color: primary },
          areaStyle: { opacity: 0.08, color: primary },
        },
        {
          name: t('cccms.monitor.trendErrors'),
          type: 'line',
          smooth: true,
          showSymbol: false,
          data: trend.value.errors,
          lineStyle: { color: danger },
          itemStyle: { color: danger },
        },
      ],
    },
    true,
  )
}

function renderPie() {
  if (!pieRef.value || !overview.value) {
    return
  }
  pieChart ??= echarts.init(pieRef.value)
  const sub = cssVar('--art-sub', '#5b6474')
  const cardBg = cssVar('--art-card-bg', '#ffffff')
  const data = overview.value.status_codes.map((item) => ({
    name: item.group === 'other' ? t('cccms.monitor.statusOther') : item.group,
    value: item.count,
  }))

  pieChart.setOption(
    {
      tooltip: { trigger: 'item' },
      legend: { bottom: 0, textStyle: { color: sub, fontSize: 11 } },
      series: [
        {
          type: 'pie',
          radius: ['45%', '68%'],
          center: ['50%', '44%'],
          avoidLabelOverlap: true,
          itemStyle: { borderColor: cardBg, borderWidth: 2 },
          label: { show: false },
          data,
        },
      ],
    },
    true,
  )
}

function startTimer() {
  stopTimer()
  if (autoRefresh.value) {
    timer = window.setInterval(() => void load(), 30000)
  }
}

function stopTimer() {
  if (timer !== null) {
    window.clearInterval(timer)
    timer = null
  }
}

watch(autoRefresh, startTimer)

watch(activeTab, async (tab) => {
  if (tab === 'server') {
    serverVisited.value = true
    return
  }
  // 切回接口监控：隐藏期间 echarts 容器尺寸为 0，重新显示后要量一次
  await nextTick()
  trendChart?.resize()
  pieChart?.resize()
})

// 主题或语言切换后 echarts 需要手动重绘（命令式渲染）
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  renderTrend()
  renderPie()
})

onMounted(() => void load())
onActivated(() => {
  trendChart?.resize()
  pieChart?.resize()
})
onBeforeUnmount(() => {
  stopTimer()
  trendChart?.dispose()
  pieChart?.dispose()
  trendChart = null
  pieChart = null
})
</script>

<style scoped>
.monitor {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
}

.monitor__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.monitor__title h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--art-main);
}

.monitor__title p {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--art-secondary);
}

.monitor__tools {
  display: flex;
  align-items: center;
  gap: 12px;
}

.monitor__scroll {
  flex: 1;
  min-height: 0;
}

.monitor__tabs {
  flex: none;
}

/* 服务器资源面板自己滚动：内容多少不定，交给内部而不是页面 */
.monitor__server {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.monitor__alert {
  margin-bottom: 12px;
}

.monitor__cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.monitor__card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.monitor__card-label {
  font-size: 12px;
  color: var(--art-secondary);
}

.monitor__card b {
  font-size: 20px;
  font-weight: 600;
  color: var(--art-main);
}

.monitor__card b.is-ok {
  color: #67c23a;
}

.monitor__card b.is-bad,
.monitor .is-bad {
  color: #f56c6c;
}

.monitor__charts {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
}

.monitor__panel {
  flex: 1;
  min-width: 0;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.monitor__panel--wide {
  flex: 2;
}

.monitor__panel-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
}

.monitor__panel-head small {
  font-size: 11px;
  font-weight: 400;
  color: var(--art-secondary);
}

.monitor__chart {
  height: 240px;
}

.monitor__table {
  width: 100%;
}

.monitor__muted {
  font-size: 12px;
  color: var(--art-secondary);
}
</style>
