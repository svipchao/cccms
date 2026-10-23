<template>
  <div class="art-fill term">
    <div class="term__head">
      <div class="term__title-wrap">
        <h2>{{ t('cccms.terminal.title') }}</h2>
        <p>
          {{
            t('cccms.terminal.subtitle', {
              timeout: catalog?.timeout ?? 0,
              limit: catalog?.output_limit ?? 0,
            })
          }}
        </p>
      </div>
      <div class="term__tools">
        <el-button :loading="loading" size="small" @click="load">
          {{ t('cccms.terminal.refresh') }}
        </el-button>
      </div>
    </div>

    <el-alert
      v-if="catalog && !catalog.enabled"
      class="term__alert"
      type="warning"
      :closable="false"
      show-icon
      :title="t('cccms.terminal.disabledTip')"
    />

    <div class="term__body">
      <aside class="term__side">
        <template v-for="group in groups" :key="group.key">
          <div class="term__group">{{ t(`cccms.terminal.group.${group.key}`) }}</div>
          <button
            v-for="command in group.items"
            :key="command.key"
            type="button"
            class="term__cmd"
            :class="{ 'is-active': current?.key === command.key }"
            @click="select(command)"
          >
            <span class="term__cmd-name">{{ command.label }}</span>
            <el-tag
              v-if="command.risk > 1"
              :type="command.risk === 3 ? 'danger' : 'warning'"
              size="small"
              effect="plain"
            >
              {{ t(`cccms.terminal.risk${command.risk}`) }}
            </el-tag>
          </button>
        </template>
        <el-empty v-if="!groups.length" :image-size="60" :description="t('cccms.terminal.selectTip')" />
      </aside>

      <main class="term__main">
        <template v-if="current">
          <div class="term__cmd-head">
            <b>{{ current.label }}</b>
            <el-tag v-if="current.risk > 1" :type="current.risk === 3 ? 'danger' : 'warning'" size="small">
              {{ t(`cccms.terminal.risk${current.risk}`) }}
            </el-tag>
          </div>
          <p class="term__desc">{{ current.description }}</p>
          <code class="term__argv">{{ commandLine }}</code>

          <el-form v-if="current.params.length" label-position="top" class="term__form">
            <el-form-item v-for="param in current.params" :key="param.name" :label="param.label">
              <el-checkbox v-if="param.type === 'bool'" v-model="boolForm[param.name]" />
              <el-input-number
                v-else-if="param.type === 'int'"
                v-model="intForm[param.name]"
                class="term__number"
                :min="param.min ?? undefined"
                :max="param.max ?? undefined"
                :controls="false"
                :placeholder="param.required ? t('cccms.terminal.required') : ''"
              />
              <el-input
                v-else
                v-model="textForm[param.name]"
                :maxlength="param.max_length ?? 120"
                clearable
                :placeholder="param.required ? t('cccms.terminal.required') : ''"
              />
            </el-form-item>
            <div class="term__hint">{{ t('cccms.terminal.paramsHint') }}</div>
          </el-form>
          <div v-else class="term__hint">{{ t('cccms.terminal.emptyParams') }}</div>

          <div v-if="needsConfirm" class="term__confirm">
            <el-alert
              type="error"
              :closable="false"
              show-icon
              :title="t('cccms.terminal.confirmTip', { key: current.key })"
            />
            <el-input v-model="confirmText" class="term__confirm-input" :placeholder="current.key" />
          </div>

          <div class="term__actions">
            <el-tooltip :disabled="!dangerBlocked" :content="t('cccms.terminal.dangerForbidden')" placement="top">
              <span>
                <el-button
                  v-auth="'cccms:terminal:run'"
                  type="primary"
                  :loading="submitting"
                  :disabled="!canSubmit"
                  @click="run"
                >
                  {{ submitting ? t('cccms.terminal.running') : t('cccms.terminal.run') }}
                </el-button>
              </span>
            </el-tooltip>
            <span class="term__hint">{{ t('cccms.terminal.queueHint') }}</span>
          </div>
        </template>
        <el-empty v-else :description="t('cccms.terminal.selectTip')" />
      </main>
    </div>

    <section v-if="canHistory" class="term__history">
      <div class="term__history-head">
        <b>{{ t('cccms.terminal.history') }}</b>
        <small>{{ t('cccms.terminal.historyHint') }}</small>
      </div>
      <el-table :data="history" size="small" height="220" @row-click="onRowClick">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column :label="t('cccms.terminal.command')" min-width="150">
          <template #default="{ row }">{{ commandLabel(row.payload?.key) }}</template>
        </el-table-column>
        <el-table-column :label="t('cccms.terminal.params')" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ paramsText(row.payload?.params) }}</template>
        </el-table-column>
        <el-table-column :label="t('cccms.terminal.status')" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="statusMeta(row.status).type" size="small" effect="light">
              {{ t(statusMeta(row.status).labelKey) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('cccms.terminal.createdAt')" width="170" />
        <el-table-column v-if="canLogs" :label="t('cccms.terminal.action')" width="120" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click.stop="openOutput(row.id)">
              {{ t('cccms.terminal.viewOutput') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-drawer
      v-model="outputVisible"
      :title="t('cccms.terminal.outputTitle', { id: outputJob?.id ?? 0 })"
      size="640px"
    >
      <div class="term__out-head">
        <el-tag v-if="outputJob" :type="statusMeta(outputJob.status).type" size="small">
          {{ t(statusMeta(outputJob.status).labelKey) }}
        </el-tag>
        <small v-if="polling">{{ t('cccms.terminal.waiting') }}</small>
      </div>
      <pre class="term__output">{{ outputText || t('cccms.terminal.noOutput') }}</pre>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:terminal' })

import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import {
  terminalCatalog,
  terminalHistory,
  terminalLogs,
  terminalRun,
  type TerminalCatalog,
  type TerminalCommand,
  type TerminalJobRow,
} from '@/api/terminal'
import { useUserStore } from '@/stores/user'

const { t } = useI18n({ useScope: 'global' })
const userStore = useUserStore()

/** 历史与输出是独立节点：只给「看命令」时不该在进页面就弹两个 403 */
const canHistory = computed(() => userStore.hasAuth('cccms:terminal:history'))
const canLogs = computed(() => userStore.hasAuth('cccms:terminal:logs'))
const canDanger = computed(() => userStore.hasAuth('cccms:terminal:danger'))

const catalog = ref<TerminalCatalog | null>(null)
const history = ref<TerminalJobRow[]>([])
const current = ref<TerminalCommand | null>(null)
const loading = ref(false)
const submitting = ref(false)
const confirmText = ref('')

/** 三种控件各自一个对象：让 `v-model` 的类型保持具体，而不是一个 `any` 大杂烩 */
const boolForm = reactive<Record<string, boolean>>({})
const intForm = reactive<Record<string, number | undefined>>({})
const textForm = reactive<Record<string, string>>({})

const groups = computed(() => {
  const map = new Map<string, TerminalCommand[]>()
  for (const command of catalog.value?.commands ?? []) {
    const list = map.get(command.group) ?? []
    list.push(command)
    map.set(command.group, list)
  }

  return [...map.entries()].map(([key, items]) => ({ key, items }))
})

const commandLine = computed(() => {
  if (!current.value) {
    return ''
  }

  return current.value.argv.join(' ').replace('{php}', 'php').replace('{root}', '.')
})

const needsConfirm = computed(() => (current.value?.risk ?? 1) >= 2)

/** 有权限之外的第二种拦截：没手输 key 之前按钮不可点（后端还会再校验一次） */
const dangerBlocked = computed(
  () => needsConfirm.value && (!canDanger.value || confirmText.value !== current.value?.key),
)

const canSubmit = computed(() => !submitting.value && (catalog.value?.enabled ?? false) && !dangerBlocked.value)

function clearForm(): void {
  for (const key of Object.keys(boolForm)) {
    delete boolForm[key]
  }
  for (const key of Object.keys(intForm)) {
    delete intForm[key]
  }
  for (const key of Object.keys(textForm)) {
    delete textForm[key]
  }
}

function select(command: TerminalCommand): void {
  current.value = command
  confirmText.value = ''
  clearForm()
  for (const param of command.params) {
    if (param.type === 'bool') {
      boolForm[param.name] = false
    } else if (param.type === 'int') {
      intForm[param.name] = undefined
    } else {
      textForm[param.name] = ''
    }
  }
}

/** 只提交有值的参数：留空的开关不该以 `--days=` 的形式传给命令行 */
function collectParams(): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = {}
  for (const param of current.value?.params ?? []) {
    if (param.type === 'bool') {
      if (boolForm[param.name]) {
        params[param.name] = true
      }
      continue
    }
    const value = param.type === 'int' ? intForm[param.name] : textForm[param.name]
    if (value === undefined || value === '') {
      continue
    }
    params[param.name] = value
  }

  return params
}

async function loadCatalog(): Promise<void> {
  const result = await terminalCatalog()
  catalog.value = result
  if (!current.value && result.commands.length) {
    select(result.commands[0])
  }
}

async function loadHistory(): Promise<void> {
  const result = await terminalHistory()
  history.value = result.list
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const tasks: Promise<unknown>[] = [loadCatalog()]
    if (canHistory.value) {
      tasks.push(loadHistory())
    }
    await Promise.allSettled(tasks)
  } finally {
    loading.value = false
  }
}

async function run(): Promise<void> {
  if (!current.value) {
    return
  }
  submitting.value = true
  try {
    const result = await terminalRun({
      key: current.value.key,
      params: collectParams(),
      confirm: confirmText.value,
    })
    ElMessage.success(t('cccms.terminal.submitted'))
    if (canHistory.value) {
      await loadHistory()
    }
    if (canLogs.value) {
      await openOutput(result.job_id)
    }
  } finally {
    submitting.value = false
  }
}

/* ---- 输出抽屉 + 轮询 ---- */

const outputVisible = ref(false)
const outputJob = ref<TerminalJobRow | null>(null)
const outputText = ref('')
const polling = ref(false)
let timer: number | null = null

function stopPolling(): void {
  if (timer !== null) {
    window.clearInterval(timer)
    timer = null
  }
  polling.value = false
}

/** 返回 true 表示「不用再轮询了」（结束、死信，或拉取失败） */
async function fetchOutput(jobId: number): Promise<boolean> {
  try {
    const result = await terminalLogs(jobId)
    outputJob.value = result.job
    outputText.value = result.list[0]?.output ?? ''

    return result.job.status === 2 || result.job.status === 4
  } catch {
    // 失败原因由拦截器统一提示；这里必须返回 true，否则会每 1.5s 弹一次错误
    return true
  }
}

function startPolling(jobId: number): void {
  stopPolling()
  polling.value = true
  let ticks = 0
  timer = window.setInterval(() => {
    ticks += 1
    void fetchOutput(jobId).then((done) => {
      // 队列进程没起时作业会一直「待执行」：90s 后停掉，别让页面一直转
      if (done || ticks >= 60) {
        stopPolling()
      }
    })
  }, 1500)
}

async function openOutput(jobId: number): Promise<void> {
  outputVisible.value = true
  outputJob.value = null
  outputText.value = ''
  stopPolling()
  if (await fetchOutput(jobId)) {
    return
  }
  startPolling(jobId)
}

function onRowClick(row: TerminalJobRow): void {
  if (canLogs.value) {
    void openOutput(row.id)
  }
}

watch(outputVisible, (visible) => {
  if (!visible) {
    stopPolling()
  }
})

/* ---- 展示辅助 ---- */

function commandLabel(key?: string): string {
  if (!key) {
    return t('core.table.empty')
  }
  const command = catalog.value?.commands.find((item) => item.key === key)

  return command?.label ?? key
}

function paramsText(params?: Record<string, unknown>): string {
  if (!params || !Object.keys(params).length) {
    return '—'
  }

  return Object.entries(params)
    .map(([key, value]) => `${key}=${String(value)}`)
    .join(' ')
}

type TagType = 'primary' | 'success' | 'warning' | 'danger' | 'info'

/** 0 待执行 / 1 执行中 / 2 成功 / 3 失败待重试 / 4 死信（与任务中心一致） */
function statusMeta(status: number): { type: TagType; labelKey: string } {
  switch (status) {
    case 1:
      return { type: 'warning', labelKey: 'cccms.job.statusRunning' }
    case 2:
      return { type: 'success', labelKey: 'cccms.job.statusDone' }
    case 3:
      return { type: 'danger', labelKey: 'cccms.job.statusRetry' }
    case 4:
      return { type: 'danger', labelKey: 'cccms.job.statusDead' }
    default:
      return { type: 'info', labelKey: 'cccms.job.statusPending' }
  }
}

onMounted(() => void load())
onUnmounted(stopPolling)
</script>

<style scoped>
.term {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.term__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.term__title-wrap h2 {
  margin: 0 0 4px;
  font-size: 18px;
}

.term__title-wrap p {
  margin: 0;
  font-size: 12px;
  color: var(--art-secondary);
}

.term__body {
  display: flex;
  flex: 1;
  min-height: 0;
  gap: 12px;
}

.term__side {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 240px;
  flex: none;
  padding: 10px;
  overflow-y: auto;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.term__group {
  padding: 6px 8px 2px;
  font-size: 11px;
  color: var(--art-secondary);
  letter-spacing: 0.04em;
}

.term__cmd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  padding: 7px 10px;
  font-size: 13px;
  color: var(--art-main);
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
}

.term__cmd:hover {
  background: var(--art-hover);
}

.term__cmd.is-active {
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.term__cmd-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.term__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  padding: 14px 16px;
  overflow-y: auto;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.term__cmd-head {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 15px;
}

.term__desc {
  margin: 8px 0;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-secondary);
}

.term__argv {
  display: block;
  padding: 8px 10px;
  overflow-x: auto;
  font-size: 12px;
  color: var(--art-secondary);
  white-space: nowrap;
  background: var(--art-hover);
  border-radius: 6px;
}

.term__form {
  margin-top: 12px;
}

.term__number {
  width: 180px;
}

.term__confirm {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.term__confirm-input {
  width: 240px;
}

.term__actions {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 16px;
}

.term__hint {
  font-size: 11px;
  color: var(--art-secondary);
}

.term__history {
  flex: none;
  padding: 10px 12px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.term__history-head {
  display: flex;
  gap: 10px;
  align-items: baseline;
  margin-bottom: 6px;
}

.term__history-head small {
  font-size: 11px;
  color: var(--art-secondary);
}

.term__out-head {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 8px;
  font-size: 12px;
  color: var(--art-secondary);
}

.term__output {
  margin: 0;
  padding: 12px;
  font-family: var(--art-font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  background: var(--art-hover);
  border-radius: 6px;
}

@media (max-width: 900px) {
  .term__body {
    flex-direction: column;
  }

  .term__side {
    width: 100%;
    max-height: 200px;
  }
}
</style>
