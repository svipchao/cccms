<template>
  <div class="art-scroll">
    <div class="art-page">
      <!-- 未启用：进站直接给结论，不弹窗（与「在线升级」同一套表现） -->
      <el-alert
        v-if="blockedBy === 'environment'"
        type="error"
        :closable="false"
        show-icon
        :title="t('cccms.generator.disabledEnvTitle')"
        :description="t('cccms.generator.disabledEnvDesc', { reason: environment?.reason })"
      />
      <el-alert
        v-else-if="blockedBy === 'switch'"
        type="warning"
        :closable="false"
        show-icon
        :title="t('cccms.generator.disabledSwitchTitle')"
        :description="t('cccms.generator.disabledSwitchDesc', { reason: environment?.reason })"
      />

      <template v-else-if="environment">
        <el-alert type="warning" :closable="false" show-icon class="gen-alert">
          <template #title>{{ t('cccms.generator.alertTitle') }}</template>
          <i18n-t keypath="cccms.generator.alertDesc" scope="global" tag="div">
            <template #cmd><code>php webman cccms:perm-scan</code></template>
          </i18n-t>
        </el-alert>

        <el-card shadow="never">
          <template #header
            ><span>{{ t('cccms.generator.configTitle') }}</span></template
          >

          <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="gen-form">
            <el-form-item :label="t('cccms.generator.tableLabel')" prop="table">
              <el-select
                v-model="form.table"
                filterable
                :placeholder="t('cccms.generator.tablePlaceholder')"
                style="width: 100%"
                @change="onTableChange"
              >
                <el-option
                  v-for="t in tables"
                  :key="t.name"
                  :label="t.comment ? `${t.name}（${t.comment}）` : t.name"
                  :value="t.name"
                />
              </el-select>
            </el-form-item>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item :label="t('cccms.generator.pluginLabel')" prop="plugin">
                  <el-input v-model="form.plugin" :placeholder="t('cccms.generator.pluginPlaceholder')" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item :label="t('cccms.generator.moduleLabel')" prop="module">
                  <el-input v-model="form.module" :placeholder="t('cccms.generator.modulePlaceholder')" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item :label="t('cccms.generator.titleLabel')" prop="title">
              <el-input v-model="form.title" :placeholder="t('cccms.generator.titlePlaceholder')" />
            </el-form-item>

            <el-form-item :label="t('cccms.generator.overwriteLabel')">
              <el-checkbox v-model="form.overwrite">{{ t('cccms.generator.overwriteText') }}</el-checkbox>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="previewing" @click="onPreview"
                ><template #icon><i class="ri-eye-line" /></template>
                {{ t('cccms.generator.preview') }}
              </el-button>
              <el-button
                v-auth="'cccms:generator:generate'"
                type="danger"
                plain
                :loading="generating"
                @click="onGenerate"
                ><template #icon><i class="ri-magic-line" /></template>
                {{ t('cccms.generator.generate') }}
              </el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <el-card v-if="columns.length" shadow="never" class="gen-block">
          <template #header>
            <span>{{ t('cccms.generator.fieldsTitle', { count: columns.length }) }}</span>
          </template>
          <el-table :data="columns" stripe border size="small">
            <el-table-column prop="name" :label="t('cccms.generator.colName')" min-width="160" />
            <el-table-column prop="type" :label="t('cccms.generator.colType')" width="140" />
            <el-table-column prop="comment" :label="t('cccms.generator.colComment')" min-width="180" />
            <el-table-column :label="t('cccms.generator.colPrimary')" width="80" align="center">
              <template #default="{ row }">
                <el-tag v-if="row.primary" type="success" effect="light" size="small">
                  {{ t('cccms.generator.yes') }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
        </el-card>

        <el-card v-if="files.length" shadow="never" class="gen-block">
          <template #header>
            <span>{{ t('cccms.generator.previewTitle', { count: files.length }) }}</span>
          </template>
          <el-tabs>
            <el-tab-pane v-for="f in files" :key="f.path" :label="f.path.split('/').pop()">
              <div class="file-path">{{ f.path }}</div>
              <pre class="code-block">{{ f.content }}</pre>
            </el-tab-pane>
          </el-tabs>
        </el-card>

        <el-card v-if="result" shadow="never" class="gen-block">
          <template #header
            ><span>{{ t('cccms.generator.resultTitle') }}</span></template
          >
          <div class="result-files">
            <el-tag v-for="f in result.files" :key="f" type="success" effect="light">{{ f }}</el-tag>
          </div>
          <el-alert type="info" :closable="false" class="result-alert">
            <i18n-t keypath="cccms.generator.resultMenu" scope="global" tag="div">
              <template #path
                ><b>{{ result.menu.path }}</b></template
              >
              <template #slug>{{ result.menu.slug }}</template>
            </i18n-t>
            <i18n-t keypath="cccms.generator.resultSnippet" scope="global" tag="div" class="result-tip">
              <template #file
                ><code>plugin/{{ form.plugin }}/db/menu.php</code></template
              >
            </i18n-t>
            <pre class="code-block">{{ result.menu_snippet }}</pre>
          </el-alert>
        </el-card>
      </template>

      <el-skeleton v-else :rows="4" animated />
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:generator' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  generatorColumns,
  generatorGenerate,
  generatorPreview,
  generatorStatus,
  generatorTables,
  type ColumnInfo,
  type GeneratedFile,
  type GenerateResult,
  type GeneratorEnvironment,
  type TableInfo,
} from '@/api/generator'

