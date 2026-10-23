<template>
  <div class="art-fill backup-page">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      selection
      storage-key="cccms-backup-index"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="onRefresh"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:backup:create'" type="primary" :loading="creating" @click="onCreate">
          <template #icon><i class="ri-database-2-line" /></template>
          {{ creating ? t('cccms.backup.creating') : t('cccms.backup.create') }}
        </el-button>
        <el-button
          v-auth="'cccms:backup:delete'"
          type="danger"
          plain
          :disabled="selection.length === 0"
          @click="onBatchDelete"
        >
          <template #icon><i class="ri-delete-bin-line" /></template>
          {{ t('cccms.backup.deleteSelected') }}{{ selection.length ? `（${selection.length}）` : '' }}
        </el-button>
        <span class="toolbar-tip">{{ t('cccms.backup.engineTip') }}</span>
      </template>

      <template #file="{ row }">
        <span class="backup-file">{{ row.file_name || t('cccms.backup.engineUnknown') }}</span>
      </template>

      <template #kind="{ row }">
        <el-tag :type="kindMeta(row.kind).type" effect="light" size="small">
          {{ t(kindMeta(row.kind).labelKey) }}
        </el-tag>
      </template>

      <template #engine="{ row }">
        <el-tooltip v-if="row.engine === 'pdo'" :content="t('cccms.backup.enginePdoTip')" placement="top">
          <el-tag type="warning" effect="plain" size="small">{{ t('cccms.backup.enginePdo') }}</el-tag>
        </el-tooltip>
        <span v-else-if="row.engine === 'mysqldump'" class="backup-engine">
          {{ t('cccms.backup.engineMysqldump') }}
        </span>
        <span v-else class="backup-none">{{ t('cccms.backup.engineUnknown') }}</span>
      </template>

      <template #size="{ row }">{{ formatSize(row.size) }}</template>

      <template #duration="{ row }">{{ formatDuration(row.duration) }}</template>

      <template #status="{ row }">
        <el-tooltip v-if="row.status === 2 && row.error" :content="row.error" placement="top">
          <el-tag type="danger" effect="light" size="small">{{ t('cccms.backup.statusFailed') }}</el-tag>
        </el-tooltip>
        <el-tag v-else :type="statusMeta(row.status).type" effect="light" size="small">
          {{ t(statusMeta(row.status).labelKey) }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-tooltip :content="t('cccms.backup.downloadTip')" placement="top">
          <el-button
            v-auth="'cccms:backup:download'"
            link
            type="primary"
            :disabled="row.status !== 1"
            @click="onDownload(row)"
          >
            {{ t('cccms.backup.download') }}
          </el-button>
        </el-tooltip>
        <el-button
          v-auth="'cccms:backup:restore'"
          link
          type="danger"
          :disabled="row.status !== 1"
          @click="openRestore(row)"
        >
          {{ t('cccms.backup.restore') }}
        </el-button>
        <el-popconfirm :title="t('cccms.backup.deleteConfirm')" @confirm="onDelete([row.id])">
          <template #reference>
            <el-button v-auth="'cccms:backup:delete'" link type="danger" :disabled="row.status === 0">
              {{ t('core.common.delete') }}
            </el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 还原：破坏性动作，三道确认（警告 + 选表 + 手输库名） -->
    <el-dialog
      v-model="restoreVisible"
      :title="t('cccms.backup.restoreTitle')"
      width="640px"
      :close-on-click-modal="false"
    >
      <el-alert type="error" :closable="false" show-icon :title="t('cccms.backup.restoreWarning')" />

      <div v-loading="tablesLoading" class="restore-form">
        <div class="restore-item">
          <div class="restore-label">{{ t('cccms.backup.restoreTables') }}</div>
          <el-select
            v-model="restoreTables"
            multiple
            filterable
            clearable
            collapse-tags
            collapse-tags-tooltip
            class="restore-select"
            :placeholder="t('cccms.backup.restoreAllTables')"
          >
            <el-option v-for="name in tableOptions" :key="name" :label="name" :value="name" />
          </el-select>
          <div class="restore-tip">{{ t('cccms.backup.restoreTablesTip') }}</div>
        </div>

        <div class="restore-item">
          <div class="restore-label">{{ t('cccms.backup.restoreConfirm') }}</div>
          <el-input v-model="confirmText" :placeholder="database" autocomplete="off" />
          <div class="restore-tip">{{ t('cccms.backup.restoreConfirmTip', { database }) }}</div>
        </div>
      </div>

      <template #footer>
        <el-button @click="restoreVisible = false">{{ t('core.common.cancel') }}</el-button>
        <el-button
          type="danger"
          :loading="restoring"
          :disabled="!database || confirmText !== database"
          @click="submitRestore"
        >
          {{ t('cccms.backup.restoreSubmit') }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:backup' })

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { formatBytes } from '@/utils/format'
import {
  backupCreate,
  backupDelete,
  backupDownload,
  backupList,
  backupRestore,
  backupTables,
  type BackupKind,
  type BackupRow,
} from '@/api/backup'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

type TagType = 'primary' | 'success' | 'warning' | 'danger' | 'info'

interface Query {
  kind: string
  status: string
}

function kindMeta(kind: BackupKind): { type: TagType; labelKey: string } {
  switch (kind) {
    case 'cron':
      return { type: 'warning', labelKey: 'cccms.backup.kindCron' }
    case 'pre_restore':
      return { type: 'info', labelKey: 'cccms.backup.kindPreRestore' }
    default:
      return { type: 'primary', labelKey: 'cccms.backup.kindManual' }
  }
}

function statusMeta(status: number): { type: TagType; labelKey: string } {
  switch (status) {
    case 1:
      return { type: 'success', labelKey: 'cccms.backup.statusDone' }
    case 2:
      return { type: 'danger', labelKey: 'cccms.backup.statusFailed' }
    default:
      return { type: 'warning', labelKey: 'cccms.backup.statusRunning' }
  }
}

const kindOptions = computed(() => [
  { label: t('cccms.backup.kindManual'), value: 'manual' },
  { label: t('cccms.backup.kindCron'), value: 'cron' },
  { label: t('cccms.backup.kindPreRestore'), value: 'pre_restore' },
])

const statusOptions = computed(() => [
  { label: t('cccms.backup.statusRunning'), value: 0 },
  { label: t('cccms.backup.statusDone'), value: 1 },
  { label: t('cccms.backup.statusFailed'), value: 2 },
])

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'file_name', label: t('cccms.backup.fileLabel'), minWidth: 250, slot: 'file', showOverflowTooltip: true },
  {
    prop: 'kind',
    label: t('cccms.backup.kindLabel'),
    width: 120,
    align: 'center',
    slot: 'kind',
    filter: { type: 'enum', options: kindOptions.value },
  },
  { prop: 'engine', label: t('cccms.backup.engineLabel'), width: 120, align: 'center', slot: 'engine' },
  { prop: 'size', label: t('cccms.backup.sizeLabel'), width: 110, align: 'right', slot: 'size', sortable: 'custom' },
  { prop: 'table_count', label: t('cccms.backup.tableCount'), width: 90, align: 'right' },
  { prop: 'duration', label: t('cccms.backup.durationLabel'), width: 100, align: 'right', slot: 'duration' },
  {
    prop: 'status',
    label: t('cccms.backup.statusLabel'),
    width: 110,
    align: 'center',
    slot: 'status',
    filter: { type: 'enum', options: statusOptions.value },
  },
  { prop: 'create_time', label: t('cccms.backup.createdAt'), width: 170, sortable: 'custom' },
  { prop: 'action', label: t('core.table.action'), width: 190, fixed: 'right', slot: 'action', lockVisible: true },
])

