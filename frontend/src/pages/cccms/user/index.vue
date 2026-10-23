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
        <el-button v-auth="'cccms:user:save'" type="primary" @click="openCreate"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('core.common.create') }}
        </el-button>

        <!-- 批量操作：只在勾选后可用；越权 / 受保护的行由后端跳过并回报 -->
        <el-dropdown :disabled="selected.length === 0" @command="onBatchCommand">
          <el-button :disabled="selected.length === 0">
            {{
              selected.length
                ? t('cccms.user.batchActionCount', { count: selected.length })
                : t('cccms.user.batchAction')
            }}
            <el-icon class="el-icon--right"><i class="ri-arrow-down-s-line" /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_status')" command="enable">
                {{ t('cccms.user.batchEnable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_status')" command="disable">
                {{ t('cccms.user.batchDisable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_assign')" command="assign" divided>
                {{ t('cccms.user.batchAssign') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_delete')" command="delete" divided>
                {{ t('cccms.user.batchDelete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>

      <!-- 点一下即把表格切到「已删除」，不是另开页面 -->
      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('cccms.user.entityLabel')" @toggle="toggle" />
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('cccms.user.enabled') : t('cccms.user.disabled') }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:user:update'" link type="primary" @click="openEdit(row)">
          {{ t('core.common.edit') }}
        </el-button>
        <el-button v-auth="'cccms:user:reset_password'" link type="primary" @click="openReset(row)">
          {{ t('cccms.user.resetPassword') }}
        </el-button>
        <el-popconfirm :title="t('cccms.user.confirmDelete')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:user:delete'" link type="danger">{{ t('core.common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 新增 / 编辑 -->
    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('cccms.user.editUser') : t('cccms.user.createUser')"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item :label="t('cccms.user.username')" prop="username">
          <el-input v-model="form.username" :placeholder="t('cccms.user.usernameRequired')" />
        </el-form-item>
        <el-form-item v-if="!form.id" :label="t('cccms.user.password')" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="t('cccms.user.passwordPlaceholder')"
          />
        </el-form-item>
        <el-form-item :label="t('cccms.user.nickname')" prop="nickname">
          <el-input v-model="form.nickname" :placeholder="t('cccms.user.nicknamePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('cccms.user.phone')" prop="phone">
          <el-input v-model="form.phone" :placeholder="t('cccms.user.phonePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('cccms.user.email')" prop="email">
          <el-input v-model="form.email" :placeholder="t('cccms.user.emailPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('cccms.user.role')" prop="role_ids">
          <el-select
            v-model="form.role_ids"
            multiple
            :placeholder="t('cccms.user.rolePlaceholder')"
            style="width: 100%"
          >
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('cccms.user.dept')" prop="dept_ids">
          <el-tree-select
            v-model="form.dept_ids"
            :data="deptTreeData"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            multiple
            show-checkbox
            check-strictly
            :placeholder="t('cccms.user.deptPlaceholder')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('cccms.user.post')" prop="post_ids">
          <el-select
            v-model="form.post_ids"
            multiple
            :placeholder="t('cccms.user.postPlaceholder')"
            style="width: 100%"
          >
            <el-option v-for="p in postOptions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('cccms.user.status')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('cccms.user.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('cccms.user.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('core.common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('core.common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 重置密码 -->
    <el-dialog v-model="resetVisible" :title="t('cccms.user.resetPassword')" width="420px">
      <el-form ref="resetRef" :model="resetForm" :rules="resetRules" label-width="80px">
        <el-form-item :label="t('cccms.user.newPassword')" prop="password">
          <el-input
            v-model="resetForm.password"
            type="password"
            show-password
            :placeholder="t('cccms.user.passwordPlaceholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="resetVisible = false">{{ t('core.common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitReset">{{ t('core.common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 批量分配角色 / 部门 / 岗位 -->
    <el-dialog v-model="assignVisible" :title="t('cccms.user.batchAssign')" width="560px">
      <el-alert
        type="info"
        :closable="false"
        show-icon
        :title="t('cccms.user.assignTip', { count: selected.length })"
        style="margin-bottom: 12px"
      />
      <el-form label-width="60px">
        <el-form-item :label="t('cccms.user.role')">
          <el-select v-model="assignForm.role_ids" multiple :placeholder="t('cccms.user.keepAsIs')" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('cccms.user.dept')">
          <el-tree-select
            v-model="assignForm.dept_ids"
            :data="deptTreeData"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            multiple
            show-checkbox
            check-strictly
            :placeholder="t('cccms.user.keepAsIs')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('cccms.user.post')">
          <el-select v-model="assignForm.post_ids" multiple :placeholder="t('cccms.user.keepAsIs')" style="width: 100%">
            <el-option v-for="p in postOptions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="assignVisible = false">{{ t('core.common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitAssign">{{ t('core.common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:user' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { useUserStore } from '@/stores/user'
import {
  userBatchAssign,
  userBatchDelete,
  userBatchStatus,
  userDelete,
  userList,
  userRead,
  userResetPassword,
  userSave,
  userUpdate,
  type BatchResult,
} from '@/api/user'
import { roleList } from '@/api/role'
import { deptTree } from '@/api/dept'
import { postList } from '@/api/post'
import { passwordValidator } from '@/utils/password'
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
  username?: string
  nickname?: string
  phone?: string
  email?: string
  status?: number
  [key: string]: unknown
}

interface Query {
  username: string
  nickname: string
  /** 列头枚举多选，值形如 `1,0` */
  status: string
  /** 最后登录时间范围（列头日期筛选写入） */
  start: string
  end: string
}

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'username', label: t('cccms.user.username'), minWidth: 120, filter: { type: 'text' } },
  { prop: 'nickname', label: t('cccms.user.nickname'), minWidth: 120, filter: { type: 'text' } },
  { prop: 'phone', label: t('cccms.user.phone'), minWidth: 130 },
  { prop: 'email', label: t('cccms.user.email'), minWidth: 180 },
  {
    prop: 'status',
    label: t('cccms.user.status'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('cccms.user.enabled'), value: 1 },
        { label: t('cccms.user.disabled'), value: 0 },
      ],
    },
  },
  { prop: 'login_time', label: t('cccms.user.lastLogin'), width: 170, filter: { type: 'date' }, sortable: 'custom' },
  { prop: 'action', label: t('core.table.action'), width: 210, fixed: 'right', slot: 'action', lockVisible: true },
])

// 回收站开关：必须声明在 useTable 之前 —— 列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('user', {
  reload: () => load(),
})

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<Row, Query>({
  api: (params) => userList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { username: '', nickname: '', status: '', start: '', end: '' },
})

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  username: '',
  password: '',
  nickname: '',
  phone: '',
  email: '',
  status: 1,
  role_ids: [] as number[],
  dept_ids: [] as number[],
  post_ids: [] as number[],
}
const form = reactive({ ...emptyForm })

const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: t('cccms.user.usernameRequired'), trigger: 'blur' }],
  password: [
    { required: true, message: t('cccms.user.passwordRequired'), trigger: 'blur' },
    // 身份信息用表单里已填的用户名 / 昵称，提前拦住「密码就是用户名」这类可猜口令
    { validator: passwordValidator(() => ({ username: form.username, nickname: form.nickname })), trigger: 'blur' },
  ],
}))

function openCreate(): void {
  Object.assign(form, emptyForm, { role_ids: [], dept_ids: [], post_ids: [] })
  formVisible.value = true
}

async function openEdit(record: Row): Promise<void> {
  Object.assign(form, emptyForm, { role_ids: [], dept_ids: [], post_ids: [] })
  const detail = await userRead(record.id)
  Object.assign(form, detail)
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
      await userUpdate({ ...form })
    } else {
      await userSave({ ...form })
    }
    ElMessage.success(t('cccms.user.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await userDelete(id)
  ElMessage.success(t('cccms.user.deleteSuccess'))
  load()
}

/* ---- 重置密码 ---- */
const resetVisible = ref(false)
const resetRef = ref<FormInstance>()
const resetForm = reactive({ id: 0, password: '' })
const resetUsername = ref('')
const resetRules = computed<FormRules>(() => ({
  password: [
    { required: true, message: t('cccms.user.newPasswordRequired'), trigger: 'blur' },
    // 重置的是既有账号，用列表里那一行的用户名做身份比对
    { validator: passwordValidator(() => ({ username: resetUsername.value })), trigger: 'blur' },
  ],
}))

function openReset(record: Row): void {
  resetForm.id = record.id
  resetForm.password = ''
  resetUsername.value = record.username || ''
  resetVisible.value = true
}

async function submitReset(): Promise<void> {
  const valid = await resetRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    await userResetPassword({ ...resetForm })
    ElMessage.success(t('cccms.user.resetSuccess'))
    resetVisible.value = false
  } finally {
    saving.value = false
  }
}

/* ---- 批量操作 ---- */
const selected = ref<Row[]>([])

function onSelectionChange(rows: Row[]): void {
  selected.value = rows
}

/** 统一的批量结果提示：跳过的行单独说明，避免「点了没反应」的困惑 */
function reportBatch(result: BatchResult, verb: string): void {
  if (result.skipped.length > 0) {
    ElMessage.warning(
      t('cccms.user.batchSkipped', { action: verb, affected: result.affected, skipped: result.skipped.length }),
    )
  } else {
    ElMessage.success(t('cccms.user.batchDone', { action: verb, affected: result.affected }))
  }
}

async function onBatchCommand(command: string): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  if (ids.length === 0) {
    return
  }

  if (command === 'assign') {
    Object.assign(assignForm, { role_ids: [], dept_ids: [], post_ids: [] })
    assignVisible.value = true
    return
  }

  const isDelete = command === 'delete'
  const confirmMessage = isDelete
    ? t('cccms.user.confirmBatchDelete', { count: ids.length })
    : command === 'enable'
      ? t('cccms.user.confirmBatchEnable', { count: ids.length })
      : t('cccms.user.confirmBatchDisable', { count: ids.length })
  try {
    await ElMessageBox.confirm(confirmMessage, isDelete ? t('cccms.user.batchDelete') : t('cccms.user.batchToggle'), {
      type: 'warning',
    })
  } catch {
    return
  }

  const result = isDelete ? await userBatchDelete(ids) : await userBatchStatus(ids, command === 'enable' ? 1 : 0)
  reportBatch(result, isDelete ? t('cccms.user.batchVerbDelete') : t('cccms.user.batchVerbUpdate'))
  selected.value = []
  load()
}

/* ---- 批量分配 ---- */
const assignVisible = ref(false)
const assignForm = reactive<{ role_ids: number[]; dept_ids: number[]; post_ids: number[] }>({
  role_ids: [],
  dept_ids: [],
  post_ids: [],
})

async function submitAssign(): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  // 只提交有内容的字段：未选的字段后端会保持原样，传空数组则会被清空
  const payload: { ids: number[]; role_ids?: number[]; dept_ids?: number[]; post_ids?: number[] } = { ids }
  if (assignForm.role_ids.length) {
    payload.role_ids = assignForm.role_ids
  }
  if (assignForm.dept_ids.length) {
    payload.dept_ids = assignForm.dept_ids
  }
  if (assignForm.post_ids.length) {
    payload.post_ids = assignForm.post_ids
  }
  if (payload.role_ids === undefined && payload.dept_ids === undefined && payload.post_ids === undefined) {
    ElMessage.warning(t('cccms.user.assignAtLeastOne'))
    return
  }

  saving.value = true
  try {
    const result = await userBatchAssign(payload)
    reportBatch(result, t('cccms.user.batchVerbAssign'))
    assignVisible.value = false
    selected.value = []
    load()
  } finally {
    saving.value = false
  }
}

/* ---- 下拉数据 ---- */
const roleOptions = ref<Row[]>([])
const postOptions = ref<Row[]>([])
const deptTreeData = ref<Record<string, unknown>[]>([])

onMounted(async () => {
  const [roles, posts, depts] = await Promise.all([
    roleList({ page: 1, limit: 999 }),
    postList({ page: 1, limit: 999 }),
    deptTree(),
  ])
  roleOptions.value = roles.list as Row[]
  postOptions.value = posts.list as Row[]
  deptTreeData.value = depts as Record<string, unknown>[]
})
</script>
