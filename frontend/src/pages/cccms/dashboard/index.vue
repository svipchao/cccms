<template>
  <div class="art-scroll">
    <div class="art-page">
      <!--
        页头：问候语不再占一张卡片。
        卡片是「内容容器」，页面标题属于「导航层级」；用卡片包住问候语，
        会让首屏最大的一块区域留给信息量最低的内容，真正的工作指标被挤到下面。
      -->
      <header class="art-page-head">
        <el-avatar :size="44" class="page-avatar">{{ avatarText }}</el-avatar>
        <div class="page-head-text">
          <h1 class="art-page-title">{{ helloText }}</h1>
          <p class="art-page-sub">
            <span>{{ todayText }}</span>
            <span class="art-page-divider" />
            <span>{{ t('dashboard.roleLabel') }}</span>
            <el-tag v-for="r in userStore.profile?.roles || []" :key="r.id" size="small" effect="light">
              {{ r.name }}
            </el-tag>
            <el-tag v-if="userStore.superAdmin" size="small" type="danger" effect="light">
              {{ t('dashboard.superAdmin') }}
            </el-tag>
          </p>
        </div>
      </header>

      <!-- 统计卡：加载中给骨架屏，避免 12 张卡 + 2 个图表同时弹入造成布局抖动 -->
      <!--
        指标卡**按权限同步渲染**，不等接口。
        卡片的可见性由「当前账号是否有对应的菜单权限节点」决定（守卫已在路由进入前
        拉好 profile，hasAuth 是同步的），因此这张网格从第一帧起就是最终布局；
        接口回来后只是把占位的「—」换成数字。
        原先按「后端是否下发该键」过滤，会让 12 张卡在数据到达瞬间从 0 张撑开，
        把下方图表与收藏整体下推 —— 那是工作台 CLS 的主要来源。
      -->
      <div class="cards">
        <!-- 第一张是「主指标」：跨两列 + 主色图标，其余用中性色。
             原来 12 张卡 12 种颜色，等于把层级交给彩虹，谁都不突出。 -->
        <el-card
          v-for="(c, index) in cards"
          :key="c.key"
          shadow="never"
          class="card"
          :class="{ 'is-primary': index === 0, 'is-clickable': !!c.path }"
          @click="c.path && goto(c.path)"
        >
          <div class="card-inner">
            <div class="card-icon">
              <el-icon :size="20"><ArtIcon :name="c.icon" /></el-icon>
            </div>
            <div class="card-body">
              <div class="card-value">{{ c.value ?? '—' }}</div>
              <div class="card-label">{{ c.label }}</div>
            </div>
            <!-- 有对应菜单才给跳转暗示：数字天然可点，不能点就别装作能点 -->
            <el-icon v-if="c.path" :size="14" class="card-arrow"><i class="ri-arrow-right-up-line" /></el-icon>
          </div>
        </el-card>
      </div>

      <!--
        图表卡同理：卡片骨架与真实图表共用 .chart 的固定高度（288px），
        列的存在与否只取决于附件列表页权限（hasPie，同步可知），
        因此加载前后这一行的尺寸完全一致，不产生位移。
      -->
      <el-row :gutter="16">
        <el-col :xs="24" :lg="hasPie ? 15 : 24">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('dashboard.trendTitle') }}</span></template
            >
            <el-skeleton v-if="!stats" animated :rows="5" class="chart" />
            <div v-else ref="trendRef" class="chart" />
          </el-card>
        </el-col>
        <el-col v-if="hasPie" :xs="24" :lg="9">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('dashboard.pieTitle') }}</span></template
            >
            <el-skeleton v-if="!stats" animated :rows="5" class="chart" />
            <div v-else ref="pieRef" class="chart" />
          </el-card>
        </el-col>
      </el-row>

      <!-- 我的收藏：与顶栏快捷导航共用同一份收藏（store 层同步），可拖拽排序。
           数据源是当前账号可见菜单，天然不含无权限的入口。 -->
      <el-card shadow="never" class="chart-card shortcut-card">
        <template #header
          ><span>{{ t('dashboard.favoriteTitle') }}</span></template
        >
        <el-empty v-if="shortcuts.length === 0" :description="t('dashboard.favoriteEmpty')" :image-size="80" />
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
import type { ECharts } from 'echarts/core'
import ArtIcon from '@/components/core/ArtIcon.vue'
import { dashboardStats, type DashboardStats } from '@/api/dashboard'
import { useMenuStore } from '@/stores/menu'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

