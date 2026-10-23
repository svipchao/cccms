<template>
  <div class="art-fill job-panel">
    <!-- 统计卡：状态计数 + 消费者心跳（心跳停了说明队列进程没起，作业只会堆积） -->
    <!-- 没有统计权限时整块不渲染：显示一排「—」会让人以为队列挂了 -->
    <div v-if="canStats" class="job-cards">
      <div v-for="card in cards" :key="card.key" class="job-card">
        <span class="job-card__label">{{ t(card.labelKey) }}</span>
        <b :class="card.tone">{{ card.value }}</b>
      </div>
      <div class="job-card">
        <span class="job-card__label">{{ t('cccms.job.heartbeat') }}</span>
        <el-tooltip :content="t('cccms.job.heartbeatTip')" placement="top">
          <b :class="heartbeat.stale ? 'is-bad' : 'is-ok'">{{ heartbeat.text }}</b>
        </el-tooltip>
      </div>
    </div>

    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      selection
      storage-key="cccms-job-index"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="refresh"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
      <template #toolbar>
        <el-button
          v-auth="'cccms:job:remove'"
          type="danger"
          plain
          :disabled="selection.length === 0"
          @click="onBatchRemove"
        >
          <template #icon><i class="ri-delete-bin-line" /></template>
          {{ t('cccms.job.deleteSelected') }}{{ selection.length ? `（${selection.length}）` : '' }}
        </el-button>
        <span class="toolbar-tip">{{ t('cccms.job.tabTip') }}</span>
      </template>

      <template #target="{ row }">
        <el-tooltip :content="row.target" placement="top">
          <span class="job-target">{{ row.target_label }}</span>
        </el-tooltip>
      </template>

      <template #status="{ row }">
        <el-tag :type="statusMeta(row.status).type" effect="light" size="small">
          {{ t(statusMeta(row.status).labelKey) }}
        </el-tag>
      </template>

      <template #attempts="{ row }">
        <span :class="{ 'is-bad': row.attempts >= row.max_attempts && row.status !== 2 }">
          {{ row.attempts }} / {{ row.max_attempts }}
        </span>
      </template>

      <template #last_error="{ row }">
        <span v-if="row.last_error" class="job-error">{{ row.last_error }}</span>
        <span v-else class="job-none">{{ t('cccms.job.detailNone') }}</span>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:job:logs'" link type="primary" @click="openLogs(row)">
          {{ t('cccms.job.logs') }}
        </el-button>
        <el-popconfirm v-if="row.status !== 2" :title="t('cccms.job.retryConfirm')" @confirm="onRetry(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:job:retry'" link type="primary">{{ t('cccms.job.retry') }}</el-button>
          </template>
        </el-popconfirm>
        <el-popconfirm
          v-if="row.status === 2 || row.status === 4"
          :title="t('cccms.job.deleteConfirm')"
          @confirm="onRemove([row.id])"
        >
          <template #reference>
            <el-button v-auth="'cccms:job:remove'" link type="danger">{{ t('core.common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 执行日志：上方是作业本身（在替谁干活），下方是每次尝试的结果 -->
    <el-drawer v-model="logVisible" :title="t('cccms.job.logsTitle', { id: currentJob?.id ?? '' })" size="760px">
      <div v-loading="logLoading" class="job-log">
        <el-descriptions :column="2" size="small" border>
          <el-descriptions-item :label="t('cccms.job.detailTarget')" :span="2">
            <span class="job-break">{{ currentJob?.target || t('cccms.job.detailNone') }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailQueue')">
            {{ currentJob?.queue || t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.statusLabel')">
            <el-tag :type="statusMeta(currentJob?.status ?? 0).type" effect="light" size="small">
              {{ t(statusMeta(currentJob?.status ?? 0).labelKey) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailAttempts')">
            {{ currentJob ? `${currentJob.attempts} / ${currentJob.max_attempts}` : t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailUniqueKey')">
            {{ currentJob?.unique_key || t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailCreated')">
            {{ currentJob?.create_time || t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailFinished')">
            {{ currentJob?.finished_at || t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailAvailable')">
            {{ currentJob?.available_at || t('cccms.job.detailNone') }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.job.detailReserved')">
            {{ currentJob?.reserved_at || t('cccms.job.detailNone') }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="job-log-section">
          <div class="job-log-label">{{ t('cccms.job.detailPayload') }}</div>
          <pre class="job-pre">{{ payloadText }}</pre>
        </div>

        <div v-if="currentJob?.last_error" class="job-log-section">
          <div class="job-log-label">{{ t('cccms.job.detailLastError') }}</div>
          <pre class="job-pre job-pre--error">{{ currentJob.last_error }}</pre>
        </div>

        <div class="job-log-label job-log-label--table">{{ t('cccms.job.logs') }}</div>
        <el-table :data="logs" size="small" border row-key="id">
          <template #empty>{{ t('cccms.job.logEmpty') }}</template>
          <el-table-column width="110" :label="t('cccms.job.logTime')">
            <template #default="{ row }">{{ row.create_time }}</template>
          </el-table-column>
          <el-table-column width="110" align="center" :label="t('cccms.job.logResult')">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'danger'" effect="light" size="small">
                {{ row.status === 1 ? t('cccms.job.logSuccess') : t('cccms.job.logFailed') }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column width="100" align="center" :label="t('cccms.job.logAttemptLabel')">
            <template #default="{ row }">{{ t('cccms.job.logAttempt', { n: row.attempt }) }}</template>
          </el-table-column>
          <el-table-column prop="cost" width="110" align="right" :label="t('cccms.job.logCost')" />
          <el-table-column :label="t('cccms.job.logOutput')" min-width="260">
            <template #default="{ row }">
              <span class="job-break">{{ row.output || t('cccms.job.detailNone') }}</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:job' })

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { useUserStore } from '@/stores/user'
import { jobList, jobLogs, jobRemove, jobRetry, jobStats, type JobLogRow, type JobRow, type JobStats } from '@/api/job'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

interface Query {
  queue: string
  /** 多选值以逗号串传递，后端 FilterInput::ints 负责拆 */
  status: string
  target: string
}

const userStore = useUserStore()
/** 统计与列表是两个节点：只给「看列表」时不请求统计，避免进 tab 就弹一个 403 */
const canStats = computed(() => userStore.hasAuth('cccms:job:stats'))

const stats = ref<JobStats | null>(null)

type TagType = 'primary' | 'success' | 'warning' | 'danger' | 'info'

/** 状态标签：0 待执行 / 1 执行中 / 2 成功 / 3 失败待重试 / 4 死信 */
function statusMeta(status: number): { type: TagType; labelKey: string } {
  switch (status) {
    case 1:
      return { type: 'warning', labelKey: 'cccms.job.statusRunning' }
    case 2:
      return { type: 'success', labelKey: 'cccms.job.statusDone' }
    case 3:
      return { type: 'danger', labelKey: 'cccms.job.statusRetry' }
    case 4:
      return { type: 'info', labelKey: 'cccms.job.statusDead' }
    default:
      return { type: 'primary', labelKey: 'cccms.job.statusPending' }
  }
}

const statusOptions = computed(() => [
  { label: t('cccms.job.statusPending'), value: 0 },
  { label: t('cccms.job.statusRunning'), value: 1 },
  { label: t('cccms.job.statusDone'), value: 2 },
  { label: t('cccms.job.statusRetry'), value: 3 },
  { label: t('cccms.job.statusDead'), value: 4 },
])

const queueOptions = computed(() => (stats.value?.queues ?? []).map((queue) => ({ label: queue, value: queue })))

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 90, sortable: 'custom' },
  {
    prop: 'queue',
    label: t('cccms.job.queueLabel'),
    width: 120,
    // 队列名来自实际数据（export / default…），没有数据就不出筛选入口
    filter: queueOptions.value.length ? { type: 'enum', options: queueOptions.value } : undefined,
  },
  {
    prop: 'target',
    label: t('cccms.job.targetLabel'),
    minWidth: 170,
    slot: 'target',
    filter: { type: 'text', placeholder: t('cccms.job.targetFilter') },
  },
  {
    prop: 'status',
    label: t('cccms.job.statusLabel'),
    width: 130,
    align: 'center',
    slot: 'status',
    filter: { type: 'enum', options: statusOptions.value },
  },
  {
    prop: 'attempts',
    label: t('cccms.job.attempts'),
    width: 100,
    align: 'center',
    slot: 'attempts',
    sortable: 'custom',
  },
  { prop: 'available_at', label: t('cccms.job.availableAt'), width: 170, sortable: 'custom' },
  { prop: 'create_time', label: t('cccms.job.createdAt'), width: 170, sortable: 'custom' },
  {
    prop: 'last_error',
    label: t('cccms.job.lastError'),
    minWidth: 200,
    slot: 'last_error',
    showOverflowTooltip: true,
  },
  { prop: 'action', label: t('core.table.action'), width: 190, fixed: 'right', slot: 'action', lockVisible: true },
])

const { list, loading, total, page, limit, selection, load, onPageChange, onLimitChange, onSelectionChange } = useTable<
  JobRow,
  Query
>({
  api: jobList,
  initialQuery: { queue: '', status: '', target: '' },
})

/** 统计卡：与列表分开加载 —— 没有统计权限的角色仍能看列表 */
const cards = computed(() => {
  const s = stats.value
  return [
    { key: 'pending', labelKey: 'cccms.job.statsPending', value: s?.pending ?? '—', tone: '' },
    { key: 'running', labelKey: 'cccms.job.statsRunning', value: s?.running ?? '—', tone: '' },
    { key: 'retry', labelKey: 'cccms.job.statsRetry', value: s?.retry ?? '—', tone: s?.retry ? 'is-bad' : '' },
    { key: 'dead', labelKey: 'cccms.job.statsDead', value: s?.dead ?? '—', tone: s?.dead ? 'is-bad' : '' },
    { key: 'total', labelKey: 'cccms.job.statsTotal', value: s?.total ?? '—', tone: '' },
  ]
})

const heartbeat = computed(() => {
  const age = stats.value?.heartbeat_age
  if (age === null || age === undefined) {
    return { stale: true, text: t('cccms.job.heartbeatNever') }
  }
  // 超过 60 秒没心跳基本可以认定消费进程没在跑
  return { stale: age > 60, text: t('cccms.job.heartbeatAgo', { n: age }) }
})

async function loadStats(): Promise<void> {
  if (!canStats.value) {
    return
  }
  try {
    stats.value = await jobStats()
  } catch {
    // 接口异常时保留上一次数据，卡片显示「—」即可（提示已由拦截器给出）
  }
}

function refresh(): void {
  void load()
  void loadStats()
}

onMounted(() => {
  void loadStats()
})

/* ---- 执行日志 ---- */
const logVisible = ref(false)
const logLoading = ref(false)
const currentJob = ref<JobRow | null>(null)
const logs = ref<JobLogRow[]>([])

const payloadText = computed(() => {
  const payload = currentJob.value?.payload
  if (!payload || Object.keys(payload).length === 0) {
    return t('cccms.job.detailNone')
  }
  return JSON.stringify(payload, null, 2)
})

async function openLogs(row: JobRow): Promise<void> {
  logVisible.value = true
  logLoading.value = true
  currentJob.value = row
  logs.value = []
  try {
    const res = await jobLogs(row.id)
    currentJob.value = res.job
    logs.value = res.list ?? []
  } catch {
    // 拦截器已提示
  } finally {
    logLoading.value = false
  }
}

/* ---- 重试 / 删除 ---- */
async function onRetry(id: number): Promise<void> {
  try {
    await jobRetry(id)
    ElMessage.success(t('core.common.success'))
    refresh()
  } catch {
    // 失败提示由拦截器统一给出（如「已完成，无需重试」）
  }
}

async function onRemove(ids: number[]): Promise<void> {
  try {
    const res = await jobRemove(ids)
    ElMessage.success(t('cccms.job.deleteSuccess', { count: res.deleted }))
    refresh()
  } catch {
    // 同上：所选作业尚未结束时后端会给出原因
  }
}

async function onBatchRemove(): Promise<void> {
  if (selection.value.length === 0) {
    ElMessage.warning(t('cccms.job.deleteNone'))
    return
  }

  try {
    await ElMessageBox.confirm(t('cccms.job.deleteConfirm'), t('core.common.tip'), { type: 'warning' })
  } catch {
    return
  }

  await onRemove(selection.value.map((row) => row.id))
}
</script>

<style scoped>
.job-panel {
  /* 作为父级 .art-fill 的 flex 子项：吃掉切换条剩下的全部高度 */
  flex: 1;
  min-height: 0;
  gap: 12px;
}

/* ---- 统计卡 ---- */
.job-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
}

.job-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 12px 14px;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
}

.job-card__label {
  font-size: var(--el-font-size-extra-small);
  color: var(--art-sub);
}

.job-card b {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--art-main);
}

.job-card b.is-ok {
  color: var(--el-color-success);
}

.job-card b.is-bad {
  color: var(--el-color-danger);
}

/* ---- 单元格 ---- */
.job-target {
  font-family: var(--el-font-family-mono, monospace);
  font-size: var(--el-font-size-extra-small);
  color: var(--art-main);
}

.job-error {
  font-size: var(--el-font-size-extra-small);
  color: var(--el-color-danger);
}

.job-none {
  color: var(--art-muted);
}

.toolbar-tip {
  margin-left: 8px;
  font-size: var(--el-font-size-extra-small);
  color: var(--art-sub);
}

/* ---- 日志抽屉 ---- */
.job-log {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.job-log-section {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.job-log-label {
  font-size: var(--el-font-size-extra-small);
  font-weight: 600;
  color: var(--art-sub);
}

.job-log-label--table {
  margin-top: 4px;
}

.job-pre {
  margin: 0;
  padding: 10px 12px;
  max-height: 220px;
  overflow: auto;
  font-family: var(--el-font-family-mono, monospace);
  font-size: var(--el-font-size-extra-small);
  white-space: pre-wrap;
  word-break: break-all;
  background: var(--art-hover-bg);
  border-radius: var(--art-radius);
}

.job-pre--error {
  color: var(--el-color-danger);
}

.job-break {
  word-break: break-all;
}
</style>
