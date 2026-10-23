<template>
  <div class="art-node-picker">
    <!-- 上面：按钮 / 搜索区（与角色管理的「权限节点」同一套结构） -->
    <div class="art-node-picker-tools">
      <template v-if="search">
        <el-input
          v-model="keyword"
          size="small"
          clearable
          :placeholder="searchPlaceholder"
          class="art-node-picker-search"
          @input="onKeywordInput"
        />
        <el-button size="small" @click="clear">{{ t('core.common.nodePicker.clear') }}</el-button>
      </template>
      <template v-else>
        <el-button v-if="hasChildren" size="small" @click="toggleExpand">
          {{ expanded ? t('core.common.nodePicker.collapseAll') : t('core.common.nodePicker.expandAll') }}
        </el-button>
        <el-button v-if="multiple" size="small" @click="checkAll">{{
          t('core.common.nodePicker.selectAll')
        }}</el-button>
        <el-button size="small" @click="clear">{{ t('core.common.nodePicker.clear') }}</el-button>
      </template>
    </div>

    <!-- 下面：节点区（高度刻意压低，表单里不显笨重） -->
    <div class="art-node-picker-body">
      <!-- 远程模式（用户）：搜索结果的扁平勾选列表 -->
      <template v-if="search">
        <div v-if="rows.length === 0" class="art-node-picker-empty">{{ emptyText }}</div>
        <template v-else>
          <label
            v-for="row in rows"
            :key="keyOf(row)"
            class="art-node-picker-item"
            :class="{ 'is-checked': selectedKeys.includes(keyOf(row)) }"
          >
            <input
              :type="multiple ? 'checkbox' : 'radio'"
              :checked="selectedKeys.includes(keyOf(row))"
              @change="toggle(keyOf(row))"
            />
            <span class="art-node-picker-item-label">{{ labelOf(row) }}</span>
          </label>
        </template>
      </template>

      <!-- 本地树（岗位 / 角色 / 部门） -->
      <!-- default-expand-all 必须绑上：它只在初始化生效，靠上面的 :key 重挂载来切换展开状态 -->
      <el-tree
        v-else
        ref="treeRef"
        :key="treeKey"
        :default-expand-all="expanded"
        class="art-node-picker-tree"
        :data="data"
        :props="treeProps"
        :node-key="nodeKey"
        show-checkbox
        check-strictly
        :expand-on-click-node="true"
        :check-on-click-node="false"
        @check="onCheck"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { ElTree } from 'element-plus'
import { useI18n } from 'vue-i18n'

type NodeRow = Record<string, any>

const { t } = useI18n({ useScope: 'global' })

/**
 * 节点选择器（与角色管理「权限节点」同一套结构：上面按钮、下面节点）。
 *
 * 两种模式：
 *   - 树模式（岗位 / 角色 / 部门）：传入 `data`，顶部是「展开 / 全选（多选）/ 清空」，
 *     下面是一棵 check-strictly 的复选树；
 *   - 远程模式（用户）：传入 `search`，顶部是搜索框，下面是匹配用户的勾选列表；
 *     已选中的用户会被钉在列表最前，免得结果集里没有它就「看不见已选」。
 *
 * 值：单选 number / null，多选 number[]（0 视为未选）。
 */
const props = withDefaults(
  defineProps<{
    modelValue?: number | number[] | null
    /** 树数据（树模式） */
    data?: NodeRow[]
    /** 远程搜索（远程模式）：按关键词返回匹配行 */
    search?: (keyword: string) => Promise<NodeRow[]>
    /** 已选中行（远程模式回显：结果集里未必包含它） */
    selectedRow?: NodeRow | null
    /** 自定义展示文本，默认取 labelField */
    labelFn?: (row: NodeRow) => string
    nodeKey?: string
    labelField?: string
    multiple?: boolean
    /** 搜索框占位文案；缺省时用内置翻译 */
    placeholder?: string
  }>(),
  {
    modelValue: null,
    data: () => [],
    selectedRow: null,
    nodeKey: 'id',
    labelField: 'name',
    multiple: false,
    placeholder: '',
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: number | number[] | null] }>()

const keyOf = (row: NodeRow): number => Number(row[props.nodeKey])
const labelOf = (row: NodeRow): string => (props.labelFn ? props.labelFn(row) : String(row[props.labelField] ?? ''))

/** 已选 key（0 / NaN 视为未选） */
const selectedKeys = computed<number[]>(() => {
  const value = props.modelValue
  if (value === null || value === undefined) {
    return []
  }
  return (Array.isArray(value) ? value : [value]).map(Number).filter((k) => Number.isInteger(k) && k > 0)
})

function applyEmit(keys: number[]): void {
  emit('update:modelValue', props.multiple ? keys : (keys[0] ?? null))
}

function clear(): void {
  if (props.search) {
    keyword.value = ''
    matched.value = []
    applyEmit([])
    return
  }
  treeRef.value?.setCheckedKeys([])
  applyEmit([])
}

