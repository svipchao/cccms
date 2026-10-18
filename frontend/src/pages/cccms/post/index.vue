<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      :recycle="recycle"
      selection
      row-key="id"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @restore="onRestore"
      @force-delete="onForceDelete"
      @selection-change="onSelectionChange"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:post:save'" type="primary" @click="openCreate"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('common.create') }}
        </el-button>

        <!-- 批量操作：下挂用户的岗位会被后端跳过并回报 -->
        <el-dropdown :disabled="selected.length === 0" @command="onBatchCommand">
          <el-button :disabled="selected.length === 0">
            {{ selected.length ? t('post.batchActionWithCount', { count: selected.length }) : t('post.batchAction') }}
            <el-icon class="el-icon--right"><i class="ri-arrow-down-s-line" /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-if="hasAuth('cccms:post:batch_status')" command="enable">
                {{ t('post.batchEnable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:post:batch_status')" command="disable">
                {{ t('post.batchDisable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:post:batch_delete')" command="delete" divided>
                {{ t('post.batchDelete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('post.recycleLabel')" @toggle="toggle" />
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('post.enabled') : t('post.disabled') }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:post:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-popconfirm :title="t('post.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:post:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('post.editTitle') : t('post.createTitle')"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="88px">
        <el-form-item :label="t('post.codeLabel')" prop="code">
          <el-input v-model="form.code" :placeholder="t('post.codePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('post.nameLabel')" prop="name">
          <el-input v-model="form.name" :placeholder="t('post.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('post.sortLabel')" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('post.statusLabel')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('post.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('post.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:post' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { useUserStore } from '@/stores/user'
import {
  postBatchDelete,
  postBatchStatus,
  postDelete,
  postList,
  postSave,
  postUpdate,
  type PostBatchResult,
} from '@/api/post'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })
/**
 * 批量操作菜单项用 `v-if="hasAuth(...)"` 而不是 `v-auth`：
 * ElDropdownItem 的根节点不是单个元素（内部是 ElRovingFocusItem），
 * 运行时指令挂不上去，Vue 会告警且权限隐藏失效。
 */
const { hasAuth } = useUserStore()

interface Row {
  id: number
  code?: string
  name?: string
  sort?: number
  status?: number
  [key: string]: unknown
}

interface Query {
  name: string
  /** 列头枚举多选，值形如 `1,0` */
  status: string
}

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'code', label: t('post.codeLabel'), minWidth: 150 },
  { prop: 'name', label: t('post.nameLabel'), minWidth: 150, filter: { type: 'text' } },
  { prop: 'sort', label: t('post.sortLabel'), width: 90, align: 'center', sortable: 'custom' },
  {
    prop: 'status',
    label: t('post.statusLabel'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('post.enabled'), value: 1 },
        { label: t('post.disabled'), value: 0 },
      ],
    },
  },
  { prop: 'action', label: t('table.action'), width: 130, fixed: 'right', slot: 'action', lockVisible: true },
])

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('post', {
  reload: () => load(),
})

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<Row, Query>({
  api: (params) => postList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '', status: '' },
})

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = { id: 0, code: '', name: '', sort: 0, status: 1 }
const form = reactive({ ...emptyForm })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  code: [{ required: true, message: t('post.codeRequired'), trigger: 'blur' }],
  name: [{ required: true, message: t('post.nameRequired'), trigger: 'blur' }],
}))

function openCreate(): void {
  Object.assign(form, emptyForm)
  formVisible.value = true
}

function openEdit(record: Row): void {
  Object.assign(form, emptyForm, record)
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (form.id) {
      await postUpdate({ ...form })
    } else {
      await postSave({ ...form })
    }
    ElMessage.success(t('post.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await postDelete(id)
  ElMessage.success(t('post.deleteSuccess'))
  load()
}

/* ---------- 批量操作 ---------- */

const selected = ref<Row[]>([])

function onSelectionChange(rows: Row[]): void {
  selected.value = rows
}

/** 越权 / 下挂用户的岗位由后端跳过，这里统一回报 */
function reportBatch(result: PostBatchResult, action: 'enable' | 'disable' | 'delete'): void {
  const doneKey = {
    enable: 'post.batchEnableSuccess',
    disable: 'post.batchDisableSuccess',
    delete: 'post.batchDeleteSuccess',
  }[action]
  const partialKey = {
    enable: 'post.batchEnablePartial',
    disable: 'post.batchDisablePartial',
    delete: 'post.batchDeletePartial',
  }[action]
  if (result.skipped.length > 0) {
    ElMessage.warning(t(partialKey, { count: result.affected, skipped: result.skipped.length }))
  } else {
    ElMessage.success(t(doneKey, { count: result.affected }))
  }
}

async function onBatchCommand(command: string): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  if (ids.length === 0) {
    return
  }
  if (command === 'enable' || command === 'disable') {
    const status = command === 'enable' ? 1 : 0
    const result = await postBatchStatus(ids, status)
    reportBatch(result, command)
    load()
    return
  }
  if (command === 'delete') {
    try {
      await ElMessageBox.confirm(t('post.batchDeleteConfirm', { count: ids.length }), t('post.batchDeleteTitle'), {
        type: 'warning',
      })
    } catch {
      return
    }
    const result = await postBatchDelete(ids)
    reportBatch(result, 'delete')
    load()
  }
}
</script>