const { list, loading, total, page, limit, selection, load, onPageChange, onLimitChange, onSelectionChange } = useTable<
  BackupRow,
  Query
>({
  api: backupList,
  initialQuery: { kind: '', status: '' },
})

function onRefresh(): void {
  void load()
}

/* ---- 格式化 ---- */
/** 备份大小：0 表示「未知」（文件还没生成 / 采集不到），展示成 — 而不是 0 B */
function formatSize(bytes: number): string {
  return formatBytes(bytes, { empty: '—' })
}

function formatDuration(ms: number): string {
  if (!ms) {
    return '—'
  }

  return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`
}

/* ---- 备份 / 下载 / 删除 ---- */
const creating = ref(false)

async function onCreate(): Promise<void> {
  creating.value = true
  try {
    const result = await backupCreate()
    ElMessage.success(t('cccms.backup.createSuccess', { name: result.file_name, size: formatSize(result.size) }))
    void load()
  } catch {
    // 失败原因由拦截器统一提示（后端已把原因写进备份记录的 error 列）
    void load()
  } finally {
    creating.value = false
  }
}

async function onDownload(row: BackupRow): Promise<void> {
  try {
    await backupDownload(row.id)
  } catch {
    // 校验失败 / 文件被清理等由拦截器提示
  }
}

async function onDelete(ids: number[]): Promise<void> {
  try {
    const result = await backupDelete(ids)
    ElMessage.success(t('cccms.backup.deleteSuccess', { count: result.deleted }))
    void load()
  } catch {
    // 进行中的备份不可删，后端会给出原因
  }
}

async function onBatchDelete(): Promise<void> {
  if (selection.value.length === 0) {
    ElMessage.warning(t('cccms.backup.deleteNone'))
    return
  }

  try {
    await ElMessageBox.confirm(t('cccms.backup.deleteConfirm'), t('core.common.tip'), { type: 'warning' })
  } catch {
    return
  }

  await onDelete(selection.value.map((row) => row.id))
}

/* ---- 还原 ---- */
const restoreVisible = ref(false)
const restoring = ref(false)
const tablesLoading = ref(false)
const restoreId = ref(0)
const restoreTables = ref<string[]>([])
const tableOptions = ref<string[]>([])
const database = ref('')
const confirmText = ref('')

async function openRestore(row: BackupRow): Promise<void> {
  restoreId.value = row.id
  restoreTables.value = []
  tableOptions.value = []
  database.value = ''
  confirmText.value = ''
  restoreVisible.value = true
  tablesLoading.value = true
  try {
    const result = await backupTables(row.id)
    database.value = result.database
    tableOptions.value = result.tables
  } catch {
    restoreVisible.value = false
  } finally {
    tablesLoading.value = false
  }
}

async function submitRestore(): Promise<void> {
  restoring.value = true
  try {
    const result = await backupRestore({
      id: restoreId.value,
      tables: restoreTables.value,
      confirm: confirmText.value,
    })
    ElMessage.success(
      t('cccms.backup.restoreDone', {
        tables: result.tables,
        statements: result.statements,
        id: result.pre_backup_id,
      }),
    )
    restoreVisible.value = false
    void load()
  } catch {
    // 失败原因由拦截器提示（含「已有还原在进行」）
  } finally {
    restoring.value = false
  }
}
</script>

<style scoped>
.backup-page {
  gap: 0;
}

.backup-file {
  font-family: var(--el-font-family-mono, monospace);
  font-size: var(--el-font-size-extra-small);
  color: var(--art-main);
  word-break: break-all;
}

.backup-engine {
  font-family: var(--el-font-family-mono, monospace);
  font-size: var(--el-font-size-extra-small);
  color: var(--art-sub);
}

.backup-none {
  color: var(--art-muted);
}

.toolbar-tip {
  margin-left: 8px;
  font-size: var(--el-font-size-extra-small);
  color: var(--art-sub);
}

.restore-form {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.restore-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.restore-label {
  font-size: var(--el-font-size-small);
  font-weight: 600;
  color: var(--art-main);
}

.restore-select {
  width: 100%;
}

.restore-tip {
  font-size: var(--el-font-size-extra-small);
  color: var(--art-sub);
}
</style>
