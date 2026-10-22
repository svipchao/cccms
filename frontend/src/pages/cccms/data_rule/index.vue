<template>
  <div class="art-fill">
    <el-alert class="rule-tip" type="info" :closable="false" show-icon :title="t('data_rule.tip')" />

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
        <el-button v-auth="'cccms:data_rule:save'" type="primary" @click="openCreate"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('data_rule.create') }}
        </el-button>
        <el-button v-auth="'cccms:data_rule:table_index'" @click="openTables"
          ><template #icon><i class="ri-settings-3-line" /></template>
          {{ t('data_rule.managedTables') }}
        </el-button>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('data_rule.recycleLabel')" @toggle="toggle" />
      </template>

      <template #target="{ row }">
        <el-tag v-if="row.table_name" effect="plain" size="small">
          {{ row.table_label || row.table_name }}
        </el-tag>
        <span v-else class="cond-empty">{{ t('data_rule.anyTable') }}</span>
      </template>

      <template #bind="{ row }">
        <span v-if="!hasBind(row)">{{ t('data_rule.global') }}</span>
        <template v-else>
          <!-- 组合方式打头：多行规则并排时，立刻能看出是「且」还是「或」 -->
          <el-tag
            size="small"
            :type="row.bind_mode === 'and' ? 'warning' : 'info'"
            class="bind-mode-tag"
            :title="row.bind_mode === 'and' ? t('data_rule.bindAndTip') : t('data_rule.bindOrTip')"
          >
            {{ row.bind_mode === 'and' ? t('data_rule.bindAnd') : t('data_rule.bindOr') }}
          </el-tag>
          <el-tag v-if="row.user_name" size="small" effect="plain">
            {{ t('data_rule.bindUser', { name: row.user_name }) }}
          </el-tag>
          <el-tag v-if="row.post_name" size="small" effect="plain" class="bind-tag">
            {{ t('data_rule.bindPost', { name: row.post_name }) }}
          </el-tag>
          <el-tag v-if="row.role_name" size="small" effect="plain" class="bind-tag">
            {{ t('data_rule.bindRole', { name: row.role_name }) }}
          </el-tag>
          <el-tag v-if="row.dept_names" size="small" effect="plain" class="bind-tag">
            {{ t('data_rule.bindDept', { name: row.dept_names.join('、') }) }}
          </el-tag>
        </template>
      </template>

      <template #conflict="{ row }">
        <el-tooltip v-if="row.conflicts?.length" placement="top">
          <template #content>
            <div v-for="(c, i) in row.conflicts" :key="i" class="conflict-line">{{ c.message }}</div>
          </template>
          <el-tag :type="hasUnsat(row) ? 'danger' : 'warning'" size="small" effect="light">
            {{ hasUnsat(row) ? t('data_rule.conflict') : t('data_rule.hint') }}
          </el-tag>
        </el-tooltip>
        <span v-else class="cond-empty">—</span>
      </template>

      <template #action_type="{ row }">
        <el-tag :type="actionTag(row.action)" effect="light" size="small">
          {{ actionLabel(row.action) }}
        </el-tag>
      </template>

      <template #condition="{ row }">
        <code v-if="row.action === 'row'" class="cond">
          {{ row.field }} {{ operatorLabel(row.operator) }} {{ row.value }}
        </code>
        <span v-else class="cond-empty">—</span>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:data_rule:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-popconfirm :title="t('data_rule.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:data_rule:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('data_rule.editTitle') : t('data_rule.createTitle')"
      width="660px"
      top="6vh"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
        <el-form-item :label="t('data_rule.nameLabel')" prop="name">
          <el-input v-model="form.name" :placeholder="t('data_rule.namePlaceholder')" />
        </el-form-item>

        <!-- 绑定对象：四个维度做成选项卡，已选项常驻在选项卡下方（切换选项卡也能看到） -->
        <el-form-item :label="t('data_rule.bindLabel')">
          <div class="bind-panel">
            <el-tabs v-model="bindTab" class="bind-tabs">
              <el-tab-pane :label="tabLabel('user')" name="user">
                <ArtNodePicker
                  v-model="draft.user"
                  :search="searchUsers"
                  :selected-row="selectedUser"
                  :label-fn="userLabel"
                  :placeholder="t('data_rule.userSearchPlaceholder')"
                />
                <div class="form-tip">{{ t('data_rule.userTip') }}</div>
              </el-tab-pane>

              <el-tab-pane :label="tabLabel('post')" name="post">
                <ArtNodePicker v-model="draft.post" :data="options.posts" />
                <div class="form-tip">{{ t('data_rule.postTip') }}</div>
              </el-tab-pane>

              <el-tab-pane :label="tabLabel('role')" name="role">
                <ArtNodePicker v-model="draft.role" :data="options.roles" />
                <div class="form-tip">
                  {{ t('data_rule.roleTipPrefix') }}<b>{{ t('data_rule.roleTipBold') }}</b
                  >{{ t('data_rule.roleTipSuffix') }}
                </div>
              </el-tab-pane>

              <el-tab-pane :label="tabLabel('dept')" name="dept">
                <ArtNodePicker v-model="draft.dept" :data="options.depts" multiple />
                <div class="form-tip">
                  {{ t('data_rule.deptTipPrefix') }}<b>{{ t('data_rule.deptTipBold') }}</b
                  >{{ t('data_rule.deptTipSuffix') }}
                </div>
              </el-tab-pane>
            </el-tabs>

            <div class="bind-add">
              <el-button type="primary" plain size="small" :disabled="!canAdd" @click="addBinding"
                ><template #icon><i class="ri-add-line" /></template>
                {{ t('data_rule.addToSelected') }}
              </el-button>
              <span class="form-tip bind-add-tip">{{ addHint }}</span>
            </div>

            <!-- 已选项：放在选项卡之外，切到哪个选项卡都看得见 -->
            <div class="bind-selected">
              <div class="bind-selected-head">
                <span>{{ t('data_rule.selectedBindings') }}</span>
                <el-button v-if="selectedGroups.length" link type="danger" size="small" @click="clearBindings">
                  {{ t('data_rule.clearAll') }}
                </el-button>
              </div>
              <template v-if="selectedGroups.length">
                <div v-for="group in selectedGroups" :key="group.key" class="bind-selected-row">
                  <span class="bind-selected-cat">{{ group.label }}</span>
                  <el-tag
                    v-for="item in group.items"
                    :key="item.key"
                    class="bind-chip"
                    type="primary"
                    effect="plain"
                    closable
                    @close="removeBinding(item)"
                  >
                    {{ item.name }}
                  </el-tag>
                </div>
              </template>
              <span v-else class="cond-empty">{{ t('data_rule.noneSelected') }}</span>
            </div>
          </div>
        </el-form-item>

        <!-- 绑定关系：单条规则内部的组合方式；默认 or 与历史行为一致 -->
        <el-form-item :label="t('data_rule.bindModeLabel')" prop="bind_mode">
          <el-radio-group v-model="form.bind_mode">
            <el-radio value="or">{{ t('data_rule.bindModeOr') }}</el-radio>
            <el-radio value="and">{{ t('data_rule.bindModeAnd') }}</el-radio>
          </el-radio-group>
          <div class="form-tip">
            {{ t('data_rule.bindModeTipPrefix') }}<b>{{ t('data_rule.bindModeTipBoldOr') }}</b
            >{{ t('data_rule.bindModeTipMiddle') }}<b>{{ t('data_rule.bindModeTipBoldAnd') }}</b
            >{{ t('data_rule.bindModeTipSuffix') }}<br />
            {{ t('data_rule.bindModeTipTail') }}
          </div>
        </el-form-item>

        <!-- 目标表 + 字段（级联选择） -->
        <el-form-item :label="t('data_rule.targetTableLabel')" prop="field">
          <el-cascader
            v-model="targetValue"
            :options="cascaderOptions"
            :props="{ expandTrigger: 'hover' }"
            filterable
            clearable
            class="target-cascader"
            :placeholder="t('data_rule.targetPlaceholder')"
          />
          <div class="form-tip">{{ fieldHint }}</div>
        </el-form-item>

        <el-form-item :label="t('data_rule.actionLabel')" prop="action">
          <el-select v-model="form.action" style="width: 100%">
            <el-option
              v-for="a in options.actions"
              :key="a"
              :label="t('data_rule.optionWithCode', { name: actionLabel(a), code: a })"
              :value="a"
            />
          </el-select>
          <div class="form-tip">{{ t('data_rule.actionTip') }}</div>
          <!-- 风险提示：encrypt 是「存储加密」而不是访问控制，容易被误当成敏感字段的防护手段 -->
          <el-alert
            v-if="form.action === 'encrypt'"
            type="warning"
            :closable="false"
            show-icon
            style="margin-top: 8px"
            :title="t('data_rule.encryptTitle')"
            :description="t('data_rule.encryptDesc')"
          />
        </el-form-item>

        <template v-if="form.action === 'row'">
          <el-form-item :label="t('data_rule.operatorLabel')" prop="operator">
            <el-select v-model="form.operator" style="width: 100%">
              <el-option
                v-for="op in operatorOptions"
                :key="op"
                :label="t('data_rule.optionWithCode', { name: operatorLabel(op), code: op })"
                :value="op"
              />
            </el-select>
          </el-form-item>
          <el-form-item :label="t('data_rule.valueTypeLabel')" prop="value_type">
            <el-radio-group v-model="form.value_type">
              <el-radio value="static">{{ t('data_rule.staticValue') }}</el-radio>
              <el-radio value="dynamic">{{ t('data_rule.dynamicValue') }}</el-radio>
            </el-radio-group>
            <div class="form-tip">
              {{ t('data_rule.valueTypeTipPrefix') }}<b>{{ t('data_rule.valueTypeTipBold') }}</b
              >{{ t('data_rule.valueTypeTipSuffix') }}
            </div>
          </el-form-item>

          <el-form-item :label="t('data_rule.valueLabel')" prop="value">
            <el-select
              v-if="form.value_type === 'dynamic'"
              v-model="valueTokens"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              :placeholder="t('data_rule.valuePlaceholder')"
            >
              <el-option
                v-for="v in options.value_vars ?? []"
                :key="v.value"
                :label="`${v.label}（${v.value}）`"
                :value="v.value"
              />
            </el-select>
            <el-input v-else v-model="form.value" :placeholder="t('data_rule.staticValuePlaceholder')" />
            <div class="form-tip">{{ valueHint }}</div>
          </el-form-item>
        </template>

        <el-form-item :label="t('data_rule.remarkLabel')" prop="remark">
          <el-input v-model="form.remark" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 受控表：只有登记在此的表才会出现在「目标表」候选里 -->
    <el-dialog
      v-model="tableVisible"
      :title="t('data_rule.managedTableTitle')"
      width="820px"
      top="8vh"
      :close-on-click-modal="false"
    >
      <el-alert
        class="rule-tip"
        type="info"
        :closable="false"
        show-icon
        :title="t('data_rule.managedTableTip')"
        :description="t('data_rule.managedTableDesc')"
      />

      <div class="tbl-add">
        <el-select
          v-model="addForm.table_name"
          filterable
          clearable
          :placeholder="t('data_rule.addTablePlaceholder')"
          class="tbl-add-select"
        >
          <el-option
            v-for="item in tableData.available"
            :key="item.table"
            :label="`${item.label}（${item.full}）`"
            :value="item.table"
          />
        </el-select>
        <el-input v-model="addForm.label" :placeholder="t('data_rule.semanticNamePlaceholder')" class="tbl-add-label" />
        <el-button
          v-auth="'cccms:data_rule:table_save'"
          type="primary"
          :disabled="!addForm.table_name"
          :loading="tableSaving"
          @click="onAddTable"
        >
          {{ t('data_rule.add') }}
        </el-button>
      </div>

      <el-table :data="tableData.list" size="small" border>
        <el-table-column :label="t('data_rule.tableNameLabel')" width="180">
          <template #default="{ row }">
            <span class="tbl-name">{{ row.table_name }}</span>
            <el-tag v-if="!row.exists" type="danger" size="small" effect="plain">
              {{ t('data_rule.tableMissing') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('data_rule.semanticNameLabel')" min-width="170">
          <template #default="{ row }">
            <el-input v-model="row.label" size="small" @change="onUpdateTable(row)" />
          </template>
        </el-table-column>
        <el-table-column :label="t('data_rule.remarkLabel')" min-width="150">
          <template #default="{ row }">
            <el-input v-model="row.remark" size="small" @change="onUpdateTable(row)" />
          </template>
        </el-table-column>
        <el-table-column prop="field_count" :label="t('data_rule.fieldCountLabel')" width="80" align="center" />
        <el-table-column :label="t('data_rule.controlledLabel')" width="80" align="center">
          <template #default="{ row }">
            <el-switch v-model="row.status" :active-value="1" :inactive-value="0" @change="onUpdateTable(row)" />
          </template>
        </el-table-column>
        <el-table-column :label="t('table.action')" width="80" align="center">
          <template #default="{ row }">
            <el-popconfirm :title="t('data_rule.removeConfirm')" @confirm="onRemoveTable(row)">
              <template #reference>
                <el-button v-auth="'cccms:data_rule:table_delete'" link type="danger">
                  {{ t('data_rule.remove') }}
                </el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
        <template #empty>{{ t('data_rule.noManagedTable') }}</template>
      </el-table>

      <template #footer>
        <el-button @click="tableVisible = false">{{ t('data_rule.close') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:data_rule' })

import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import ArtNodePicker from '@/components/core/ArtNodePicker.vue'
import { useTable } from '@/composables/useTable'
import {
  dataRuleDelete,
  dataRuleList,
  dataRuleOptions,
  dataRuleSave,
  dataRuleUpdate,
  dataRuleUsers,
  dataScopeTableDelete,
  dataScopeTableList,
  dataScopeTableSave,
  dataScopeTableUpdate,
  type DataRuleOption,
  type DataRuleOptions,
  type DataRuleRow,
  type DataScopeTableAvailable,
  type DataScopeTableRow,
} from '@/api/dataRule'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  name: string
  /** 列头枚举多选，值形如 `tbl1,tbl2` */
  table_name: string
  field: string
  /** 列头枚举多选，值形如 `row,hidden` */
  action: string
}

const { t } = useI18n({ useScope: 'global' })

/** 目标表 → 字段 的级联节点 */
interface CascadeNode {
  value: string
  label: string
  children?: CascadeNode[]
}

/** 规则动作 → 语言包 key（文案跟随语言切换） */
const ACTION_LABEL_KEYS: Record<string, string> = {
  row: 'data_rule.actionRow',
  hidden: 'data_rule.actionHidden',
  readonly: 'data_rule.actionReadonly',
  mask: 'data_rule.actionMask',
  encrypt: 'data_rule.actionEncrypt',
}

/** 操作符语义 → 语言包 key（后端 options.operator_labels 优先，这里做兜底） */
const OPERATOR_LABEL_KEYS: Record<string, string> = {
  '=': 'data_rule.opEq',
  '!=': 'data_rule.opNe',
  '<>': 'data_rule.opNe',
  '>': 'data_rule.opGt',
  '>=': 'data_rule.opGe',
  '<': 'data_rule.opLt',
  '<=': 'data_rule.opLe',
  like: 'data_rule.opLike',
  in: 'data_rule.opIn',
  between: 'data_rule.opBetween',
}

/** 这些类型用 like 没有意义；反过来，字符串列也不该出现比较操作符 */
const NUMERIC_TYPES = [
  'tinyint',
  'smallint',
  'mediumint',
  'int',
  'bigint',
  'decimal',
  'float',
  'double',
  'date',
  'datetime',
  'timestamp',
  'time',
  'year',
]

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 70 },
  { prop: 'name', label: t('data_rule.nameLabel'), minWidth: 140, filter: { type: 'text' } },
  {
    prop: 'table_name',
    label: t('data_rule.targetTableLabel'),
    width: 160,
    slot: 'target',
    filter: {
      type: 'enum',
      options: options.value.tables.map((item) => ({ label: item.label, value: item.table })),
    },
  },
  { prop: 'field', label: t('data_rule.fieldLabel'), width: 120, filter: { type: 'text' } },
  { prop: 'bind', label: t('data_rule.bindLabel'), minWidth: 200, slot: 'bind' },
  // prop 用 action_type 而不是 action：下面还有一个「操作」列，重复 key 会让 v-for 出问题
  // 列头筛选写回的是 query.action（后端按 action 过滤），故用 queryKey 指回
  {
    prop: 'action_type',
    label: t('data_rule.actionColumnLabel'),
    width: 106,
    align: 'center',
    slot: 'action_type',
    filter: {
      type: 'enum',
      queryKey: 'action',
      options: options.value.actions.map((a) => ({ label: actionLabel(a), value: a })),
    },
  },
  { prop: 'condition', label: t('data_rule.conditionLabel'), minWidth: 150, slot: 'condition' },
  // 体检结果：冲突这类问题「配的时候看不出来、用的时候页面空白」，列表上直接标出来
  { prop: 'conflict', label: t('data_rule.conflictColumnLabel'), width: 78, align: 'center', slot: 'conflict' },
  { prop: 'action', label: t('table.action'), width: 130, fixed: 'right', slot: 'action', lockVisible: true },
])

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('data_rule', {
  reload: () => load(),
})

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<DataRuleRow, Query>({
  api: (params) => dataRuleList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '', table_name: '', field: '', action: '' },
})

