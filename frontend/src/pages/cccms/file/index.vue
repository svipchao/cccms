<template>
  <div class="art-fill">
    <ArtSplitView aside-width="248px">
      <template #aside>
        <ArtTreePanel
          :title="t('file.categoryTreeTitle')"
          :data="treeData"
          :current-key="currentId"
          @node-click="onNodeClick"
        >
          <template #header>
            <el-tooltip :content="t('file.addTopCategory')" placement="top">
              <el-button v-auth="'cccms:file:category_save'" text circle @click="openCategoryCreate(0)"
                ><i class="ri-add-line"
              /></el-button>
            </el-tooltip>
            <!-- 只显示附件模块的分类，避免看到字典模块的分类 -->
            <RecycleToggle
              :active="categoryRecycle"
              :label="t('file.categoryTreeTitle')"
              @toggle="toggleCategoryRecycle"
            />
          </template>

          <template #node="{ data }">
            <span class="cat-node">
              <span class="cat-node-label">{{ data.name }}</span>
              <span v-if="data.id > 0" class="cat-node-actions">
                <!-- 分类回收站：节点操作换成还原 / 彻底删除 -->
                <template v-if="categoryRecycle">
                  <el-icon :title="t('table.restore')" @click.stop="onCategoryRestore(data)"
                    ><i class="ri-restart-line"
                  /></el-icon>
                  <el-icon :title="t('table.forceDelete')" @click.stop="onCategoryForceDelete(data)">
                    <i class="ri-delete-bin-line" />
                  </el-icon>
                </template>
                <template v-else>
                  <el-icon :title="t('file.addSubCategory')" @click.stop="openCategoryCreate(data.id)"
                    ><i class="ri-add-line"
                  /></el-icon>
                  <el-icon :title="t('file.renameCategory')" @click.stop="openCategoryEdit(data)"
                    ><i class="ri-edit-line"
                  /></el-icon>
                  <el-icon :title="t('common.delete')" @click.stop="onCategoryDelete(data)"
                    ><i class="ri-delete-bin-line"
                  /></el-icon>
                </template>
              </span>
            </span>
          </template>
        </ArtTreePanel>
      </template>

      <ArtTable
        :columns="columns"
        :data="list"
        :loading="loading"
        :total="total"
        :recycle="recycle"
        selection
        v-model:page="page"
        v-model:limit="limit"
        @refresh="load"
        @page-change="onPageChange"
        @size-change="onLimitChange"
        @selection-change="onSelectionChange"
        @restore="onRestore"
        @force-delete="onForceDelete"
      >
        <template #toolbar-right>
          <RecycleToggle :active="recycle" :label="t('file.recycleLabel')" @toggle="toggle" />
        </template>

        <template #toolbar>
          <el-upload
            v-auth="'cccms:file:upload'"
            :show-file-list="false"
            :before-upload="onBeforeUpload"
            :http-request="onHttpRequest"
          >
            <el-button type="primary" :loading="uploading"
              ><template #icon><i class="ri-upload-2-line" /></template>{{ t('file.upload') }}</el-button
            >
          </el-upload>

          <el-button v-auth="'cccms:file:move'" :disabled="selection.length === 0" @click="openMove"
            ><template #icon><i class="ri-folder-check-line" /></template>
            {{ selection.length ? t('file.moveWithCount', { count: selection.length }) : t('file.move') }}
          </el-button>

          <el-tag v-if="currentId" type="info" closable @close="clearNode">
            {{ t('file.onlyView', { name: currentNodeName }) }}
          </el-tag>
          <span v-else class="toolbar-tip">{{ t('file.toolbarTip') }}</span>
        </template>

        <!-- is_image 由后台 upload.image_ext 配置推导 -->
        <template #preview="{ row }">
          <el-image
            v-if="row.is_image"
            class="file-thumb"
            :src="row.url"
            :preview-src-list="[row.url]"
            preview-teleported
            fit="cover"
          />
          <el-icon v-else :size="18" class="file-thumb-icon"><i class="ri-file-text-line" /></el-icon>
        </template>

        <template #ext="{ row }">
          <el-tag effect="plain" size="small">{{ row.ext }}</el-tag>
        </template>

        <template #size="{ row }">
          {{ formatSize(row.size) }}
        </template>

        <template #action="{ row }">
          <!-- PDF 走内置对话框预览，其余类型回退到新窗口打开（浏览器自行决定预览或下载） -->
          <el-button link type="primary" @click="openPreview(row)">
            {{ row.is_pdf ? t('file.preview') : t('file.view') }}
          </el-button>
          <el-popconfirm :title="t('file.deleteConfirm')" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:file:delete'" link type="danger">{{ t('common.delete') }}</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <!-- 分类表单 -->
    <el-dialog
      v-model="categoryVisible"
      :title="categoryForm.id ? t('file.editCategoryTitle') : t('file.createCategoryTitle')"
      width="460px"
      :close-on-click-modal="false"
    >
      <el-form ref="categoryFormRef" :model="categoryForm" :rules="categoryRules" label-width="92px">
        <el-form-item :label="t('file.parentCategoryLabel')" prop="parent_id">
          <el-tree-select
            v-model="categoryForm.parent_id"
            :data="categoryOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('file.categoryNameLabel')" prop="name">
          <el-input v-model="categoryForm.name" :placeholder="t('file.categoryNamePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('file.sortLabel')" prop="sort">
          <el-input-number v-model="categoryForm.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('file.remarkLabel')" prop="remark">
          <el-input v-model="categoryForm.remark" :placeholder="t('file.remarkPlaceholder')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="categoryVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitCategory">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 移动到分类 -->
    <el-dialog v-model="moveVisible" :title="t('file.moveTitle')" width="420px">
      <el-form label-width="80px">
        <el-form-item :label="t('file.targetCategoryLabel')">
          <el-tree-select
            v-model="moveTarget"
            :data="moveOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="moveVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitMove">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- PDF 在线预览：内嵌浏览器自带阅读器；destroy-on-close 保证关闭后不再加载 -->
    <el-dialog
      v-model="previewVisible"
      :title="previewTitle"
      width="82%"
      top="5vh"
      class="pdf-preview-dialog"
      destroy-on-close
    >
      <iframe v-if="previewUrl" :src="previewUrl" class="pdf-frame" :title="t('file.pdfPreview')" />
      <template #footer>
        <el-button @click="openUrl(previewUrl)">{{ t('file.openInNewWindow') }}</el-button>
        <el-button @click="previewVisible = false">{{ t('file.close') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:file' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ElMessage,
  ElMessageBox,
  type FormInstance,
  type FormRules,
  type UploadRawFile,
  type UploadRequestOptions,
} from 'element-plus'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { useRecycle } from '@/composables/useRecycle'
import { useCategoryTree } from '@/composables/useCategoryTree'
import { useUserStore } from '@/stores/user'
import {
  fileDelete,
  fileList,
  fileMove,
  fileUpload,
  fileUploadChunk,
  fileUploadComplete,
  fileUploadInit,
  type FileRow,
} from '@/api/file'
import type { ArtTableColumn } from '@/types/table'

const MODULE = 'file' as const

interface Query {
  original_name: string
  ext: string
  /** 上传时间范围（列头日期筛选写入） */
  start: string
  end: string
}

const MAX_SIZE = 10 * 1024 * 1024
/** 超过该体积就不算 SHA-1 秒传指纹（整文件读进内存哈希，再大无谓） */
const HASH_LIMIT = 64 * 1024 * 1024
/** 分片大小：与后端 ChunkUpload::CHUNK_SIZE 一致 */
const CHUNK_SIZE = 4 * 1024 * 1024

const { t } = useI18n({ useScope: 'global' })
const userStore = useUserStore()

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'preview', label: t('file.preview'), width: 76, align: 'center', slot: 'preview' },
  { prop: 'original_name', label: t('file.nameLabel'), minWidth: 200, filter: { type: 'text' } },
  { prop: 'category_name', label: t('file.categoryColumnLabel'), width: 140 },
  { prop: 'ext', label: t('file.typeLabel'), width: 100, align: 'center', slot: 'ext', filter: { type: 'text' } },
  { prop: 'size', label: t('file.sizeLabel'), width: 110, align: 'right', slot: 'size', sortable: 'custom' },
  { prop: 'create_time', label: t('file.uploadTimeLabel'), width: 170, filter: { type: 'date' }, sortable: 'custom' },
  { prop: 'action', label: t('table.action'), width: 130, fixed: 'right', slot: 'action', lockVisible: true },
])

