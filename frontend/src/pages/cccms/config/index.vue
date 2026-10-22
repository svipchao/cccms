<template>
  <div class="art-fill">
    <el-card class="cfg" shadow="never">
      <template #header>
        <div class="cfg-head">
          <div class="cfg-head-left">
            <span class="cfg-title">{{ t('config.pageTitle') }}</span>
            <el-tag v-if="dirtyCount" type="warning" effect="light" size="small">
              {{ t('config.dirtyCount', { count: dirtyCount }) }}
            </el-tag>
            <span v-else class="cfg-count">{{ t('config.totalCount', { count: items.length }) }}</span>
          </div>

          <div class="cfg-head-right">
            <el-input v-model="keyword" :placeholder="t('config.searchPlaceholder')" clearable class="cfg-search"
              ><template #prefix><i class="ri-search-line" /></template
            ></el-input>
            <el-button @click="reload"
              ><template #icon><i class="ri-refresh-line" /></template>{{ t('common.refresh') }}</el-button
            >
            <el-button :disabled="!dirtyCount" @click="discard"
              ><template #icon><i class="ri-restart-line" /></template>
              {{ t('config.discard') }}
            </el-button>
            <el-button
              v-auth="'cccms:config:save'"
              type="primary"
              :loading="saving"
              :disabled="!dirtyCount"
              @click="onSave"
              ><template #icon><i class="ri-check-line" /></template>
              {{ dirtyCount ? t('config.saveCount', { count: dirtyCount }) : t('common.save') }}
            </el-button>
          </div>
        </div>
      </template>

      <div class="cfg-body">
        <!-- 分组导航 -->
        <aside class="cfg-aside">
          <ul class="cfg-groups">
            <li :class="{ 'is-active': group === '' }" @click="group = ''">
              <span>{{ t('config.all') }}</span>
              <em>{{ filtered.length }}</em>
            </li>
            <li v-for="g in groups" :key="g.name" :class="{ 'is-active': group === g.name }" @click="group = g.name">
              <span>{{ g.label }}</span>
              <em>{{ g.count }}</em>
            </li>
          </ul>
        </aside>

        <!-- 表单 -->
        <div class="cfg-main">
          <el-skeleton v-if="loading" :rows="8" animated />

          <el-empty
            v-else-if="!visibleGroups.length"
            :description="keyword ? t('config.noMatch') : t('config.empty')"
          />

          <template v-else>
            <section v-for="g in visibleGroups" :key="g.name" class="cfg-section">
              <h3 class="cfg-section-title">{{ g.label }}</h3>

              <el-form label-width="180px" label-position="right">
                <el-form-item v-for="item in g.items" :key="item.name" :class="{ 'is-dirty': isDirty(item.name) }">
                  <template #label>
                    <div class="cfg-label">
                      <span class="cfg-label-text">{{ item.title || item.name }}</span>
                      <button type="button" class="cfg-label-key" @click="copyKey(item.name)">
                        {{ item.name }}
                      </button>
                    </div>
                  </template>

                  <div class="cfg-field">
                    <el-switch
                      v-if="item.type === 'switch'"
                      v-model="values[item.name]"
                      :active-value="1"
                      :inactive-value="0"
                    />

                    <el-radio-group v-else-if="item.type === 'radio'" v-model="values[item.name]">
                      <el-radio v-for="opt in parseOptions(item)" :key="String(opt.value)" :value="opt.value">
                        {{ opt.label }}
                      </el-radio>
                    </el-radio-group>

                    <el-select
                      v-else-if="item.type === 'select'"
                      v-model="values[item.name]"
                      :placeholder="t('config.selectPlaceholder')"
                      style="width: 260px"
                    >
                      <el-option
                        v-for="opt in parseOptions(item)"
                        :key="String(opt.value)"
                        :label="opt.label"
                        :value="opt.value"
                      />
                    </el-select>

                    <el-input-number
                      v-else-if="item.type === 'input-number'"
                      v-model="values[item.name]"
                      :min="numberMin(item)"
                      controls-position="right"
                      style="width: 200px"
                    />

                    <el-input
                      v-else-if="item.type === 'textarea'"
                      :ref="(el: unknown) => setTextareaRef(item.name, el)"
                      v-model="values[item.name]"
                      type="textarea"
                      :autosize="{ minRows: 2, maxRows: 6 }"
                      style="max-width: 560px"
                    />

                    <el-input
                      v-else-if="item.type === 'password'"
                      v-model="values[item.name]"
                      type="password"
                      show-password
                      :placeholder="t('config.passwordPlaceholder')"
                      style="max-width: 420px"
                    />

                    <el-input
                      v-else
                      v-model="values[item.name]"
                      :placeholder="t('config.inputPlaceholder')"
                      style="max-width: 420px"
                    />

                    <!--
                      水印内容：常用变量标签，点一下插入到光标处（写法随界面语言：中文 `{用户名}` / 英文 `{username}`）。
                      ⚠️ 必须放在上面那条 v-if / v-else-if 链**之外**：插在链中间会截断条件链，
                      使 `v-else` 对其它类型也命中，多渲染一个默认输入框（两个框绑定同一个值，输入会同步）。
                    -->
                    <div v-if="item.name === 'watermark.content'" class="cfg-vars">
                      <span class="cfg-vars-label">{{ t('config.wmVarHint') }}</span>
                      <el-check-tag
                        v-for="v in WATERMARK_VARS"
                        :key="v.en"
                        checked
                        type="info"
                        @change="insertWatermarkVar(item.name, watermarkVarToken(v, currentLocale))"
                      >
                        {{ watermarkVarToken(v, currentLocale) }}
                      </el-check-tag>
                    </div>

                    <div v-if="item.remark" class="cfg-remark">{{ item.remark }}</div>
                  </div>
                </el-form-item>
              </el-form>
            </section>
          </template>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:config' })