const options = ref<DataRuleOptions>({
  users: [],
  posts: [],
  roles: [],
  depts: [],
  tables: [],
  actions: ['row', 'hidden', 'readonly', 'mask', 'encrypt'],
  operators: ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'between'],
  operator_labels: {},
  value_types: ['static', 'dynamic'],
  value_vars: [],
})

async function loadOptions(): Promise<void> {
  options.value = await dataRuleOptions()
  primeBindNames()
}

const actionLabel = (action?: string): string => {
  const key = ACTION_LABEL_KEYS[action ?? '']
  return key ? t(key) : (action ?? '-')
}

/** 操作符语义化展示：优先用后端返回的映射，缺失时回退到本地语言包 */
function operatorLabel(operator?: string): string {
  const backend = options.value.operator_labels?.[operator ?? '']
  if (backend) {
    return backend
  }
  const key = OPERATOR_LABEL_KEYS[operator ?? '']
  return key ? t(key) : (operator ?? '-')
}

function actionTag(action?: string): 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  switch (action) {
    case 'row':
      return 'primary'
    case 'hidden':
      return 'warning'
    case 'readonly':
      return 'info'
    case 'encrypt':
      return 'danger'
    default:
      return 'success'
  }
}

function hasBind(row: DataRuleRow): boolean {
  return !!(row.user_id || row.post_id || row.role_id || (row.dept_ids?.length ?? 0) > 0)
}

