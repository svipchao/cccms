<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      row-key="id"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:tenant:save'" type="primary" @click="openCreate">
          <template #icon><i class="ri-add-line" /></template>
          {{ t('common.create') }}
        </el-button>
      </template>

      <template #name="{ row }">
        <span>{{ row.name }}</span>
        <el-tag v-if="row.is_platform" class="tenant-tag" type="info" size="small" effect="plain">
          {{ t('tenant.platformTag') }}
        </el-tag>
      </template>

      <template #userCount="{ row }">{{ row.user_count }}</template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('tenant.enabled') : t('tenant.disabled') }}
        </el-tag>
      </template>

      <template #expire="{ row }">
        <span v-if="row.is_platform">{{ t('tenant.expireNever') }}</span>
        <span v-else>{{ row.expire_at || t('tenant.expireNever') }}</span>
      </template>

      <template #action="{ row }">
        <!-- 平台租户是虚拟行：只读，不提供编辑 / 删除入口（后端同样会拒绝） -->
        <template v-if="!row.is_platform">
          <el-button v-auth="'cccms:tenant:update'" link type="primary" @click="openEdit(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-popconfirm :title="t('tenant.deleteConfirm')" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:tenant:delete'" link type="danger">{{ t('common.delete') }}</el-button>
            </template>
          </el-popconfirm>
        </template>
        <el-tooltip v-else :content="t('tenant.platformHint')" placement="left">
          <span class="tenant-readonly">—</span>
        </el-tooltip>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('tenant.editTitle') : t('tenant.createTitle')"
      width="520px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item :label="t('tenant.nameLabel')" prop="name">
          <el-input v-model="form.name" :placeholder="t('tenant.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('tenant.codeLabel')" prop="code">
          <el-input v-model="form.code" :placeholder="t('tenant.codePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('tenant.contactLabel')" prop="contact">
          <el-input v-model="form.contact" :placeholder="t('tenant.contactPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('tenant.phoneLabel')" prop="phone">
          <el-input v-model="form.phone" :placeholder="t('tenant.phonePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('tenant.expireLabel')" prop="expire_at">
          <el-date-picker
            v-model="form.expire_at"
            type="date"
            value-format="YYYY-MM-DD"
            :placeholder="t('tenant.expirePlaceholder')"
            clearable
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('tenant.statusLabel')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('tenant.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('tenant.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('tenant.remarkLabel')" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" :placeholder="t('tenant.remarkPlaceholder')" />
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
defineOptions({ name: 'cccms:tenant' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { tenantDelete, tenantList, tenantSave, tenantUpdate, type TenantRow } from '@/api/tenant'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

interface Query {
  name: string
  code: string
  contact: string
  phone: string
  /** 列头枚举多选，值形如 `1,0` */
  status: string
  /** 到期时间范围（列头日期筛选写入） */
  start: string
  end: string
}

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'name', label: t('tenant.nameLabel'), minWidth: 180, slot: 'name', filter: { type: 'text' } },
  { prop: 'code', label: t('tenant.codeLabel'), minWidth: 140, filter: { type: 'text' } },
  { prop: 'contact', label: t('tenant.contactLabel'), minWidth: 110, filter: { type: 'text' } },
  { prop: 'phone', label: t('tenant.phoneLabel'), minWidth: 130, filter: { type: 'text' } },
  { prop: 'user_count', label: t('tenant.userCountLabel'), width: 90, align: 'center', slot: 'userCount' },
  {
    prop: 'expire_at',
    label: t('tenant.expireLabel'),
    minWidth: 170,
    slot: 'expire',
    filter: { type: 'date' },
    sortable: 'custom',
  },
  {
    prop: 'status',
    label: t('tenant.statusLabel'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('tenant.enabled'), value: 1 },
        { label: t('tenant.disabled'), value: 0 },
      ],
    },
  },
  { prop: 'action', label: t('table.action'), width: 140, fixed: 'right', slot: 'action', lockVisible: true },
])

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<TenantRow, Query>({
  api: (params) => tenantList(params),
  initialQuery: { name: '', code: '', contact: '', phone: '', status: '', start: '', end: '' },
})

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = { id: 0, name: '', code: '', contact: '', phone: '', expire_at: '', status: 1, remark: '' }
const form = reactive({ ...emptyForm })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('tenant.nameRequired'), trigger: 'blur' }],
  code: [{ required: true, message: t('tenant.codeRequired'), trigger: 'blur' }],
}))

function openCreate(): void {
  Object.assign(form, emptyForm)
  formVisible.value = true
}

function openEdit(record: TenantRow): void {
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
      await tenantUpdate({ ...form })
    } else {
      await tenantSave({ ...form })
    }
    ElMessage.success(t('tenant.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await tenantDelete(id)
  ElMessage.success(t('tenant.deleteSuccess'))
  load()
}
</script>

<style scoped>
.tenant-tag {
  margin-left: 6px;
}

.tenant-readonly {
  color: var(--art-muted);
}
</style>
