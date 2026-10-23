<template>
  <div class="art-scroll">
    <div class="art-page">
      <!-- 欢迎 -->
      <el-card shadow="never" class="hello">
        <div class="hello-inner">
          <el-avatar :size="52" class="hello-avatar">{{ avatarText }}</el-avatar>
          <div class="hello-text">
            <h2 class="hello-title">{{ helloText }}</h2>
            <p class="hello-sub">
              <span>{{ todayText }}</span>
              <span class="hello-divider" />
              <span>{{ t('cccms.dashboard.roleLabel') }}</span>
              <el-tag v-for="r in userStore.profile?.roles || []" :key="r" size="small" effect="light">
                {{ r }}
              </el-tag>
              <el-tag v-if="userStore.superAdmin" size="small" type="danger" effect="light">
                {{ t('cccms.dashboard.superAdmin') }}
              </el-tag>
            </p>
          </div>
        </div>
      </el-card>

      <!-- 统计卡 -->
      <div class="cards">
        <el-card v-for="c in cards" :key="c.key" shadow="never" class="card">
          <div class="card-inner">
            <div class="card-icon" :style="{ background: c.bg, color: c.color }">
              <el-icon :size="22"><ArtIcon :name="c.icon" /></el-icon>
            </div>
            <div>
              <div class="card-value">{{ c.value }}</div>
              <div class="card-label">{{ c.label }}</div>
            </div>
          </div>
        </el-card>
      </div>

      <!-- 图表：拿不到日志 / 附件列表页权限时后端下发空数据，对应卡片不渲染 -->
      <el-row v-if="hasTrend || hasPie" :gutter="14">
        <el-col v-if="hasTrend" :xs="24" :lg="hasPie ? 15 : 24">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('cccms.dashboard.trendTitle') }}</span></template
            >
            <div ref="trendRef" class="chart" />
          </el-card>
        </el-col>
        <el-col v-if="hasPie" :xs="24" :lg="hasTrend ? 9 : 24">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('cccms.dashboard.pieTitle') }}</span></template
            >
            <div ref="pieRef" class="chart" />
          </el-card>
        </el-col>
      </el-row>

      <!-- 我的收藏：与顶栏快捷导航共用同一份收藏（store 层同步），可拖拽排序。
           数据源是当前账号可见菜单，天然不含无权限的入口。 -->
      <el-card shadow="never" class="chart-card shortcut-card">
        <template #header
          ><span>{{ t('cccms.dashboard.favoriteTitle') }}</span></template
        >
        <el-empty v-if="shortcuts.length === 0" :description="t('cccms.dashboard.favoriteEmpty')" :image-size="70" />
        <div v-else class="shortcuts">
          <div
            v-for="(item, index) in shortcuts"
            :key="item.path"
            class="shortcut"
            :class="{ 'is-dragging': dragIndex === index }"
            draggable="true"
            @dragstart="onDragStart(index, $event)"
            @dragover.prevent="onDragOver(index)"
            @dragend="onDragEnd"
            @drop.prevent="onDragEnd"
            @click="goto(item.path)"
          >
            <el-icon :size="18" class="shortcut-icon"><ArtIcon :name="item.icon" /></el-icon>
            <span class="shortcut-title">{{ item.title }}</span>
            <el-icon :size="13" class="shortcut-handle"><i class="ri-award-line" /></el-icon>
          </div>
        </div>
      </el-card>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'dashboard' })

import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { echarts } from '@/utils/echarts'
import ArtIcon from '@/components/core/ArtIcon.vue'
import { dashboardStats, type DashboardStats } from '@/api/dashboard'
import { useMenuStore } from '@/stores/menu'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

const { t, locale } = useI18n({ useScope: 'global' })

const router = useRouter()
const menuStore = useMenuStore()
const userStore = useUserStore()
const setting = useSettingStore()

const stats = ref<DashboardStats | null>(null)
const trendRef = ref<HTMLElement>()
const pieRef = ref<HTMLElement>()

let trendChart: echarts.ECharts | null = null
let pieChart: echarts.ECharts | null = null

const avatarText = computed(() => (userStore.nickname || 'U').charAt(0).toUpperCase())

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 6) {
    return t('cccms.dashboard.greetingNight')
  }
  if (hour < 12) {
    return t('cccms.dashboard.greetingMorning')
  }
  if (hour < 18) {
    return t('cccms.dashboard.greetingAfternoon')
  }
  return t('cccms.dashboard.greetingEvening')
})

// 称谓与问候语拼成整句交给语言包（不同语言的标点与语序不同）
const helloText = computed(() =>
  t('cccms.dashboard.helloTitle', { greeting: greeting.value, name: userStore.nickname || t('cccms.dashboard.admin') }),
)

