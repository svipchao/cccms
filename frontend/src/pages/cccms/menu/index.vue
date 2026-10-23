<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="tableData"
      :loading="loading"
      :pagination="false"
      :recycle="recycle"
      tree
      @refresh="load"
      @restore="onRestore"
      @force-delete="onForceDelete"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:menu:save'" type="primary" @click="openCreate(0)"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('core.common.create') }}
        </el-button>
        <span class="toolbar-tip">
          {{ t('cccms.menu.permTip') }}
        </span>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('cccms.menu.menuLabel')" @toggle="toggle" />
      </template>

      <template #type="{ row }">
        <el-tag :type="typeTag(row.type)" effect="light">{{ typeText(row.type) }}</el-tag>
      </template>

      <template #node="{ row }">
        <code class="node-code">{{ row.node }}</code>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('cccms.menu.shown') : t('cccms.menu.hidden') }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-if="row.type !== 3" v-auth="'cccms:menu:save'" link type="primary" @click="openCreate(row.id)">
          {{ t('cccms.menu.addChild') }}
        </el-button>
        <el-button v-auth="'cccms:menu:update'" link type="primary" @click="openEdit(row)">
          {{ t('core.common.edit') }}
        </el-button>
        <el-popconfirm :title="t('cccms.menu.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:menu:delete'" link type="danger">{{ t('core.common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('cccms.menu.editTitle') : t('cccms.menu.createTitle')"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item :label="t('cccms.menu.parentNode')" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'title', children: 'children' }"
            node-key="id"
            check-strictly
            :placeholder="t('cccms.menu.top')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('cccms.menu.typeLabel')" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">{{ t('cccms.menu.typeDir') }}</el-radio>
            <el-radio :value="2">{{ t('cccms.menu.typeMenu') }}</el-radio>
            <el-radio :value="3">{{ t('cccms.menu.typeButton') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('cccms.menu.name')" prop="title">
          <el-input v-model="form.title" :placeholder="t('cccms.menu.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('cccms.menu.node')" prop="node">
          <el-input v-model="form.node" :placeholder="t('cccms.menu.nodePlaceholder')" />
          <div class="form-tip">{{ t('cccms.menu.nodeTip') }}</div>
        </el-form-item>
        <el-form-item v-if="form.type !== 3" :label="t('cccms.menu.path')" prop="path">
          <el-input v-model="form.path" :placeholder="t('cccms.menu.pathPlaceholder')" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" :label="t('cccms.menu.component')" prop="component">
          <el-input v-model="form.component" :placeholder="t('cccms.menu.componentPlaceholder')" />
        </el-form-item>
        <el-form-item v-if="form.type !== 3" :label="t('cccms.menu.icon')" prop="icon">
          <el-popover trigger="click" :width="372" placement="bottom-start">
            <template #reference>
              <el-input v-model="form.icon" readonly :placeholder="t('cccms.menu.iconPlaceholder')" style="width: 100%">
                <template #prefix>
                  <el-icon><ArtIcon :name="form.icon" /></el-icon>
                </template>
              </el-input>
            </template>
            <div class="icon-picker">
              <button
                v-for="name in MENU_ICON_OPTIONS"
                :key="name"
                type="button"
                class="icon-picker-item"
                :class="{ 'is-active': form.icon === name }"
                :title="name"
                @click="form.icon = name"
              >
                <el-icon :size="16"><ArtIcon :name="name" /></el-icon>
              </button>
            </div>
          </el-popover>
        </el-form-item>
        <el-form-item :label="t('cccms.menu.sort')" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('cccms.menu.status')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('cccms.menu.shown') }}</el-radio>
            <el-radio :value="0">{{ t('cccms.menu.hidden') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('core.common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('core.common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:menu' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ArtIcon from '@/components/core/ArtIcon.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTableFilter } from '@/composables/useTable'
import { menuDelete, menuSave, menuTree, menuUpdate } from '@/api/menu'
import { MENU_ICON_OPTIONS } from '@/utils/icon'
import type { MenuNode } from '@/api/types'
import type { ArtTableColumn } from '@/types/table'
import { filterTree, matchEnum } from '@/utils/tree'

const { t } = useI18n({ useScope: 'global' })

/** 类型枚举：值是 i18n key，渲染处翻译 */
const typeTextMap: Record<number, string> = {
  1: 'cccms.menu.typeDir',
  2: 'cccms.menu.typeMenu',
  3: 'cccms.menu.typeButton',
}
const typeTagMap: Record<number, 'primary' | 'success' | 'warning'> = {
  1: 'primary',
  2: 'success',
  3: 'warning',
}

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'title', label: t('cccms.menu.name'), minWidth: 200, filter: { type: 'text' } },
  {
    prop: 'type',
    label: t('cccms.menu.typeLabel'),
    width: 90,
    align: 'center',
    slot: 'type',
    filter: {
      type: 'enum',
      options: [
        { label: t('cccms.menu.typeDir'), value: 1 },
        { label: t('cccms.menu.typeMenu'), value: 2 },
        { label: t('cccms.menu.typeButton'), value: 3 },
      ],
    },
  },
  { prop: 'node', label: t('cccms.menu.node'), minWidth: 190, slot: 'node', filter: { type: 'text' } },
  { prop: 'path', label: t('cccms.menu.path'), width: 160 },
  { prop: 'component', label: t('cccms.menu.component'), width: 190 },
  { prop: 'sort', label: t('cccms.menu.sort'), width: 80, align: 'center' },
  {
    prop: 'status',
    label: t('cccms.menu.status'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('cccms.menu.shown'), value: 1 },
        { label: t('cccms.menu.hidden'), value: 0 },
      ],
    },
  },
  { prop: 'action', label: t('core.table.action'), width: 210, fixed: 'right', slot: 'action', lockVisible: true },
])