import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { configList, configSave, type ConfigItem, type ConfigOption } from '@/api/config'
import { currentLocale } from '@/locales'
import { WATERMARK_VARS, watermarkVarToken } from '@/utils/watermark'

const { t } = useI18n({ useScope: 'global' })

/** 无分组的兜底键（用于过滤，不用于展示） */
const UNGROUPED = '__ungrouped__'

const loading = ref(false)
const saving = ref(false)
const items = ref<ConfigItem[]>([])
const keyword = ref('')
const group = ref('')

/** 表单当前值 */
const values = reactive<Record<string, any>>({})
/** 加载时的快照，用于判断哪些项被改过 */
const original = ref<Record<string, any>>({})

/* ---------- 取值与比较 ---------- */

function normalize(item: ConfigItem): unknown {
  const raw = item.value ?? ''
  if (item.type === 'switch') {
    return Number(raw) === 1 ? 1 : 0
  }
  if (item.type === 'input-number') {
    const num = Number(raw)
    return Number.isFinite(num) ? num : 0
  }
  return raw
}

/**
 * input-number 的下限。
 *
 * 绝大多数配置（分页条数、次数、时长…）不允许负数，统一 0；
 * 但「倾斜角度」这类配置需要负值（水印向左倾斜），故按配置名放行。
 */
function numberMin(item: ConfigItem): number {
  return item.name.includes('angle') ? -180 : 0
}

function parseOptions(item: ConfigItem): ConfigOption[] {
  const raw = item.options
  if (!raw) {
    return []
  }
  if (Array.isArray(raw)) {
    return raw as ConfigOption[]
  }
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? (parsed as ConfigOption[]) : []
    } catch {
      return []
    }
  }
  return []
}

function isDirty(name: string): boolean {
  // 配置值都是标量（string / number），全等比较即可，无需 JSON.stringify
  return values[name] !== original.value[name]
}

const dirtyKeys = computed(() => Object.keys(values).filter((key) => isDirty(key)))
const dirtyCount = computed(() => dirtyKeys.value.length)

/* ---------- 过滤与分组 ---------- */

/**
 * 云存储凭证项与存储驱动的前缀对应关系。
 *
 * 上传分组里的凭证项只在「存储驱动」选中对应驱动时展示：避免给 OSS 填 COS 的密钥。
 * 只是**不展示**，值仍留在 `values` 里 —— 来回切换驱动不会丢掉之前填过的配置，
 * 保存时按 dirty 项一并提交。
 */
