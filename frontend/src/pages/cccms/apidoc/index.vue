<template>
  <div class="art-fill apidoc">
    <!-- 头部：标题 + 搜索 + 导出。搜索只过滤左侧树，不影响已选中的详情 -->
    <div class="apidoc__head">
      <div class="apidoc__title">
        <h2>{{ t('cccms.apidoc.title') }}</h2>
        <p>{{ t('cccms.apidoc.subtitle', { total }) }}</p>
      </div>
      <div class="apidoc__tools">
        <el-input
          v-model="keyword"
          :placeholder="t('cccms.apidoc.searchPlaceholder')"
          clearable
          class="apidoc__search"
        />
        <el-tooltip :content="t('cccms.apidoc.exportHint')" placement="top">
          <el-button v-auth="'cccms:apidoc:spec'" :loading="exporting" @click="onExport">
            {{ t('cccms.apidoc.exportSpec') }}
          </el-button>
        </el-tooltip>
      </div>
    </div>

    <div class="apidoc__body">
      <aside class="apidoc__side">
        <el-scrollbar>
          <!-- v-if 等到数据就绪再挂载：default-expanded-keys 只在建树时读一次，
               先挂空树再塞数据会导致第一层不展开 -->
          <el-tree
            v-if="treeData.length > 0"
            ref="treeRef"
            :data="treeData"
            :props="{ label: 'label', children: 'children' }"
            node-key="id"
            highlight-current
            :default-expanded-keys="expandedKeys"
            :expand-on-click-node="false"
            :filter-node-method="filterNode"
            @node-click="onNodeClick"
          >
            <template #default="{ data }">
              <span v-if="data.isPlugin" class="apidoc__plugin">
                {{ data.label }}
                <small>{{ data.count || 0 }}</small>
              </span>
              <span v-else-if="data.isGroup" class="apidoc__group">
                {{ data.label }}
                <small>{{ data.count || 0 }}</small>
              </span>
              <span v-else class="apidoc__op">
                <em :class="`is-${String(data.method).toLowerCase()}`">{{ data.method }}</em>
                <span class="apidoc__op-path">{{ data.path }}</span>
              </span>
            </template>
          </el-tree>
          <el-empty v-if="treeData.length === 0" :description="t('cccms.apidoc.empty')" :image-size="60" />
        </el-scrollbar>
      </aside>

      <section class="apidoc__main">
        <el-empty v-if="!detail" :description="t('cccms.apidoc.selectHint')" />
        <template v-else>
          <header class="apidoc__detail-head">
            <div class="apidoc__detail-line">
              <em :class="`is-${detail.method.toLowerCase()}`">{{ detail.method }}</em>
              <code class="apidoc__detail-path">{{ detail.path }}</code>
            </div>
            <h3>{{ detail.summary }}</h3>
            <div class="apidoc__meta">
              <span class="apidoc__tag">{{ t('cccms.apidoc.permission') }}：{{ detail.slug || '—' }}</span>
              <span class="apidoc__tag">{{ t('cccms.apidoc.auth') }}：{{ authLabel }}</span>
            </div>
          </header>

          <el-divider content-position="left">{{ t('cccms.apidoc.params') }}</el-divider>
          <el-alert
            v-if="detail.unbounded"
            type="warning"
            :closable="false"
            show-icon
            :title="t('cccms.apidoc.unbounded')"
            class="apidoc__alert"
          />
          <el-table v-if="detail.params.length > 0" :data="detail.params" size="small" border>
            <el-table-column prop="name" :label="t('cccms.apidoc.paramName')" min-width="160" />
            <el-table-column :label="t('cccms.apidoc.paramIn')" width="110">
              <template #default="{ row }">
                {{ row.in === 'body' ? t('cccms.apidoc.body') : t('cccms.apidoc.query') }}
              </template>
            </el-table-column>
            <el-table-column prop="type" :label="t('cccms.apidoc.paramType')" width="110" />
            <el-table-column :label="t('cccms.apidoc.paramRequired')" width="90">
              <template #default="{ row }">
                <el-tag :type="row.required ? 'danger' : 'info'" size="small" effect="plain">
                  {{ row.required ? t('cccms.apidoc.paramYes') : t('cccms.apidoc.paramNo') }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column :label="t('cccms.apidoc.paramEnum')" min-width="200">
              <template #default="{ row }">
                <div v-if="row.enum?.length" class="apidoc__enum">
                  <el-tag
                    v-for="option in row.enum"
                    :key="String(option.value)"
                    size="small"
                    effect="plain"
                    :title="`${option.value}${option.label ? '：' + option.label : ''}`"
                  >
                    {{ option.label ? `${option.label}(${option.value})` : option.value }}
                  </el-tag>
                </div>
                <span v-else class="apidoc__muted">{{ t('cccms.apidoc.enumNone') }}</span>
              </template>
            </el-table-column>
            <el-table-column :label="t('cccms.apidoc.paramComment')" min-width="160">
              <template #default="{ row }">
                <span :class="{ apidoc__muted: !row.description }">{{ row.description || '—' }}</span>
              </template>
            </el-table-column>
          </el-table>
          <p v-else class="apidoc__muted">{{ t('cccms.apidoc.noParams') }}</p>

          <el-divider content-position="left">{{ t('cccms.apidoc.response') }}</el-divider>
          <p class="apidoc__muted">{{ t('cccms.apidoc.responseHint') }}</p>
          <template v-if="detail.response?.fields?.length">
            <el-alert
              type="info"
              :closable="false"
              show-icon
              :title="t('cccms.apidoc.responseInferred', { table: detail.response.table })"
              class="apidoc__alert apidoc__alert--tight"
            />
            <el-table :data="detail.response.fields" size="small" border max-height="320">
              <el-table-column prop="name" :label="t('cccms.apidoc.fieldName')" min-width="150" />
              <el-table-column prop="type" :label="t('cccms.apidoc.fieldType')" width="110" />
              <el-table-column :label="t('cccms.apidoc.fieldEnum')" min-width="220">
                <template #default="{ row }">
                  <div v-if="row.enum?.length" class="apidoc__enum">
                    <el-tag
                      v-for="option in row.enum"
                      :key="String(option.value)"
                      size="small"
                      effect="plain"
                      :title="`${option.value}${option.label ? '：' + option.label : ''}`"
                    >
                      {{ option.label ? `${option.label}(${option.value})` : option.value }}
                    </el-tag>
                  </div>
                  <span v-else class="apidoc__muted">{{ t('cccms.apidoc.enumNone') }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="comment" :label="t('cccms.apidoc.fieldComment')" min-width="180" />
            </el-table>
          </template>

          <el-divider content-position="left">{{ t('cccms.apidoc.tryItOut') }}</el-divider>
          <el-alert type="info" :closable="false" show-icon :title="t('cccms.apidoc.tryHint')" class="apidoc__alert" />
          <el-form label-width="140px" class="apidoc__form">
            <el-form-item v-for="param in detail.params" :key="`${param.in}-${param.name}`" :label="param.name">
              <!-- 有枚举就用下拉，省得手打「1/0」猜半天；值仍按字符串存，提交前统一 cast -->
              <el-select
                v-if="param.enum?.length"
                v-model="form[paramKey(param)]"
                :placeholder="`${param.type}${param.required ? ' *' : ''}`"
                clearable
                class="apidoc__control"
              >
                <el-option
                  v-for="option in param.enum"
                  :key="String(option.value)"
                  :label="option.label ? `${option.label}（${option.value}）` : String(option.value)"
                  :value="String(option.value)"
                />
              </el-select>
              <el-input
                v-else
                v-model="form[paramKey(param)]"
                :placeholder="`${param.type}${param.required ? ' *' : ''}`"
                class="apidoc__control"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="trying" @click="onTry">{{ t('cccms.apidoc.execute') }}</el-button>
              <el-button v-auth="'cccms:apidoc:detail'" @click="onCopyCurl">{{ t('cccms.apidoc.copyCurl') }}</el-button>
              <el-button @click="resetForm">{{ t('cccms.apidoc.reset') }}</el-button>
            </el-form-item>
          </el-form>

          <div v-if="result" class="apidoc__result">
            <div class="apidoc__result-head">
              <span
                >{{ t('cccms.apidoc.status') }}：<b
                  :class="result.status >= 400 || result.status === 0 ? 'is-bad' : 'is-ok'"
                  >{{ result.status }}</b
                ></span
              >
              <span>{{ t('cccms.apidoc.duration') }}：{{ result.duration }} ms</span>
            </div>
            <pre class="apidoc__pre">{{ result.body || t('cccms.apidoc.emptyBody') }}</pre>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:apidoc' })

import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { copyText } from '@/utils/clipboard'
import {
  apiDocCurl,
  apiDocDetail,
  apiDocSpec,
  apiDocTree,
  apiDocTry,
  type ApiDocDetail,
  type ApiDocTryResult,
} from '@/api/apidoc'

const { t } = useI18n({ useScope: 'global' })

interface TreeNode {
  id: string
  label: string
  isGroup: boolean
  /** 第一层：插件目录（plugin/{name}） */
  isPlugin?: boolean
  method?: string
  path?: string
  /** 插件 / 分组下的接口数量 */
  count?: number
  children?: TreeNode[]
}

const treeData = ref<TreeNode[]>([])
const total = ref(0)
const keyword = ref('')
const treeRef = ref<{ filter: (value: string) => void } | null>(null)
/** 默认展开第一层（插件），分组折叠 —— 三层树一进来就能看出「哪个插件有哪些模块」 */
const expandedKeys = ref<string[]>([])

const detail = ref<ApiDocDetail | null>(null)
const form = reactive<Record<string, string>>({})
const result = ref<ApiDocTryResult | null>(null)
const trying = ref(false)
const exporting = ref(false)

const authLabel = computed(() => {
  if (!detail.value) {
    return ''
  }
  if (detail.value.noLogin || detail.value.noAuth) {
    return detail.value.noAuth ? t('cccms.apidoc.authNoAuth') : t('cccms.apidoc.authPublic')
  }

  return t('cccms.apidoc.authRequired')
})

function paramKey(param: { in: string; name: string }): string {
  return `${param.in}:${param.name}`
}

/**
 * 拼三层树：插件 → 分组 → 接口。
 *
 * 服务端给的是「分组 + 所属插件」，顺序已按 cccms 优先、插件名、分组 key 排好，
 * 这里只按 plugin 归并，不重新排序 —— 否则前后端两套排序规则迟早对不上。
 */
async function loadTree() {
  const data = await apiDocTree()
  total.value = data.total

  const plugins = new Map<string, TreeNode>()
  data.groups.forEach((group) => {
    let node = plugins.get(group.plugin)
    if (!node) {
      node = {
        id: `plugin:${group.plugin}`,
        label: group.pluginTitle,
        isPlugin: true,
        isGroup: false,
        count: 0,
        children: [],
      }
      plugins.set(group.plugin, node)
    }

    node.count = (node.count ?? 0) + group.operations.length
    node.children?.push({
      id: `group:${group.key}`,
      label: group.title,
      isGroup: true,
      count: group.operations.length,
      children: group.operations.map((op) => ({
        id: op.id,
        label: `${op.method} ${op.path}`,
        isGroup: false,
        method: op.method,
        path: op.path,
      })),
    })
  })

  treeData.value = [...plugins.values()]
  expandedKeys.value = treeData.value.map((node) => node.id)
}

async function selectOperation(id: string) {
  try {
    detail.value = await apiDocDetail(id)
    result.value = null
    resetForm()
  } catch {
    ElMessage.error(t('cccms.apidoc.detailFailed'))
  }
}

function onNodeClick(data: TreeNode) {
  // 插件 / 分组节点只负责展开，不触发详情请求
  if (!data.isGroup && !data.isPlugin) {
    void selectOperation(data.id)
  }
}

/**
 * 搜索命中的节点集合（插件 / 分组 / 接口）。
 *
 * 三层树不能再用「每个节点各自判断」的写法：父节点返回 false 时子节点也不会显示，
 * 所以先自底向上算出「哪些节点该留」—— 子节点命中会带着父节点一起留下；
 * 插件 / 分组名命中则整棵子树都保留（搜「用户管理」时不该只剩一个空标题）。
 */
const visibleIds = computed<Set<string>>(() => {
  const needle = keyword.value.trim().toLowerCase()
  const ids = new Set<string>()
  if (needle === '') {
    return ids
  }

  const markSubtree = (node: TreeNode): void => {
    ids.add(node.id)
    node.children?.forEach(markSubtree)
  }

  const walk = (node: TreeNode): boolean => {
    const isLeaf = !node.isGroup && !node.isPlugin
    const selfHit = isLeaf
      ? `${node.method} ${node.path}`.toLowerCase().includes(needle)
      : node.label.toLowerCase().includes(needle)

    if (selfHit && !isLeaf) {
      markSubtree(node)
    }

    const childHit = (node.children ?? []).some(walk)
    if (selfHit || childHit) {
      ids.add(node.id)

      return true
    }

    return false
  }

  treeData.value.forEach(walk)

  return ids
})

/** 只保留命中的接口与其祖先，空分组一并隐藏 —— 否则搜索后满屏空标题 */
function filterNode(value: string, data: TreeNode): boolean {
  if (!value) {
    return true
  }

  return visibleIds.value.has(data.id)
}

watch(keyword, (value) => {
  treeRef.value?.filter(value)
})

function resetForm() {
  Object.keys(form).forEach((key) => delete form[key])
  detail.value?.params.forEach((param) => {
    form[paramKey(param)] = ''
  })
  result.value = null
}

/** 按声明的类型转换：后端 `(int)$request->get()` 之类的强转不会替我们兜底 */
function cast(value: string, type: string): unknown {
  if (value === '') {
    return undefined
  }
  if (type === 'integer' || type === 'number') {
    const num = Number(value)

    return Number.isNaN(num) ? value : num
  }
  if (type === 'boolean') {
    return value === 'true' || value === '1'
  }

  return value
}

function splitParams(): { query: Record<string, unknown>; body: Record<string, unknown> } {
  const query: Record<string, unknown> = {}
  const body: Record<string, unknown> = {}
  detail.value?.params.forEach((param) => {
    const value = cast(form[paramKey(param)] ?? '', param.type)
    if (value === undefined) {
      return
    }
    if (param.in === 'body') {
      body[param.name] = value
    } else {
      query[param.name] = value
    }
  })

  return { query, body }
}

async function onTry() {
  if (!detail.value) {
    return
  }
  const { query, body } = splitParams()
  trying.value = true
  try {
    result.value = await apiDocTry(detail.value.method, detail.value.path, query, body)
  } finally {
    trying.value = false
  }
}

async function onCopyCurl() {
  if (!detail.value) {
    return
  }
  const { query, body } = splitParams()
  if (await copyText(apiDocCurl(detail.value.method, detail.value.path, query, body))) {
    ElMessage.success(t('cccms.apidoc.copied'))
  } else {
    ElMessage.error(t('cccms.apidoc.copyFailed'))
  }
}

async function onExport() {
  exporting.value = true
  try {
    const spec = await apiDocSpec()
    const blob = new Blob([spec], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'openapi.json'
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    ElMessage.error(t('cccms.apidoc.exportFailed'))
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  try {
    await loadTree()
    await nextTick()
    // 默认选中第一个接口：插件 → 分组 → 接口，逐层取第一个非空节点
    const firstPlugin = treeData.value.find((plugin) => (plugin.children?.length ?? 0) > 0)
    const firstGroup = firstPlugin?.children?.find((group) => (group.children?.length ?? 0) > 0)
    const firstOperation = firstGroup?.children?.[0]
    if (firstOperation) {
      treeRef.value?.filter('')
      await selectOperation(firstOperation.id)
    }
  } catch {
    ElMessage.error(t('cccms.apidoc.loadFailed'))
  }
})
</script>

<style scoped>
.apidoc {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
}

.apidoc__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.apidoc__title h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--art-main);
}

.apidoc__title p {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--art-secondary);
}