/**
 * echarts 延迟加载（原先在模块顶层静态 import，登录后首屏必下 532KB / gzip 182KB）。
 *
 * 工作台的首屏内容（欢迎卡 + 指标卡 + 收藏）完全不依赖图表：先让这些内容渲染，
 * 图表库在 `load()` 拿到数据后才按需拉取，既不抢首屏带宽，也不做无谓的模块初始化。
 * 按需注册的模块清单在 `@/utils/echarts` 里（用静态具名导入，保证能 tree-shake）。
 */
type EChartsModule = (typeof import('@/utils/echarts'))['default']

let echartsTask: Promise<EChartsModule | null> | null = null

/** 懒加载图表库（并发调用只会真正执行一次） */
function ensureEcharts(): Promise<EChartsModule | null> {
  echartsTask ??= import('@/utils/echarts')
    .then((mod) => mod.default)
    .catch(() => {
      // 图表库加载失败不影响页面其它部分
      return null
    })
  return echartsTask
}

const { t, locale } = useI18n({ useScope: 'global' })

const router = useRouter()
const menuStore = useMenuStore()
const userStore = useUserStore()
const setting = useSettingStore()

const stats = ref<DashboardStats | null>(null)
const trendRef = ref<HTMLElement>()
const pieRef = ref<HTMLElement>()

let trendChart: ECharts | null = null
let pieChart: ECharts | null = null

const avatarText = computed(() => (userStore.nickname || 'U').charAt(0).toUpperCase())

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 6) {
    return t('dashboard.greetingNight')
  }
  if (hour < 12) {
    return t('dashboard.greetingMorning')
  }
  if (hour < 18) {
    return t('dashboard.greetingAfternoon')
  }
  return t('dashboard.greetingEvening')
})

// 称谓与问候语拼成整句交给语言包（不同语言的标点与语序不同）
const helloText = computed(() =>
  t('dashboard.helloTitle', { greeting: greeting.value, name: userStore.nickname || t('dashboard.admin') }),
)

const todayText = computed(() => {
  const now = new Date()
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return t('dashboard.dateText', { date, week: t(`dashboard.weekday${now.getDay()}`) })
})

/* ---- 指标卡 ---- */

/**
 * 指标键 → 菜单路径末段。
 * 卡片键是后端下发的统计键，菜单路径是后台配置的自由值，两者不同名，需要映射一次。
 */
const MENU_HINT: Record<string, string> = {
  user: 'user',
  role: 'role',
  dept: 'dept',
  post: 'post',
  file: 'file',
  today_log: 'log',
  online: 'online',
  notice_unread: 'notice',
  dict: 'dict',
  crontab: 'crontab',
  data_rule: 'data_rule',
  tenant: 'tenant',
}

/**
 * 找到该指标对应的可跳转菜单；没有就不给跳转（卡片降级为纯展示）。
 * 用「末段完全相等」而不是 includes —— 否则 `user` 会误命中 `data_rule` 之类的子串。
 */
function menuPathOf(key: string): string | undefined {
  const hint = MENU_HINT[key]
  if (!hint) {
    return undefined
  }
  const hit = menuStore.flatMenus.find((item) => {
    const segments = item.path.split('/').filter(Boolean)
    return segments[segments.length - 1] === hint
  })
  return hit?.path
}

interface StatCard {
  key: string
  label: string
  /** 统计值：接口未返回（或还没返回）时为 undefined，卡片显示占位符 */
  value: number | undefined
  icon: string
  /** 对应菜单路径（没有权限入口时为 undefined） */
  path: string | undefined
}

/**
 * 指标 → 后端下发该统计值时校验的权限节点。
 *
 * 必须与 `backend/src/cccms/api/dashboard.py` 里 `add(key, slug, value)` 的 slug 一致：
 * 前端据此**在接口返回前**就能算出卡片网格的最终形态，布局因此不会跳变。
 * tenant（仅超管）与 notice_unread（登录即见）不在这里，见 visibleByAuth()。
 */
