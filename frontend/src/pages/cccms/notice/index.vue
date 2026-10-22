<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      :recycle="recycle"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @restore="onRestore"
      @force-delete="onForceDelete"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:notice:save'" type="primary" @click="openCreate"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('common.create') }}
        </el-button>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('notice.recycleLabel')" @toggle="toggle" />
      </template>

      <template #type="{ row }">
        <el-tag :type="row.type === 2 ? 'primary' : 'info'" effect="plain">
          {{ row.type === 2 ? t('notice.typeAnnouncement') : t('notice.typeNotification') }}
        </el-tag>
      </template>

      <template #level="{ row }">
        <el-tag :type="row.level === 2 ? 'danger' : 'info'" effect="light">
          {{ row.level === 2 ? t('notice.levelImportant') : t('notice.levelNormal') }}
        </el-tag>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'warning'" effect="light" round>
          {{ row.status === 1 ? t('notice.published') : t('notice.draft') }}
        </el-tag>
      </template>

      <template #scope="{ row }">
        <el-tag :type="scopeTagType(row.scope)" effect="plain" size="small">
          {{ scopeLabel(row.scope) }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:notice:report'" link type="success" @click="openReport(row)">
          {{ t('notice.report') }}
        </el-button>
        <el-button v-auth="'cccms:notice:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-popconfirm :title="t('notice.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:notice:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('notice.editTitle') : t('notice.createTitle')"
      width="720px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item :label="t('notice.titleLabel')" prop="title">
          <el-input v-model="form.title" :placeholder="t('notice.titlePlaceholder')" maxlength="128" show-word-limit />
        </el-form-item>
        <el-form-item :label="t('notice.typeLabel')" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">{{ t('notice.typeNotification') }}</el-radio>
            <el-radio :value="2">{{ t('notice.typeAnnouncement') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('notice.levelLabel')" prop="level">
          <el-radio-group v-model="form.level">
            <el-radio :value="1">{{ t('notice.levelNormal') }}</el-radio>
            <el-radio :value="2">{{ t('notice.levelImportant') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('notice.statusLabel')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('notice.publish') }}</el-radio>
            <el-radio :value="0">{{ t('notice.draft') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('notice.scopeLabel')" prop="scope">
          <el-radio-group v-model="form.scope" @change="onScopeChange">
            <el-radio :value="0">{{ t('notice.scopeAll') }}</el-radio>
            <el-radio :value="1">{{ t('notice.scopeDept') }}</el-radio>
            <el-radio :value="2">{{ t('notice.scopeRole') }}</el-radio>
            <el-radio :value="3">{{ t('notice.scopeUser') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.scope === 1" :label="t('notice.selectDeptLabel')">
          <ArtNodePicker v-model="form.target_ids" :data="options.depts" multiple />
          <i18n-t keypath="notice.scopeDeptTip" scope="global" tag="div" class="form-tip">
            <template #sub>
              <b>{{ t('notice.scopeDeptTipSub') }}</b>
            </template>
          </i18n-t>
        </el-form-item>
        <el-form-item v-if="form.scope === 2" :label="t('notice.selectRoleLabel')">
          <ArtNodePicker v-model="form.target_ids" :data="options.roles" multiple />
          <i18n-t keypath="notice.scopeRoleTip" scope="global" tag="div" class="form-tip">
            <template #sub>
              <b>{{ t('notice.scopeRoleTipSub') }}</b>
            </template>
          </i18n-t>
        </el-form-item>
        <el-form-item v-if="form.scope === 3" :label="t('notice.selectUserLabel')">
          <el-select
            v-model="form.target_ids"
            multiple
            filterable
            remote
            reserve-keyword
            :remote-method="searchUsers"
            :loading="userLoading"
            :placeholder="t('notice.userSearchPlaceholder')"
            style="width: 100%"
          >
            <el-option
              v-for="u in userOptions"
              :key="u.id"
              :label="u.nickname ? `${u.username}（${u.nickname}）` : u.username"
              :value="u.id"
            />
          </el-select>
          <div class="form-tip">{{ t('notice.scopeUserTip') }}</div>
        </el-form-item>
        <el-form-item :label="t('notice.publishAtLabel')">
          <el-date-picker
            v-model="form.publish_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            :placeholder="t('notice.publishAtPlaceholder')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('notice.expireAtLabel')">
          <el-date-picker
            v-model="form.expire_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            :placeholder="t('notice.expireAtPlaceholder')"
            style="width: 100%"
          />
        </el-form-item>
        <!-- 正文是长文，用富文本编辑器；备注/说明这类短文本仍用 textarea -->
        <el-form-item :label="t('notice.contentLabel')" prop="content">
          <ArtRichEditor v-model="form.content" :placeholder="t('notice.contentPlaceholder')" :min-height="220" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 已读回执统计报表 -->
    <el-dialog
      v-model="reportVisible"
      :title="t('notice.reportTitle', { title: report.notice.title })"
      width="780px"
      :close-on-click-modal="false"
    >
      <div class="report-summary">
        <el-tag type="info" effect="plain">{{ t('notice.reportShouldRead', { count: report.total }) }}</el-tag>
        <el-tag type="success" effect="plain">{{ t('notice.reportRead', { count: report.read }) }}</el-tag>
        <el-tag type="warning" effect="plain">{{ t('notice.reportUnread', { count: report.unread }) }}</el-tag>
        <el-radio-group v-model="reportView" size="small" class="report-switch" @change="onReportViewChange">
          <el-radio-button value="read">{{ t('notice.reportReadDetail') }}</el-radio-button>
          <el-radio-button value="unread">{{ t('notice.reportUnreadDetail') }}</el-radio-button>
        </el-radio-group>
      </div>
      <el-table v-loading="reportLoading" :data="report.list" size="small" border>
        <el-table-column prop="user_id" :label="t('notice.userIdLabel')" width="90" />
        <el-table-column prop="username" :label="t('notice.usernameLabel')" min-width="120" />
        <el-table-column prop="nickname" :label="t('notice.nicknameLabel')" min-width="120" />
        <el-table-column prop="read_time" :label="t('notice.readTimeLabel')" min-width="170">
          <template #default="{ row }">{{ row.read_time || '—' }}</template>
        </el-table-column>
      </el-table>
      <div class="report-pager">
        <el-pagination
          layout="total, prev, pager, next"
          :total="report.total"
          :page-size="reportLimit"
          :current-page="reportPage"
          @current-change="onReportPageChange"
        />
      </div>
      <template #footer>
        <el-button @click="reportVisible = false">{{ t('notice.close') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:notice' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ArtRichEditor from '@/components/core/ArtRichEditor.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import ArtNodePicker from '@/components/core/ArtNodePicker.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import {
  noticeDelete,
  noticeList,
  noticeOptions,
  noticeReport,
  noticeSave,
  noticeUpdate,
  noticeUsers,
  type NoticeOptions,
  type NoticeReport,
  type NoticeRow,
  type NoticeUserOption,
} from '@/api/notice'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

interface Query {
  title: string
  /** 多选值以逗号串传递 */
  type: string
  level: string
  status: string
  scope: string
  /** 创建时间范围（列头时间筛选写入） */
  start: string
  end: string
  /** 发布时间范围 */
  publish_start: string
  publish_end: string
  /** 到期时间范围 */
  expire_start: string
  expire_end: string
}

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: t('notice.titleLabel'), minWidth: 220, filter: { type: 'text' } },
  {
    prop: 'type',
    label: t('notice.typeLabel'),
    width: 90,
    align: 'center',
    slot: 'type',
    filter: {
      type: 'enum',
      options: [
        { label: t('notice.typeNotification'), value: 1 },
        { label: t('notice.typeAnnouncement'), value: 2 },
      ],
    },
  },
  {
    prop: 'level',
    label: t('notice.levelLabel'),
    width: 90,
    align: 'center',
    slot: 'level',
    filter: {
      type: 'enum',
      options: [
        { label: t('notice.levelNormal'), value: 1 },
        { label: t('notice.levelImportant'), value: 2 },
      ],
    },
  },
  {
    prop: 'status',
    label: t('notice.statusLabel'),
    width: 100,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('notice.published'), value: 1 },
        { label: t('notice.draft'), value: 0 },
      ],
    },
  },
  {
    prop: 'scope',
    label: t('notice.scopeLabel'),
    width: 110,
    align: 'center',
    slot: 'scope',
    filter: {
      type: 'enum',
      options: [
        { label: t('notice.scopeAll'), value: 0 },
        { label: t('notice.scopeDept'), value: 1 },
        { label: t('notice.scopeRole'), value: 2 },
        { label: t('notice.scopeUser'), value: 3 },
      ],
    },
  },
  // 发布时间 / 到期时间各用一组 startKey / endKey，避免和创建时间范围互相覆盖
  {
    prop: 'publish_at',
    label: t('notice.publishAtLabel'),
    width: 170,
    filter: { type: 'date', startKey: 'publish_start', endKey: 'publish_end' },
    sortable: 'custom',
  },
  {
    prop: 'expire_at',
    label: t('notice.expireAtLabel'),
    width: 170,
    filter: { type: 'date', startKey: 'expire_start', endKey: 'expire_end' },
    sortable: 'custom',
  },
  { prop: 'read_count', label: t('notice.readCountLabel'), width: 120, align: 'right', sortable: 'custom' },
  { prop: 'create_time', label: t('notice.createdAtLabel'), width: 170, filter: { type: 'date' } },
  { prop: 'action', label: t('table.action'), width: 170, fixed: 'right', slot: 'action', lockVisible: true },
])

// 投放范围 → 语言包 key（整句在语言包里，这里只做映射）
const SCOPE_KEYS: Record<number, string> = {
  0: 'notice.scopeAll',
  1: 'notice.scopeDept',
  2: 'notice.scopeRole',
  3: 'notice.scopeUser',
}

function scopeLabel(scope: number): string {
  return t(SCOPE_KEYS[scope] ?? SCOPE_KEYS[0])
}

function scopeTagType(scope: number): 'info' | 'primary' | 'success' | 'warning' | 'danger' {
  return scope === 0 ? 'info' : 'primary'
}

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('notice', {
  reload: () => load(),
})

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<NoticeRow, Query>({
  api: (params) => noticeList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: {
    title: '',
    type: '',
    level: '',
    status: '',
    scope: '',
    start: '',
    end: '',
    publish_start: '',
    publish_end: '',
    expire_start: '',
    expire_end: '',
  },
})

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  title: '',
  type: 1,
  level: 1,
  status: 1,
  scope: 0,
  target_ids: [] as number[],
  publish_at: null as string | null,
  expire_at: null as string | null,
  content: '',
}
const form = reactive({ ...emptyForm })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  title: [{ required: true, message: t('notice.titleRequired'), trigger: 'blur' }],
}))