/* ---- 左侧分类树 ---- */
const {
  saving,
  currentId,
  categoryRecycle,
  toggleCategoryRecycle,
  onCategoryRestore,
  onCategoryForceDelete,
  treeData,
  currentNodeName,
  categoryOptions,
  selectorOptions: moveOptions,
  loadCategories,
  onNodeClick,
  clearNode,
  categoryFormRef,
  categoryVisible,
  categoryForm,
  categoryRules,
  openCategoryCreate,
  openCategoryEdit,
  submitCategory,
  onCategoryDelete,
} = useCategoryTree(MODULE, { prefix: 'file', onListReload: () => search() })

/* ---- 回收站：本页附件入口；附件分类入口由 useCategoryTree 管理 ---- */
// 声明在 useTable 之前：列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('file', {
  reload: () => search(),
  ids: () => selection.value.map((row) => row.id),
  clear: () => {
    selection.value = []
  },
})

const { list, loading, total, page, limit, selection, load, search, onPageChange, onLimitChange, onSelectionChange } =
  useTable<FileRow, Query>({
    api: (params) => fileList({ ...params, category_id: currentId.value, trashed: recycle.value ? 1 : 0 }),
    initialQuery: { original_name: '', ext: '', start: '', end: '' },
  })



/* ---- 上传 ---- */
const uploading = ref(false)
/** 上传直接落到左侧选中的分类（全部/未分类 → 0） */
function uploadCategory(): number {
  return currentId.value > 0 ? currentId.value : 0
}