/* ---- 树模式（岗位 / 角色 / 部门） ---- */

const treeRef = ref<InstanceType<typeof ElTree>>()
const expanded = ref(false)
/** el-tree 的 default-expand-all 只在初始化生效，用 key 强制重挂载来切换 */
const treeKey = ref(0)
const treeProps = computed(() => ({ label: props.labelField, children: 'children' }))

/** 数据里有没有子节点；全是扁平列表时「展开/折叠」按钮没意义 */
const hasChildren = computed(() =>
  (props.data ?? []).some((node) => Array.isArray(node.children) && node.children.length > 0),
)

function syncTreeChecked(): void {
  void nextTick(() => {
    treeRef.value?.setCheckedKeys(props.multiple ? selectedKeys.value : selectedKeys.value.slice(0, 1))
  })
}

function onCheck(_node: NodeRow, info: { checkedKeys: Array<string | number> }): void {
  const keys = (info?.checkedKeys ?? []).map(Number)
  if (props.multiple) {
    applyEmit(keys)
    return
  }
  // 单选：勾新节点自动取消旧的；取消当前节点则清空
  const previous = selectedKeys.value[0] ?? null
  const next = keys.find((key) => key !== previous) ?? null
  treeRef.value?.setCheckedKeys(next === null ? [] : [next])
  applyEmit(next === null ? [] : [next])
}

function toggleExpand(): void {
  const checked = (treeRef.value?.getCheckedKeys() ?? []) as Array<string | number>
  expanded.value = !expanded.value
  treeKey.value += 1
  nextTick(() => treeRef.value?.setCheckedKeys(checked))
}

function collectKeys(nodes: NodeRow[]): number[] {
  const out: number[] = []
  const walk = (items: NodeRow[]): void => {
    for (const item of items) {
      out.push(Number(item[props.nodeKey]))
      if (item.children?.length) {
        walk(item.children)
      }
    }
  }
  walk(nodes)
  return out
}

function checkAll(): void {
  const keys = collectKeys(props.data ?? [])
  treeRef.value?.setCheckedKeys(keys)
  applyEmit(keys)
}

/* ---- 远程模式（用户） ---- */

const keyword = ref('')
const matched = ref<NodeRow[]>([])
const searching = ref(false)
let searchTimer = 0

function onKeywordInput(): void {
  window.clearTimeout(searchTimer)
  const kw = keyword.value.trim()
  if (!kw) {
    matched.value = []
    return
  }
  searchTimer = window.setTimeout(async () => {
    searching.value = true
    try {
      matched.value = await props.search!(kw)
    } finally {
      searching.value = false
    }
  }, 250)
}

/** 展示行：已选中的钉在最前，结果集里没有它也能保持可见 + 保持勾选 */
const rows = computed<NodeRow[]>(() => {
  const out: NodeRow[] = []
  const selected = props.selectedRow
  if (selected && !matched.value.some((row) => keyOf(row) === keyOf(selected))) {
    out.push(selected)
  }
  out.push(...matched.value)
  return out
})

const searchPlaceholder = computed(() => props.placeholder || t('core.common.nodePicker.searchPlaceholder'))

const emptyText = computed(() => {
  if (keyword.value.trim()) {
    return searching.value ? t('core.common.nodePicker.searching') : t('core.common.nodePicker.noMatchUser')
  }
  return props.selectedRow ? '' : searchPlaceholder.value
})

function toggle(key: number): void {
  if (props.multiple) {
    const set = new Set(selectedKeys.value)
    if (set.has(key)) {
      set.delete(key)
    } else {
      set.add(key)
    }
    applyEmit([...set])
    return
  }
  applyEmit(selectedKeys.value.includes(key) ? [] : [key])
}

watch(() => props.modelValue, syncTreeChecked, { deep: true })
watch(() => props.data, syncTreeChecked, { deep: true })
onMounted(syncTreeChecked)
</script>

<style scoped>
.art-node-picker {
  width: 100%;
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) - 2px);
}

.art-node-picker-tools {
  display: flex;
  gap: 6px;
  align-items: center;
  padding: 8px 10px;
  border-bottom: 1px solid var(--art-card-border);
}

.art-node-picker-search {
  flex: 1;
}

/* 节点区高度刻意压低：表单里有四个这样的块，太高会很笨重 */
.art-node-picker-body {
  max-height: 148px;
  padding: 6px 8px;
  overflow-y: auto;
}

.art-node-picker-empty {
  padding: 12px 4px;
  font-size: 12px;
  color: var(--art-muted);
  text-align: center;
}

.art-node-picker-item {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 4px 6px;
  font-size: 13px;
  cursor: pointer;
  border-radius: 4px;
}

.art-node-picker-item:hover {
  background: var(--art-primary-light, var(--el-color-primary-light-9));
}

.art-node-picker-item.is-checked {
  color: var(--art-primary);
}

.art-node-picker-item-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