/** 体检结果里是否有「条件互斥」：这类会让命中的用户什么都看不到，比其它提示更严重 */
function hasUnsat(row: DataRuleRow): boolean {
  return (row.conflicts ?? []).some((c) => c.type === 'row_unsat')
}

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  name: '',
  // 绑定项留空而非 0：没有选中时显示 placeholder，而不是「0」
  user_id: null as number | null,
  post_id: null as number | null,
  role_id: null as number | null,
  dept_ids: [] as number[],
  /** 绑定组合方式：or=任一命中（历史行为，默认）；and=已填写的全部命中 */
  bind_mode: 'or',
  table_name: '',
  field: '',
  action: 'row',
  operator: '=',
  value: '',
  value_type: 'static',
  remark: '',
}
const form = reactive({ ...emptyForm })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('data_rule.nameRequired'), trigger: 'blur' }],
  field: [{ required: true, message: t('data_rule.fieldRequired'), trigger: 'change' }],
}))

/** 目标表 → 字段 的级联候选；「不限表」用 __none__ 表示，子节点是各表字段并集 */
const cascaderOptions = computed<CascadeNode[]>(() => {
  const tables = options.value.tables ?? []

  const union: CascadeNode[] = []
  const seen = new Set<string>()
  for (const table of tables) {
    for (const f of table.fields) {
      if (seen.has(f.field)) {
        continue
      }
      seen.add(f.field)
      union.push({
        value: f.field,
        label: f.label === f.field ? f.field : `${f.field}（${f.label}）`,
      })
    }
  }

  return [
    { value: '__none__', label: t('data_rule.anyTableOption'), children: union },
    ...tables.map((table) => ({
      value: table.table,
      label: `${table.label}（${table.full}）`,
      children: table.fields.map((f) => ({
        value: f.field,
        label: f.label === f.field ? f.field : `${f.field}（${f.label}）`,
      })),
    })),
  ]
})