function onBeforeUpload(): boolean {
  uploading.value = true
  // http-request 走 axios 实例，进度条由请求拦截器统一登记，无需在这里手动 start
  return true
}

/**
 * 自定义上传：小文件单请求直传，大文件走分片（init → chunk → complete）。
 * 触发条件是文件体积，不是两个按钮 —— 同一个「上传」按钮自动分流。
 */
async function onHttpRequest(options: UploadRequestOptions): Promise<void> {
  const file = options.file as UploadRawFile
  const categoryId = uploadCategory()

  try {
    const result = file.size > MAX_SIZE ? await chunkedUpload(file, categoryId) : await singleUpload(file, categoryId)
    options.onSuccess(result)
    uploading.value = false
    ElMessage.success(t('file.uploadSuccess'))
    search()
  } catch (error) {
    // onError 的形参类型 UploadAjaxError 未从 element-plus 导出，这里只作失败信号用
    options.onError(error as never)
    uploading.value = false
    // 具体错误由 request 拦截器（HTTP 错误）或 chunkedUpload（无分片权限）提示，避免弹两条
  }
}

async function singleUpload(file: UploadRawFile, categoryId: number): Promise<FileRow> {
  const form = new FormData()
  form.append('file', file)
  form.append('category_id', String(categoryId))

  return fileUpload(form)
}