const DRIVER_PREFIX: Record<string, string> = {
  oss: 'upload.oss_',
  cos: 'upload.cos_',
  qiniu: 'upload.qiniu_',
}

function driverVisible(name: string): boolean {
  const owner = Object.keys(DRIVER_PREFIX).find((driver) => name.startsWith(DRIVER_PREFIX[driver]))
  return owner === undefined || owner === String(values['upload.storage_driver'] ?? '')
}

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  const list = kw
    ? items.value.filter((item) =>
        [item.title, item.name, item.remark].some((text) => (text || '').toLowerCase().includes(kw)),
      )
    : items.value
  return list.filter((item) => driverVisible(item.name))
})

/** 分组展示名：优先后端翻译下发的 group_label，回退原始 group；无分组用「未分组」 */
function groupLabel(item: ConfigItem): string {
  return item.group_label || item.group || t('config.ungrouped')
}

const groups = computed(() => {
  const counter = new Map<string, { count: number; label: string }>()
  for (const item of filtered.value) {
    // 过滤键仍用 group（group_label 仅用于展示，不能作过滤值）
    const name = item.group || UNGROUPED
    const cur = counter.get(name)
    if (cur) {
      cur.count += 1
    } else {
      counter.set(name, { count: 1, label: groupLabel(item) })
    }
  }
  return Array.from(counter, ([name, value]) => ({ name, count: value.count, label: value.label }))
})

const visibleGroups = computed(() => {
  const list = group.value ? filtered.value.filter((item) => (item.group || UNGROUPED) === group.value) : filtered.value

  const bucket = new Map<string, { label: string; items: ConfigItem[] }>()
  for (const item of list) {
    const name = item.group || UNGROUPED
    const cur = bucket.get(name)
    if (cur) {
      cur.items.push(item)
    } else {
      bucket.set(name, { label: groupLabel(item), items: [item] })
    }
  }
  return Array.from(bucket, ([name, value]) => ({ name, label: value.label, items: value.items }))
})

/* ---------- 加载与保存 ---------- */

async function reload(): Promise<void> {
  loading.value = true
  try {
    const list = await configList()
    items.value = list

    const next: Record<string, any> = {}
    for (const item of list) {
      next[item.name] = normalize(item)
    }

    // 清掉已删除的键，再覆盖当前值
    Object.keys(values).forEach((key) => {
      if (!(key in next)) {
        delete values[key]
      }
    })
    Object.assign(values, next)
    original.value = JSON.parse(JSON.stringify(next))
  } finally {
    loading.value = false
  }
}

function discard(): void {
  Object.assign(values, JSON.parse(JSON.stringify(original.value)))
  ElMessage.info(t('config.discarded'))
}

async function onSave(): Promise<void> {
  if (!dirtyCount.value) {
    return
  }
  // 只提交变更项，避免用陈旧值覆盖其他字段
  const payload: Record<string, unknown> = {}
  dirtyKeys.value.forEach((key) => {
    payload[key] = values[key]
  })

  saving.value = true
  try {
    const res = await configSave(payload)
    ElMessage.success(t('config.savedCount', { count: res?.updated ?? dirtyCount.value }))
    await reload()
  } finally {
    saving.value = false
  }
}

async function copyKey(name: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(name)
    ElMessage.success(t('config.copied', { name }))
  } catch {
    ElMessage.warning(t('config.copyFailed'))
  }
}

/* ---------- 水印内容：常用变量标签 ---------- */

/** textarea 的组件实例（按配置名索引）：点变量标签时要往光标处插入 */
const textareaRefs = new Map<string, { textarea?: HTMLTextAreaElement }>()

function setTextareaRef(name: string, el: unknown): void {
  if (el) {
    textareaRefs.set(name, el as { textarea?: HTMLTextAreaElement })
  } else {
    textareaRefs.delete(name)
  }
}

/** 把变量占位符插入到水印内容的**光标处**（未聚焦 / 无光标时追加到末尾） */
function insertWatermarkVar(name: string, token: string): void {
  const current = String(values[name] ?? '')
  const el = textareaRefs.get(name)?.textarea
  const start = el?.selectionStart ?? current.length
  const end = el?.selectionEnd ?? current.length

  values[name] = current.slice(0, start) + token + current.slice(end)

  if (el) {
    void nextTick(() => {
      el.focus()
      const pos = start + token.length
      el.setSelectionRange(pos, pos)
    })
  }
}