/** 级联选择器绑定：[目标表, 字段] ↔ form.table_name / form.field */
const targetValue = computed<string[]>({
  get: () => [form.table_name || '__none__', form.field || ''],
  set: (val) => {
    const [table = '__none__', field = ''] = Array.isArray(val) ? val : []
    form.table_name = table === '__none__' ? '' : table
    form.field = field
  },
})

const currentFieldType = computed(() => {
  const tables = options.value.tables ?? []
  if (form.table_name) {
    const chosen = tables.find((table) => table.table === form.table_name)
    return chosen?.fields.find((f) => f.field === form.field)?.type ?? ''
  }
  for (const table of tables) {
    const hit = table.fields.find((f) => f.field === form.field)
    if (hit) {
      return hit.type
    }
  }
  return ''
})

const operatorOptions = computed(() => {
  const all = options.value.operators ?? []
  const type = currentFieldType.value
  if (!type) {
    return all
  }
  return NUMERIC_TYPES.includes(type)
    ? all.filter((op) => op !== 'like')
    : all.filter((op) => !['>', '>=', '<', '<='].includes(op))
})

// 切换字段后原来的操作符可能不再适用
watch(operatorOptions, (available) => {
  if (!available.includes(form.operator)) {
    form.operator = '='
  }
})

const fieldHint = computed(() => {
  if (!form.table_name) {
    return t('data_rule.fieldHintAnyTable')
  }
  return t('data_rule.fieldHintTable')
})