.apidoc__tools {
  display: flex;
  gap: 8px;
}

.apidoc__search {
  width: 260px;
}

.apidoc__body {
  display: flex;
  flex: 1;
  gap: 12px;
  min-height: 0;
}

.apidoc__side {
  width: 300px;
  flex: none;
  padding: 8px;
  overflow: hidden;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.apidoc__main {
  flex: 1;
  min-width: 0;
  padding: 16px;
  overflow: auto;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

/* 第一层：插件目录（plugin/{name}） —— 比分组更重，一眼看出接口归属哪个插件 */
.apidoc__plugin {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  color: var(--art-main);
}

.apidoc__plugin small,
.apidoc__group small {
  padding: 0 6px;
  font-size: 11px;
  font-weight: 400;
  color: var(--art-secondary);
  background: var(--art-fill);
  border-radius: 8px;
}

.apidoc__group {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}

.apidoc__op {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  overflow: hidden;
}

.apidoc__op em,
.apidoc__detail-line em {
  flex: none;
  padding: 0 6px;
  font-size: 11px;
  font-style: normal;
  font-weight: 600;
  line-height: 18px;
  border-radius: 4px;
  color: #fff;
  background: var(--art-secondary);
}

.apidoc__op em.is-get,
.apidoc__detail-line em.is-get {
  background: #409eff;
}

.apidoc__op em.is-post,
.apidoc__detail-line em.is-post {
  background: #67c23a;
}

.apidoc__op em.is-put,
.apidoc__detail-line em.is-put {
  background: #e6a23c;
}

.apidoc__op em.is-delete,
.apidoc__detail-line em.is-delete {
  background: #f56c6c;
}

.apidoc__op-path {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.apidoc__detail-head h3 {
  margin: 8px 0;
  font-size: 15px;
  color: var(--art-main);
}

.apidoc__detail-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.apidoc__detail-path {
  font-size: 13px;
  color: var(--art-main);
}

.apidoc__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.apidoc__tag {
  padding: 2px 8px;
  font-size: 12px;
  color: var(--art-secondary);
  background: var(--art-fill);
  border-radius: 4px;
}

.apidoc__alert {
  margin-bottom: 12px;
}

.apidoc__alert--tight {
  margin: 8px 0;
}

/* 可选值：标签之间留小间距，列宽不够时自动换行 */
.apidoc__enum {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.apidoc__control {
  width: 100%;
}

.apidoc__muted {
  margin: 0;
  font-size: 12px;
  color: var(--art-secondary);
}

.apidoc__form {
  margin-top: 12px;
}

.apidoc__result {
  margin-top: 8px;
}

.apidoc__result-head {
  display: flex;
  gap: 16px;
  margin-bottom: 8px;
  font-size: 12px;
  color: var(--art-secondary);
}

.apidoc__result-head .is-ok {
  color: #67c23a;
}

.apidoc__result-head .is-bad {
  color: #f56c6c;
}

.apidoc__pre {
  max-height: 360px;
  margin: 0;
  padding: 12px;
  overflow: auto;
  font-size: 12px;
  line-height: 1.6;
  background: var(--art-fill);
  border-radius: 6px;
}
</style>