const CARD_NODE: Record<string, string> = {
  user: 'cccms:user:index',
  role: 'cccms:role:index',
  dept: 'cccms:dept:tree',
  post: 'cccms:post:index',
  file: 'cccms:file:index',
  today_log: 'cccms:log:index',
  online: 'cccms:online:index',
  dict: 'cccms:dict:index',
  crontab: 'cccms:crontab:index',
  data_rule: 'cccms:data_rule:index',
}

/** 当前账号是否该看到这张卡（纯权限判断，与接口数据无关） */
function visibleByAuth(key: string): boolean {
  if (key === 'tenant') {
    // 租户总数只对超管下发
    return userStore.superAdmin
  }
  if (key === 'notice_unread') {
    // 未读公告与权限无关，登录即可见
    return true
  }
  const node = CARD_NODE[key]
  return !node || userStore.hasAuth(node)
}

const cards = computed<StatCard[]>(() => {
  const c = stats.value?.counts

  // 列表本身恒定存在（顺序不变），只有值随接口填充；
  // 是否显示由 visibleByAuth() 在下面统一按权限过滤（超管直通，永远拿全）。
  const all: StatCard[] = [
    { key: 'user', label: t('dashboard.statUser'), value: c?.user, icon: 'icon-user', path: menuPathOf('user') },
    { key: 'role', label: t('dashboard.statRole'), value: c?.role, icon: 'icon-safe', path: menuPathOf('role') },
    { key: 'dept', label: t('dashboard.statDept'), value: c?.dept, icon: 'icon-tree', path: menuPathOf('dept') },
    { key: 'post', label: t('dashboard.statPost'), value: c?.post, icon: 'icon-badge', path: menuPathOf('post') },
    { key: 'file', label: t('dashboard.statFile'), value: c?.file, icon: 'icon-upload', path: menuPathOf('file') },
    {
      key: 'today_log',
      label: t('dashboard.statTodayLog'),
      value: c?.today_log,
      icon: 'icon-file',
      path: menuPathOf('today_log'),
    },
    {
      key: 'online',
      label: t('dashboard.statOnline'),
      value: c?.online,
      icon: 'icon-monitor',
      path: menuPathOf('online'),
    },
    {
      key: 'notice_unread',
      label: t('dashboard.statNoticeUnread'),
      value: c?.notice_unread,
      icon: 'icon-tickets',
      path: menuPathOf('notice_unread'),
    },
    { key: 'dict', label: t('dashboard.statDict'), value: c?.dict, icon: 'icon-book', path: menuPathOf('dict') },
    {
      key: 'crontab',
      label: t('dashboard.statCrontab'),
      value: c?.crontab,
      icon: 'icon-clock',
      path: menuPathOf('crontab'),
    },
    {
      key: 'data_rule',
      label: t('dashboard.statDataRule'),
      value: c?.data_rule,
      icon: 'icon-key',
      path: menuPathOf('data_rule'),
    },
    {
      key: 'tenant',
      label: t('dashboard.statTenant'),
      value: c?.tenant,
      icon: 'icon-management',
      path: menuPathOf('tenant'),
    },
  ]

  // 可见性只看权限节点：接口数据只负责把「—」换成数字，不影响布局
  return all.filter((item) => visibleByAuth(item.key))
})

/** 饼图是否出现：只看附件列表页权限（同步可知，避免加载完成后列宽跳变） */
const hasPie = computed(() => userStore.hasAuth('cccms:file:index'))

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

function renderTrend(lib: EChartsModule): void {
  if (!trendRef.value || !stats.value) {
    return
  }
  trendChart ??= lib.init(trendRef.value)

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
        name: t('dashboard.trendSeries'),
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

function renderPie(lib: EChartsModule): void {
  if (!pieRef.value || !stats.value) {
    return
  }
  pieChart ??= lib.init(pieRef.value)

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
        data: types.length ? types : [{ name: t('table.empty'), value: 1 }],
      },
    ],
  })
}

async function renderAll(): Promise<void> {
  if (!stats.value) {
    return
  }
  const lib = await ensureEcharts()
  if (!lib) {
    return
  }
  renderTrend(lib)
  renderPie(lib)
}

function resizeAll(): void {
  trendChart?.resize()
  pieChart?.resize()
}