const { t } = useI18n({ useScope: 'global' })

/** 可用性：null = 探测中（渲染骨架），不可用时页面只显示原因，不再请求表结构 */
const environment = ref<GeneratorEnvironment | null>(null)

/** 被哪道闸挡住：environment = 非本地开发环境；switch = 总开关关闭 */
const blockedBy = computed(() => environment.value?.blocked_by || '')

const tables = ref<TableInfo[]>([])
const columns = ref<ColumnInfo[]>([])
const files = ref<GeneratedFile[]>([])
const result = ref<GenerateResult | null>(null)

const previewing = ref(false)
const generating = ref(false)

const formRef = ref<FormInstance>()
const form = reactive({
  table: '',
  plugin: 'cccms',
  module: '',
  title: '',
  overwrite: false,
})

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  table: [{ required: true, message: t('cccms.generator.tableRequired'), trigger: 'change' }],
  plugin: [{ required: true, message: t('cccms.generator.pluginRequired'), trigger: 'blur' }],
  title: [{ required: true, message: t('cccms.generator.titleRequired'), trigger: 'blur' }],
}))

function config(): Record<string, unknown> {
  return { ...form }
}

async function onTableChange(value: unknown): Promise<void> {
  const table = String(value || '')
  columns.value = []
  files.value = []
  result.value = null
  if (!table) {
    return
  }
  try {
    columns.value = await generatorColumns(table)
  } catch {
    // 提示由 axios 拦截器统一给出，这里保持空列表
    return
  }
  if (!form.title) {
    const hit = tables.value.find((t) => t.name === table)
    form.title = hit?.comment || table
  }
}

async function onPreview(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  previewing.value = true
  try {
    files.value = await generatorPreview(config())
    result.value = null
  } catch {
    // 提示由拦截器统一给出，不冒泡到 Vue 的事件调用器
  } finally {
    previewing.value = false
  }
}

async function onGenerate(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  generating.value = true
  try {
    result.value = await generatorGenerate(config())
    files.value = []
    ElMessage.success(t('cccms.generator.generateSuccess'))
  } catch {
    // 同上：失败原因由拦截器提示
  } finally {
    generating.value = false
  }
}

onMounted(async () => {
  // 先问可用性：关闭时直接渲染拦截提示，不再发那串注定 403 的请求
  try {
    environment.value = await generatorStatus()
  } catch {
    return
  }
  if (!environment.value.allowed) {
    return
  }

  try {
    tables.value = await generatorTables()
  } catch {
    tables.value = []
  }
})
</script>

<style scoped>
.gen-alert {
  margin-bottom: 14px;
}

.gen-form {
  max-width: 720px;
}

.gen-block {
  margin-top: 14px;
}

.file-path {
  margin-bottom: 8px;
  font-size: 12px;
  color: var(--art-muted);
}

.code-block {
  max-height: 420px;
  padding: 12px;
  margin: 0;
  overflow: auto;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-sub);
  word-break: break-all;
  white-space: pre-wrap;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 4px);
}

.result-files {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.result-tip {
  margin-top: 6px;
}
</style>