const loading = ref(false)
const list = ref<MenuNode[]>([])
const parentOptions = ref<MenuNode[]>([])

/* ---- 关键字过滤（前端过滤，不请求接口） ---- */
interface Query {
  title: string
  node: string
  /** 列头枚举多选，值形如 `1,2` */
  type: string
  /** 列头枚举多选，值形如 `1,0` */
  status: string
}

const { query } = useTableFilter<Query>({ initialQuery: { title: '', node: '', type: '', status: '' } })

const tableData = computed<MenuNode[]>(() => {
  const title = query.title.trim().toLowerCase()
  const node = query.node.trim().toLowerCase()
  const type = query.type
  const status = query.status
  if (!title && !node && !type && !status) {
    return list.value
  }
  return filterTree(list.value, (item) => {
    const hitTitle = !title || item.title.toLowerCase().includes(title)
    const hitNode = !node || item.node.toLowerCase().includes(node)
    return hitTitle && hitNode && matchEnum(item.type, type) && matchEnum(item.status, status)
  })
})

const typeText = (type: number): string => (typeTextMap[type] ? t(typeTextMap[type]) : '-')
const typeTag = (type: number): 'primary' | 'success' | 'warning' => typeTagMap[type] ?? 'primary'

// 回收站开关（load() 在本文件末尾首次执行，那时它已初始化）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('menu', {
  reload: () => load(),
})

async function load(): Promise<void> {
  loading.value = true
  try {
    // trashed=true → 后端返回平铺的已删节点（含隐藏节点），恢复后父子关系自动接上
    list.value = await menuTree(recycle.value)
    parentOptions.value = [
      {
        id: 0,
        parent_id: 0,
        type: 1,
        title: t('cccms.menu.top'),
        path: '',
        component: '',
        icon: '',
        sort: 0,
        node: '',
        status: 1,
        children: list.value,
      },
    ]
  } finally {
    loading.value = false
  }
}

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  parent_id: 0,
  type: 2,
  title: '',
  node: '',
  path: '',
  component: '',
  icon: '',
  sort: 0,
  status: 1,
}
const form = reactive({ ...emptyForm })

const rules = computed<FormRules>(() => ({
  title: [{ required: true, message: t('cccms.menu.nameRequired'), trigger: 'blur' }],
  node: [{ required: true, message: t('cccms.menu.nodeRequired'), trigger: 'blur' }],
}))

function openCreate(parentId: number): void {
  Object.assign(form, emptyForm, { parent_id: parentId })
  formVisible.value = true
}

function openEdit(record: MenuNode): void {
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
      await menuUpdate({ ...form })
    } else {
      await menuSave({ ...form })
    }
    ElMessage.success(t('cccms.menu.saveSuccess'))
    formVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await menuDelete(id)
  ElMessage.success(t('cccms.menu.deleteSuccess'))
  await load()
}

load()
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}

.node-code {
  padding: 1px 6px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-sub);
  background: var(--art-hover-bg);
  border-radius: 4px;
}

.form-tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

.icon-picker {
  display: grid;
  grid-template-columns: repeat(9, 1fr);
  gap: 6px;
  max-height: 260px;
  overflow-y: auto;
}

.icon-picker-item {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  color: var(--art-sub);
  cursor: pointer;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
}

.icon-picker-item:hover {
  color: var(--el-color-primary);
  background: var(--art-hover-bg);
}

.icon-picker-item.is-active {
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-7);
}
</style>