/* ---------- 未保存提示 ---------- */

onBeforeRouteLeave(async () => {
  if (!dirtyCount.value) {
    return true
  }
  try {
    await ElMessageBox.confirm(t('config.leaveConfirm'), t('config.leaveTitle'), {
      type: 'warning',
      confirmButtonText: t('config.leaveConfirmButton'),
      cancelButtonText: t('config.stayButton'),
    })
    return true
  } catch {
    return false
  }
})

function handleBeforeUnload(event: BeforeUnloadEvent): void {
  if (dirtyCount.value) {
    event.preventDefault()
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload)
  void reload()
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})
</script>

<style scoped>
.cfg {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.cfg :deep(.el-card__body) {
  display: flex;
  flex: 1;
  min-height: 0;
  padding: 0;
}

.cfg-head {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
}

.cfg-head-left {
  display: flex;
  gap: 10px;
  align-items: center;
}

.cfg-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--art-main);
}

.cfg-count {
  font-size: 12px;
  color: var(--art-muted);
}

.cfg-head-right {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cfg-search {
  width: 220px;
}

.cfg-body {
  display: flex;
  flex: 1;
  min-height: 0;
}

/* ---- 左侧分组 ---- */
.cfg-aside {
  flex: 0 0 170px;
  padding: 10px;
  overflow-y: auto;
  border-right: 1px solid var(--art-card-border);
}

.cfg-groups {
  padding: 0;
  margin: 0;
  list-style: none;
}

.cfg-groups li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 36px;
  padding: 0 10px;
  margin-bottom: 2px;
  font-size: var(--art-fs-dense);
  color: var(--art-sub);
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 2px);
  /* 只过渡真正会变的属性，避免 `transition: all` 把布局属性也纳入过渡 */
  transition:
    color 0.15s ease,
    background-color 0.15s ease;
}

.cfg-groups li:hover {
  background: var(--art-hover-bg);
}

.cfg-groups li.is-active {
  font-weight: 600;
  color: var(--art-primary-text);
  background: var(--el-color-primary-light-9);
}

.cfg-groups em {
  font-size: 12px;
  font-style: normal;
  color: var(--art-muted);
}

/* ---- 右侧表单 ---- */
.cfg-main {
  flex: 1;
  min-width: 0;
  padding: 18px 24px 24px;
  overflow-y: auto;
}

.cfg-section + .cfg-section {
  margin-top: 8px;
}

.cfg-section-title {
  padding-left: 10px;
  margin: 18px 0 14px;
  font-size: var(--art-fs-dense);
  font-weight: 600;
  color: var(--art-main);
  border-left: 3px solid var(--el-color-primary);
}

.cfg-label {
  display: flex;
  flex-direction: column;
  gap: 2px;
  line-height: 1.4;
}

.cfg-label-text {
  color: var(--art-main);
}

.cfg-label-key {
  padding: 0;
  font-family: Consolas, Monaco, monospace;
  /* 11px 低于可读下限，配置键名是要逐个核对的内容 */
  font-size: var(--art-fs-caption);
  color: var(--art-muted);
  cursor: pointer;
  background: transparent;
  border: none;
}

.cfg-label-key:hover {
  color: var(--art-primary-text);
  text-decoration: underline;
}

.cfg-field {
  width: 100%;
}

.cfg-remark {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

/* 变更项左侧高亮 */
.cfg :deep(.el-form-item.is-dirty) {
  position: relative;
}

.cfg :deep(.el-form-item.is-dirty)::before {
  position: absolute;
  top: 8px;
  bottom: 8px;
  left: -12px;
  width: 3px;
  content: '';
  background: var(--el-color-warning);
  border-radius: 2px;
}

.cfg :deep(.el-form-item) {
  margin-bottom: 20px;
}

/* ---- 水印内容的常用变量标签 ---- */
.cfg-vars {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-top: 8px;
}

.cfg-vars-label {
  font-size: 12px;
  color: var(--art-muted);
}
</style>