/* ---- 行级取值：动态变量 ---- */

/** 动态取值在界面上是多选的 token，落库仍是逗号分隔字符串 */
const valueTokens = computed<string[]>({
  get: () =>
    form.value
      ? String(form.value)
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean)
      : [],
  set: (val) => {
    form.value = (Array.isArray(val) ? val : []).join(',')
  },
})

const valueHint = computed(() => {
  if (form.value_type !== 'dynamic') {
    return t('data_rule.valueHintStatic')
  }
  if (form.operator === 'between') {
    return t('data_rule.valueHintBetween')
  }
  if (form.operator === 'in') {
    return t('data_rule.valueHintIn')
  }
  return t('data_rule.valueHintOther')
})

/* ---- 绑定对象：选项卡 + 已选列表 ---- */

/** 四个绑定维度 */
type BindKey = 'user' | 'post' | 'role' | 'dept'

/** 四个绑定维度的展示名 → 语言包 key */
const BIND_LABEL_KEYS: Record<BindKey, string> = {
  user: 'data_rule.bindUserLabel',
  post: 'data_rule.bindPostLabel',
  role: 'data_rule.bindRoleLabel',
  dept: 'data_rule.bindDeptLabel',
}

/** 当前选项卡 */
const bindTab = ref<BindKey>('user')