const todayText = computed(() => {
  const now = new Date()
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return t('cccms.dashboard.dateText', { date, week: t(`cccms.dashboard.weekday${now.getDay()}`) })
})

interface StatCard {
  key: string
  label: string
  /** 后端没下发该键时为 undefined —— 表示无权限，整张卡片不渲染 */
  value: number | undefined
  icon: string
  color: string
  bg: string
}

const cards = computed<StatCard[]>(() => {
  const c = stats.value?.counts

  // 全部卡片统一按「后端是否下发该键」过滤：没下发 = 当前账号没有该模块的权限节点
  // （超管 hasPermission() 直通，因此永远拿全）；参与数据权限的表已按当前用户范围收窄。
  const all: StatCard[] = [
    {
      key: 'user',
      label: t('cccms.dashboard.statUser'),
      value: c?.user,
      icon: 'icon-user',
      color: '#2b6cff',
      bg: 'rgb(43 108 255 / 12%)',
    },
    {
      key: 'role',
      label: t('cccms.dashboard.statRole'),
      value: c?.role,
      icon: 'icon-safe',
      color: '#722ed1',
      bg: 'rgb(114 46 209 / 12%)',
    },
    {
      key: 'dept',
      label: t('cccms.dashboard.statDept'),
      value: c?.dept,
      icon: 'icon-tree',
      color: '#13c2c2',
      bg: 'rgb(19 194 194 / 12%)',
    },
    {
      key: 'post',
      label: t('cccms.dashboard.statPost'),
      value: c?.post,
      icon: 'icon-badge',
      color: '#fa8c16',
      bg: 'rgb(250 140 22 / 14%)',
    },
    {
      key: 'file',
      label: t('cccms.dashboard.statFile'),
      value: c?.file,
      icon: 'icon-upload',
      color: '#21c26b',
      bg: 'rgb(33 194 107 / 12%)',
    },
    {
      key: 'today_log',
      label: t('cccms.dashboard.statTodayLog'),
      value: c?.today_log,
      icon: 'icon-file',
      color: '#f4524d',
      bg: 'rgb(244 82 77 / 12%)',
    },
    {
      key: 'online',
      label: t('cccms.dashboard.statOnline'),
      value: c?.online,
      icon: 'icon-monitor',
      color: '#13c2c2',
      bg: 'rgb(19 194 194 / 12%)',
    },
    {
      key: 'notice_unread',
      label: t('cccms.dashboard.statNoticeUnread'),
      value: c?.notice_unread,
      icon: 'icon-tickets',
      color: '#f4524d',
      bg: 'rgb(244 82 77 / 12%)',
    },
    {
      key: 'dict',
      label: t('cccms.dashboard.statDict'),
      value: c?.dict,
      icon: 'icon-book',
      color: '#722ed1',
      bg: 'rgb(114 46 209 / 12%)',
    },
    {
      key: 'crontab',
      label: t('cccms.dashboard.statCrontab'),
      value: c?.crontab,
      icon: 'icon-clock',
      color: '#fa8c16',
      bg: 'rgb(250 140 22 / 14%)',
    },
    {
      key: 'data_rule',
      label: t('cccms.dashboard.statDataRule'),
      value: c?.data_rule,
      icon: 'icon-key',
      color: '#2b6cff',
      bg: 'rgb(43 108 255 / 12%)',
    },
    {
      key: 'tenant',
      label: t('cccms.dashboard.statTenant'),
      value: c?.tenant,
      icon: 'icon-management',
      color: '#21c26b',
      bg: 'rgb(33 194 107 / 12%)',
    },
  ]

  return all.filter((item) => item.value !== undefined)
})

/** 图表是否渲染：后端按权限下发，拿不到日志 / 附件列表页权限时给的是空数据 */
const hasTrend = computed(() => (stats.value?.log_trend.dates.length ?? 0) > 0)
const hasPie = computed(() => stats.value?.counts.file !== undefined)

/** 我的收藏：与顶栏快捷导航共用 store 里的同一份收藏，展示顺序即收藏顺序 */
const shortcuts = computed(() => menuStore.favoriteMenus)

/** 拖拽排序：dragover 时实时换位，落点所见即所得（原生拖拽，不引第三方库） */
const dragIndex = ref(-1)

function onDragStart(index: number, event: DragEvent): void {
  dragIndex.value = index
  event.dataTransfer?.setData('text/plain', String(index))
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
  }
}

function onDragOver(index: number): void {
  if (dragIndex.value < 0 || dragIndex.value === index) {
    return
  }
  menuStore.reorderFavorite(dragIndex.value, index)
  dragIndex.value = index
}

function onDragEnd(): void {
  dragIndex.value = -1
}

function goto(path: string): void {
  void router.push(path)
}

function cssVar(name: string, fallback: string): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