async function chunkedUpload(file: UploadRawFile, categoryId: number): Promise<FileRow> {
  if (!userStore.hasAuth('cccms:file:upload:chunk')) {
    ElMessage.warning(t('file.uploadChunkNoPermission'))
    throw new Error(t('file.uploadChunkNoPermission'))
  }

  const chunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE))

  // 秒传指纹：只有体积可控时才整文件读进内存算 SHA-1；WebCrypto 需要安全上下文
  // （HTTPS / localhost），非安全环境算不出来就跳过秒传（不影响分片续传）。
  let hash = ''
  if (file.size <= HASH_LIMIT && typeof crypto !== 'undefined' && crypto.subtle) {
    try {
      hash = await sha1Hex(file)
    } catch {
      hash = ''
    }
  }

  const init = await fileUploadInit({ name: file.name, size: file.size, chunks, hash, category_id: categoryId })
  if (init.instant && init.file) {
    return init.file
  }

  const uploadId = init.upload_id as string
  const received = new Set(init.received ?? [])
  for (let i = 0; i < chunks; i++) {
    // 断点续传：服务端返回已收到的分片序号，跳过它们只补缺的
    if (received.has(i)) {
      continue
    }
    const start = i * CHUNK_SIZE
    const blob = file.slice(start, Math.min(start + CHUNK_SIZE, file.size))
    await fileUploadChunk(uploadId, i, blob, `${file.name}.part${i}`)
  }

  const done = await fileUploadComplete(uploadId)

  return done.file
}

/** SHA-1 十六进制（与服务端 sha1_file 同口径，用于 init 阶段秒传） */
async function sha1Hex(file: Blob): Promise<string> {
  const buf = await file.arrayBuffer()
  const digest = await crypto.subtle.digest('SHA-1', buf)

  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')
}

/* ---- 移动到分类 ---- */
const moveVisible = ref(false)
const moveTarget = ref(0)

function openMove(): void {
  moveTarget.value = currentId.value > 0 ? currentId.value : 0
  moveVisible.value = true
}

async function submitMove(): Promise<void> {
  const ids = selection.value.map((row) => row.id)
  if (!ids.length) {
    return
  }
  saving.value = true
  try {
    await fileMove(ids, moveTarget.value)
    ElMessage.success(t('file.moveSuccess', { count: ids.length }))
    moveVisible.value = false
    // 表格未开 reserve-selection，刷新后勾选会自动清空
    load()
  } finally {
    saving.value = false
  }
}

/* ---- 预览 ---- */
const previewVisible = ref(false)
const previewUrl = ref('')
const previewTitle = ref(t('file.preview'))

/** 图片由 preview 列的 el-image 内置查看器负责；PDF 用对话框内嵌，其余回退到新窗口 */
function openPreview(row: FileRow): void {
  if (row.is_pdf) {
    previewUrl.value = row.url
    previewTitle.value = row.original_name || t('file.pdfPreview')
    previewVisible.value = true
    return
  }
  openUrl(row.url)
}

/* ---- 其它 ---- */
function openUrl(url: string): void {
  window.open(url, '_blank', 'noopener')
}

async function onDelete(id: number): Promise<void> {
  await fileDelete(id)
  ElMessage.success(t('file.deleteSuccess'))
  load()
}

function formatSize(size: number): string {
  if (!size) {
    return '0 B'
  }
  if (size < 1024) {
    return `${size} B`
  }
  if (size < 1024 * 1024) {
    return `${(size / 1024).toFixed(1)} KB`
  }
  return `${(size / 1048576).toFixed(2)} MB`
}

loadCategories()
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}

.file-thumb {
  width: 34px;
  height: 34px;
  border-radius: 6px;
}

.file-thumb-icon {
  color: var(--art-muted);
}

/* PDF 预览：暗色模式下强制浏览器阅读器用浅色底，避免「深底深字」看不清 */
.pdf-frame {
  width: 100%;
  height: 72vh;
  background: var(--art-card-bg);
  border: 0;
  color-scheme: light;
}

/* ---- 分类树节点 ---- */
.cat-node {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.cat-node-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cat-node-actions {
  display: flex;
  gap: 6px;
  align-items: center;
  opacity: 0;
  transition: opacity 0.15s ease;
}

.cat-node:hover .cat-node-actions {
  opacity: 1;
}

.cat-node-actions :deep(.el-icon) {
  font-size: 13px;
  color: var(--art-muted);
  cursor: pointer;
}

.cat-node-actions :deep(.el-icon:hover) {
  color: var(--el-color-primary);
}
</style>