// ---- 投放目标候选 ----
const options = reactive<NoticeOptions>({ depts: [], roles: [] })

async function loadOptions(): Promise<void> {
  const data = await noticeOptions()
  options.depts = data.depts ?? []
  options.roles = data.roles ?? []
}

// 用户候选（懒加载）
const userOptions = ref<NoticeUserOption[]>([])
const userLoading = ref(false)
// 请求序号：远程搜索连续输入时，先发后至的旧结果不能覆盖新结果
let userSearchSeq = 0

async function searchUsers(keyword: string): Promise<void> {
  const seq = ++userSearchSeq
  userLoading.value = true
  try {
    const result = await noticeUsers({ keyword })
    if (seq === userSearchSeq) {
      userOptions.value = result
    }
  } finally {
    if (seq === userSearchSeq) {
      userLoading.value = false
    }
  }
}

async function loadSelectedUsers(ids: number[]): Promise<void> {
  if (!ids.length) {
    return
  }
  userOptions.value = await noticeUsers({ ids })
}

function onScopeChange(): void {
  form.target_ids = []
  userOptions.value = []
}

function openCreate(): void {
  Object.assign(form, emptyForm, { target_ids: [] })
  userOptions.value = []
  formVisible.value = true
}

async function openEdit(row: NoticeRow): Promise<void> {
  // 列表行已携带 content / target_ids 等全部字段，直接回填，省一次详情请求
  Object.assign(form, emptyForm, {
    id: row.id,
    title: row.title,
    type: row.type,
    level: row.level,
    status: row.status,
    scope: row.scope ?? 0,
    target_ids: Array.isArray(row.target_ids) ? [...row.target_ids] : [],
    publish_at: row.publish_at,
    expire_at: row.expire_at,
    content: row.content,
  })
  userOptions.value = []
  if ((row.scope ?? 0) === 3) {
    await loadSelectedUsers(form.target_ids)
  }
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (form.scope !== 0 && (!Array.isArray(form.target_ids) || form.target_ids.length === 0)) {
    ElMessage.warning(t('notice.targetRequired'))
    return
  }
  saving.value = true
  try {
    const payload = { ...form, target_ids: form.scope === 0 ? [] : form.target_ids }
    if (form.id) {
      await noticeUpdate(payload)
    } else {
      await noticeSave(payload)
    }
    ElMessage.success(t('notice.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await noticeDelete(id)
  ElMessage.success(t('notice.deleteSuccess'))
  load()
}

// ---- 已读回执报表 ----
const reportVisible = ref(false)
const reportLoading = ref(false)
const report = ref<NoticeReport>({
  notice: { id: 0, title: '', scope: 0 },
  total: 0,
  read: 0,
  unread: 0,
  list: [],
})
const reportView = ref<'read' | 'unread'>('read')
const reportPage = ref(1)
const reportLimit = ref(15)

async function openReport(row: NoticeRow): Promise<void> {
  reportVisible.value = true
  reportView.value = 'read'
  reportPage.value = 1
  report.value = {
    notice: { id: row.id, title: row.title, scope: row.scope ?? 0 },
    total: 0,
    read: 0,
    unread: 0,
    list: [],
  }
  await loadReport(row.id)
}

async function loadReport(id: number): Promise<void> {
  reportLoading.value = true
  try {
    report.value = await noticeReport({ id, view: reportView.value, page: reportPage.value, limit: reportLimit.value })
  } finally {
    reportLoading.value = false
  }
}

function onReportViewChange(): void {
  reportPage.value = 1
  loadReport(report.value.notice.id)
}

function onReportPageChange(p: number): void {
  reportPage.value = p
  loadReport(report.value.notice.id)
}

loadOptions()
</script>

<style scoped>
.form-tip {
  width: 100%;
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--art-muted);
}

.report-summary {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.report-switch {
  margin-left: auto;
}

.report-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