/**
 * 各选项卡里「待添加」的选择，与 form 里已选的值**分开**：
 * 在树里点选不会立刻改规则，必须点「添加到已选」才生效 —— 避免误点即改动。
 */
const draft = reactive<Record<BindKey, number | number[] | null>>({
  user: null,
  post: null,
  role: null,
  dept: [],
})

/** id → 展示名：把已选项渲染成人能看懂的标签（岗位/角色/部门候选已全量在手，用户靠搜索结果缓存） */
const bindNames = reactive<Record<BindKey, Record<number, string>>>({
  user: {},
  post: {},
  role: {},
  dept: {},
})

/** 展平树形候选（角色 / 部门是多层），登记 id 与名称 */
function collectNames(rows: Array<Record<string, any>> | undefined, into: Record<number, string>): void {
  for (const row of rows ?? []) {
    const id = Number(row?.id)
    if (id > 0) {
      into[id] = String(row?.name ?? `#${id}`)
    }
    if (Array.isArray(row?.children) && row.children.length) {
      collectNames(row.children, into)
    }
  }
}

/** 候选加载完就登记名称，已选标签才能在编辑时立刻显示名称 */
function primeBindNames(): void {
  collectNames(options.value.posts, bindNames.post)
  collectNames(options.value.roles, bindNames.role)
  collectNames(options.value.depts, bindNames.dept)
}

/** 各维度已选值（统一成数组，便于渲染与去重） */
const selectedIds = computed<Record<BindKey, number[]>>(() => ({
  user: Number(form.user_id) > 0 ? [Number(form.user_id)] : [],
  post: Number(form.post_id) > 0 ? [Number(form.post_id)] : [],
  role: Number(form.role_id) > 0 ? [Number(form.role_id)] : [],
  dept: (form.dept_ids ?? []).map(Number).filter((id: number) => id > 0),
}))

interface BindItem {
  key: string
  category: BindKey
  id: number
  name: string
}

/** 已选项按维度分组：每个维度一行，多选（部门）就是一行多个标签 */
const selectedGroups = computed(() =>
  (Object.keys(BIND_LABEL_KEYS) as BindKey[])
    .map((category) => ({
      key: category,
      label: t(BIND_LABEL_KEYS[category]),
      items: selectedIds.value[category].map<BindItem>((id) => ({
        key: `${category}-${id}`,
        category,
        id,
        name: bindNames[category][id] || `#${id}`,
      })),
    }))
    .filter((group) => group.items.length > 0),
)

/** 选项卡标题带已选数量：切到别的选项卡也知道哪些维度已选 */
function tabLabel(key: BindKey): string {
  const count = selectedIds.value[key].length
  return count > 0 ? t('data_rule.bindTabWithCount', { name: t(BIND_LABEL_KEYS[key]), count }) : t(BIND_LABEL_KEYS[key])
}