/** resize 合并到一帧内：拖拽窗口时原先是每个事件都同步重算图表，主线程被打满 */
let resizeFrame = 0
function onWindowResize(): void {
  if (resizeFrame) {
    return
  }
  resizeFrame = window.requestAnimationFrame(() => {
    resizeFrame = 0
    resizeAll()
  })
}

async function load(): Promise<void> {
  stats.value = await dashboardStats()
  await nextTick()
  await renderAll()
}

// 主题或语言切换后图表文案 / 配色要跟着变（echarts 是命令式渲染，需手动重绘）
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  await renderAll()
})

onMounted(async () => {
  window.addEventListener('resize', onWindowResize)
  await load()
})

// keep-alive 缓存后重新进入，容器尺寸可能已变
onActivated(() => {
  nextTick(resizeAll)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onWindowResize)
  if (resizeFrame) {
    window.cancelAnimationFrame(resizeFrame)
    resizeFrame = 0
  }
  trendChart?.dispose()
  pieChart?.dispose()
  trendChart = null
  pieChart = null
})
</script>

<style scoped>
/* ---- 页头 ---- */
.page-avatar {
  flex-shrink: 0;
  font-size: 18px;
  background: var(--art-primary);
}

.page-head-text {
  min-width: 0;
}

/* ---- 统计卡 ---- */
.cards {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: var(--art-sp-4);
  margin-bottom: var(--art-sp-4);
}

.card {
  /* 主指标跨两列：同一套 DOM，靠 span 表达优先级 */
  transition:
    box-shadow 0.15s ease,
    border-color 0.15s ease,
    transform 0.15s ease;
}

.card.is-primary {
  grid-column: span 2;
}

.card.is-clickable {
  cursor: pointer;
}

.card.is-clickable:hover {
  border-color: var(--art-primary);
  box-shadow: var(--art-shadow-2);
  transform: translateY(-1px);
}

.card-inner {
  display: flex;
  gap: var(--art-sp-3);
  align-items: center;
}

/* 图标统一中性色，只有主指标着色 —— 单一强调色原则 */
.card-icon {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  color: var(--art-sub);
  background: var(--art-hover-bg);
  border-radius: var(--art-radius-sm);
}

.card.is-primary .card-icon {
  color: var(--art-primary);
  background: var(--el-color-primary-light-9);
}

.card-body {
  min-width: 0;
}

.card-value {
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
  color: var(--art-main);
}

.card.is-primary .card-value {
  font-size: 26px;
  color: var(--art-primary-text);
}

.card-label {
  overflow: hidden;
  font-size: var(--art-fs-caption);
  line-height: var(--art-lh-caption);
  color: var(--art-muted);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-arrow {
  flex-shrink: 0;
  margin-left: auto;
  color: var(--art-muted);
  opacity: 0;
  transition: opacity 0.15s ease;
}

.card.is-clickable:hover .card-arrow {
  opacity: 1;
}

/* ---- 图表 ---- */
.chart-card :deep(.el-card__body) {
  padding: var(--art-sp-2) var(--art-sp-3) var(--art-sp-3);
}

/* 图表区固定高度：骨架（el-skeleton 也套这个类）与真实图表共用同一尺寸 */
.chart {
  width: 100%;
  height: 288px;
  overflow: hidden;
}

/* el-row 的列之间有 gutter，行下方需要自己补间距 */
:deep(.el-row) {
  margin-bottom: var(--art-sp-4);
}

/* ---- 常用功能 ----
 * 与上方卡片的间距由 .cards / .el-row 的下外边距提供，这里不再叠加，
 * 否则 16 + 16 会变成 32px 的「断层」。
 */
.shortcut-card :deep(.el-card__body) {
  padding-top: var(--art-sp-3);
}

.shortcuts {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: var(--art-sp-3);
}

.shortcut {
  display: flex;
  gap: var(--art-sp-2);
  align-items: center;
  padding: var(--art-sp-3);
  cursor: pointer;
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius-sm);
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
  font-size: var(--art-fs-dense);
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

  .card.is-primary {
    grid-column: span 3;
  }

  .shortcuts {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .cards {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .card.is-primary {
    grid-column: span 2;
  }

  .chart {
    height: 240px;
  }

  .shortcuts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