function renderTrend(): void {
  if (!trendRef.value || !stats.value) {
    return
  }
  trendChart ??= echarts.init(trendRef.value)

  const primary = cssVar('--art-primary', '#2b6cff')
  const line = cssVar('--art-card-border', '#e9edf5')
  const sub = cssVar('--art-sub', '#5b6474')

  trendChart.setOption({
    grid: { left: 6, right: 18, top: 22, bottom: 4, containLabel: true },
    tooltip: { trigger: 'axis' },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: stats.value.log_trend.dates.map((d) => d.slice(5)),
      axisLine: { lineStyle: { color: line } },
      axisTick: { show: false },
      axisLabel: { color: sub },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: line, type: 'dashed' } },
      axisLabel: { color: sub },
    },
    series: [
      {
        name: t('cccms.dashboard.trendSeries'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 7,
        data: stats.value.log_trend.values,
        itemStyle: { color: primary },
        lineStyle: { width: 3, color: primary },
        areaStyle: {
          color: {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
              { offset: 0, color: `${primary}4d` },
              { offset: 1, color: `${primary}00` },
            ],
          },
        },
      },
    ],
  })
}

function renderPie(): void {
  if (!pieRef.value || !stats.value) {
    return
  }
  pieChart ??= echarts.init(pieRef.value)

  const sub = cssVar('--art-sub', '#5b6474')
  const cardBg = cssVar('--art-card-bg', '#ffffff')
  const types = stats.value.file_types

  pieChart.setOption({
    tooltip: { trigger: 'item' },
    legend: { bottom: 0, icon: 'circle', textStyle: { color: sub } },
    series: [
      {
        type: 'pie',
        radius: ['46%', '68%'],
        center: ['50%', '44%'],
        avoidLabelOverlap: true,
        itemStyle: { borderColor: cardBg, borderWidth: 2 },
        label: { show: false },
        data: types.length ? types : [{ name: t('core.table.empty'), value: 1 }],
      },
    ],
  })
}

function renderAll(): void {
  renderTrend()
  renderPie()
}

function resizeAll(): void {
  trendChart?.resize()
  pieChart?.resize()
}

async function load(): Promise<void> {
  stats.value = await dashboardStats()
  await nextTick()
  renderAll()
}

// 主题或语言切换后图表文案 / 配色要跟着变（echarts 是命令式渲染，需手动重绘）
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  renderAll()
})

onMounted(async () => {
  window.addEventListener('resize', resizeAll)
  await load()
})

// keep-alive 缓存后重新进入，容器尺寸可能已变
onActivated(() => {
  nextTick(resizeAll)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeAll)
  trendChart?.dispose()
  pieChart?.dispose()
  trendChart = null
  pieChart = null
})
</script>

<style scoped>
.hello {
  margin-bottom: 14px;
}

.hello-inner {
  display: flex;
  gap: 16px;
  align-items: center;
}

.hello-avatar {
  font-size: 20px;
  background: var(--art-primary);
}

.hello-title {
  margin: 0;
  font-size: 19px;
  font-weight: 600;
  color: var(--art-main);
}

.hello-sub {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin: 8px 0 0;
  font-size: 13px;
  color: var(--art-sub);
}

.hello-divider {
  width: 1px;
  height: 12px;
  background: var(--art-card-border);
}

.cards {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.card-inner {
  display: flex;
  gap: 12px;
  align-items: center;
}

.card-icon {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  border-radius: calc(var(--art-radius) - 2px);
}

.card-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--art-main);
}

.card-label {
  font-size: 12px;
  color: var(--art-muted);
}

.chart-card :deep(.el-card__body) {
  padding: 8px 12px 12px;
}

.chart {
  width: 100%;
  height: 288px;
}

/* ---- 常用功能 ---- */
.shortcut-card {
  margin-top: 14px;
}

.shortcuts {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}

.shortcut {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 12px;
  cursor: pointer;
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) - 2px);
  transition:
    border-color 0.15s ease,
    background 0.15s ease;
}

.shortcut:hover {
  background: var(--art-hover-bg);
  border-color: var(--art-primary);
}

.shortcut-icon {
  flex-shrink: 0;
  color: var(--art-primary);
}

.shortcut-title {
  flex: 1;
  overflow: hidden;
  font-size: 13px;
  color: var(--art-main);
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 拖拽把手：默认隐藏，悬停才显示，避免宫格看起来太杂 */
.shortcut-handle {
  flex-shrink: 0;
  color: var(--art-muted);
  cursor: grab;
  opacity: 0;
  transition: opacity 0.15s ease;
}

.shortcut:hover .shortcut-handle {
  opacity: 1;
}

.shortcut.is-dragging {
  background: var(--art-hover-bg);
  border-color: var(--art-primary);
  opacity: 0.7;
}

@media (max-width: 1400px) {
  .cards {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .shortcuts {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .cards {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .chart {
    height: 240px;
  }

  .shortcuts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