/** 当前选项卡的待添加项是否有值 */
const canAdd = computed(() => {
  const value = draft[bindTab.value]
  return Array.isArray(value) ? value.length > 0 : Number(value) > 0
})

const addHint = computed(() => (bindTab.value === 'dept' ? t('data_rule.addHintMulti') : t('data_rule.addHintSingle')))

/** 添加到已选：单选维度替换，部门并入 */
function addBinding(): void {
  const category = bindTab.value
  const raw = draft[category]
  const ids = (Array.isArray(raw) ? raw : [raw]).map(Number).filter((id) => id > 0)
  if (ids.length === 0) {
    return
  }

  if (category === 'user') {
    form.user_id = ids[0]
  } else if (category === 'post') {
    form.post_id = ids[0]
  } else if (category === 'role') {
    form.role_id = ids[0]
  } else {
    form.dept_ids = [...new Set([...selectedIds.value.dept, ...ids])].sort((a, b) => a - b)
  }

  // 已选项与待添加项对齐，避免出现「树里勾着、其实没添加」的错觉
  draft[category] = category === 'dept' ? [...form.dept_ids] : ids[0]
}

/** 移除已选：同时把该维度待添加项里的同一项去掉，保持两个区域一致 */
function removeBinding(item: BindItem): void {
  const pending = draft[item.category]

  if (item.category === 'user') {
    form.user_id = null
  } else if (item.category === 'post') {
    form.post_id = null
  } else if (item.category === 'role') {
    form.role_id = null
  } else {
    form.dept_ids = selectedIds.value.dept.filter((id) => id !== item.id)
  }

  if (Array.isArray(pending)) {
    draft[item.category] = pending.map(Number).filter((id) => id !== item.id)
  } else if (Number(pending) === item.id) {
    draft[item.category] = null
  }
}

/** 清空全部绑定（回到「全局规则」） */
function clearBindings(): void {
  form.user_id = null
  form.post_id = null
  form.role_id = null
  form.dept_ids = []
  draft.user = null
  draft.post = null
  draft.role = null
  draft.dept = []
}

/** 打开表单时把待添加项对齐到当前值，让选项卡里直接看到现状 */
function resetBindDraft(): void {
  draft.user = selectedIds.value.user[0] ?? null
  draft.post = selectedIds.value.post[0] ?? null
  draft.role = selectedIds.value.role[0] ?? null
  draft.dept = [...selectedIds.value.dept]
  bindTab.value = 'user'
}

/* ---- 绑定用户：远程模糊搜索（ArtNodePicker 自己防抖，这里只管取数与回显） ---- */
/** 已选中的用户（回显用：结果集里未必包含它，ArtNodePicker 会把它钉在列表最前） */
const selectedUser = ref<DataRuleOption | null>(null)

/** 用户行展示：账号（昵称）——作为 ArtNodePicker 的 labelFn */
function userLabel(u: Record<string, any>): string {
  return u.nickname ? `${u.username}（${u.nickname}）` : (u.username ?? String(u.id))
}

async function searchUsers(keyword: string): Promise<DataRuleOption[]> {
  const rows = await dataRuleUsers({ keyword })
  // 顺手缓存名称：已选列表要把 user_id 渲染成账号
  for (const row of rows) {
    bindNames.user[Number(row.id)] = userLabel(row)
  }

  return rows
}

/* ---- 受控表维护 ---- */
const tableVisible = ref(false)
const tableSaving = ref(false)
const tableData = reactive<{ list: DataScopeTableRow[]; available: DataScopeTableAvailable[] }>({
  list: [],
  available: [],
})
const addForm = reactive({ table_name: '', label: '' })

async function loadTables(): Promise<void> {
  const data = await dataScopeTableList()
  tableData.list = data.list
  tableData.available = data.available
}

async function openTables(): Promise<void> {
  tableVisible.value = true
  await loadTables()
}

async function onAddTable(): Promise<void> {
  if (!addForm.table_name) {
    return
  }
  tableSaving.value = true
  try {
    await dataScopeTableSave({ table_name: addForm.table_name, label: addForm.label })
    ElMessage.success(t('data_rule.tableAdded'))
    addForm.table_name = ''
    addForm.label = ''
    await loadTables()
    // 目标表候选变了，重新拉一次
    await loadOptions()
  } finally {
    tableSaving.value = false
  }
}

async function onUpdateTable(row: DataScopeTableRow): Promise<void> {
  await dataScopeTableUpdate({
    id: row.id,
    label: row.label,
    status: row.status,
    remark: row.remark,
  })
  ElMessage.success(t('data_rule.tableSaved'))
  await loadOptions()
}

async function onRemoveTable(row: DataScopeTableRow): Promise<void> {
  const res = await dataScopeTableDelete(row.id)
  const paused = res?.rules ?? 0
  ElMessage.success(paused > 0 ? t('data_rule.tableRemovedWithRules', { count: paused }) : t('data_rule.tableRemoved'))
  await loadTables()
  await loadOptions()
}

function openCreate(): void {
  Object.assign(form, emptyForm, { dept_ids: [] })
  selectedUser.value = null
  resetBindDraft()
  formVisible.value = true
}

function openEdit(record: DataRuleRow): void {
  Object.assign(form, emptyForm, record, {
    dept_ids: [...(record.dept_ids ?? [])],
    table_name: record.table_name ?? '',
    // 0 = 不限：转成 null，让控件显示 placeholder
    user_id: record.user_id || null,
    post_id: record.post_id || null,
    role_id: record.role_id || null,
    value_type: record.value_type || 'static',
    // 兜底：字段缺失或为空时按 or（与后端判定、DB 默认值三者一致）
    bind_mode: record.bind_mode || 'or',
  })
  // 回显已绑定的用户（ArtNodePicker 会把它钉在列表最前）
  selectedUser.value = record.user_id
    ? { id: record.user_id, username: record.user_name || `#${record.user_id}` }
    : null
  if (record.user_id) {
    bindNames.user[Number(record.user_id)] = userLabel(selectedUser.value!)
  }
  // 待添加项对齐到当前值，选项卡里直接看到现状
  resetBindDraft()
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    // 显式构造提交体，避免把绑定名称等只读字段回传
    const payload: Record<string, unknown> = {
      id: form.id,
      name: form.name,
      user_id: form.user_id || 0,
      post_id: form.post_id || 0,
      role_id: form.role_id || 0,
      dept_ids: form.dept_ids,
      bind_mode: form.bind_mode || 'or',
      table_name: form.table_name,
      field: form.field,
      action: form.action,
      operator: form.action === 'row' ? form.operator : '=',
      value: form.action === 'row' ? form.value : '',
      value_type: form.action === 'row' ? form.value_type || 'static' : 'static',
      remark: form.remark,
    }

    if (form.id) {
      await dataRuleUpdate(payload)
    } else {
      await dataRuleSave(payload)
    }
    ElMessage.success(t('data_rule.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await dataRuleDelete(id)
  ElMessage.success(t('data_rule.deleteSuccess'))
  load()
}

loadOptions()
</script>

<style scoped>
.rule-tip {
  flex-shrink: 0;
  margin-bottom: 12px;
}

.bind-tag {
  margin-left: 2px;
}

.cond {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-main);
}

.cond-empty {
  color: var(--art-muted);
}

.target-cascader {
  width: 100%;
}

.tbl-add {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.tbl-add-select {
  width: 320px;
}

.tbl-add-label {
  width: 220px;
}

.tbl-name {
  margin-right: 6px;
}

.form-tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

/* 绑定对象面板：选项卡（选）+ 添加按钮 + 已选列表（在选项卡之外，切选项卡始终可见） */
.bind-panel {
  width: 100%;
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) - 2px);
}

.bind-tabs :deep(.el-tabs__header) {
  margin: 0;
  padding: 0 10px;
}

.bind-tabs :deep(.el-tabs__content) {
  padding: 10px 10px 0;
}

.bind-add {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 10px;
}

.bind-add-tip {
  margin-top: 0;
}

.bind-selected {
  padding: 8px 10px 10px;
  border-top: 1px solid var(--art-card-border);
}

.bind-selected-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--art-muted);
}

.bind-selected-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}

.bind-selected-row + .bind-selected-row {
  margin-top: 6px;
}

/* 维度名固定宽度，多个维度对齐后更好扫读 */
.bind-selected-cat {
  flex: none;
  width: 32px;
  font-size: 12px;
  color: var(--art-muted);
}

.bind-chip {
  margin-bottom: 0;
}

/* 绑定对象列里的组合方式标记（且 / 或），与后面的绑定项 tag 拉开一点距离 */
.bind-mode-tag {
  margin-right: 4px;
}

/* 「检测」列 tooltip 里的多条提示 */
.conflict-line + .conflict-line {
  margin-top: 4px;
}
</style>
