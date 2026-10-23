<template>
  <el-drawer
    :model-value="exportStore.visible"
    :title="t('core.layout.messageCenter')"
    :with-header="false"
    size="560px"
    append-to-body
    @close="exportStore.close()"
  >
    <el-tabs v-model="exportStore.activeTab" class="msg-tabs">
      <!-- 我的消息 -->
      <el-tab-pane :label="t('core.layout.notice')" name="notice">
        <div class="notice-head">
          <el-button v-if="noticeStore.unread > 0" link type="primary" size="small" @click="markAllRead">
            {{ t('core.layout.noticeAllRead') }}
          </el-button>
        </div>

        <div v-loading="noticeStore.loading" class="notice-list">
          <el-empty
            v-if="noticeStore.items.length === 0"
            :description="t('core.layout.noticeEmpty')"
            :image-size="70"
          />
          <div
            v-for="item in noticeStore.items"
            :key="item.id"
            class="notice-item"
            :class="{ 'is-read': item.is_read }"
            @click="openNotice(item)"
          >
            <div class="notice-item-title">
              <span class="notice-dot" :class="{ 'is-hidden': item.is_read }" />
              <span class="notice-text">{{ item.title }}</span>
              <el-tag :type="item.level === 2 ? 'danger' : 'info'" size="small" effect="plain">
                {{ item.level === 2 ? t('core.layout.noticeImportant') : t('core.layout.noticeNormal') }}
              </el-tag>
            </div>
            <div class="notice-item-meta">
              <el-tag :type="item.type === 2 ? 'primary' : 'success'" size="small" effect="plain">
                {{ item.type === 2 ? t('core.layout.noticeAnnouncement') : t('core.layout.noticeNotification') }}
              </el-tag>
              <span class="notice-item-time">{{ item.publish_at || item.create_time }}</span>
              <span class="notice-item-status" :class="{ 'is-read': item.is_read }">
                {{ item.is_read ? t('core.layout.noticeRead') : t('core.layout.noticeUnread') }}
              </span>
            </div>
          </div>
        </div>
      </el-tab-pane>

      <!-- 导出任务 -->
      <el-tab-pane :label="t('cccms.export.title')" name="export">
        <div class="export-toolbar">
          <el-button size="small" :loading="exportStore.loading" @click="exportStore.load()">
            <template #icon><i class="ri-refresh-line" /></template>
            {{ t('cccms.export.refresh') }}
          </el-button>
          <span class="export-hint">{{ t('cccms.export.hint') }}</span>
        </div>

        <el-empty
          v-if="exportStore.items.length === 0 && !exportStore.loading"
          :description="t('cccms.export.empty')"
          :image-size="80"
        />
        <el-table v-else v-loading="exportStore.loading" :data="exportStore.items" size="small" class="export-table">
          <el-table-column :label="t('cccms.export.type')" width="120">
            <template #default="{ row }">{{ typeLabel(row.type) }}</template>
          </el-table-column>
          <el-table-column :label="t('cccms.export.rows')" width="80" align="right">
            <template #default="{ row }">{{ row.total_rows }}</template>
          </el-table-column>
          <el-table-column :label="t('cccms.export.createdAt')" min-width="150">
            <template #default="{ row }">{{ row.create_time }}</template>
          </el-table-column>
          <el-table-column :label="t('cccms.export.status')" width="90" align="center">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="t('core.table.action')" width="110" align="center">
            <template #default="{ row }">
              <!-- 完成直接下载；待处理时点击会触发后端同步生成并下载（Windows 无定时进程的兜底） -->
              <el-button v-if="row.status === 0 || row.status === 2" link type="primary" @click="download(row)">
                <template #icon><i class="ri-download-2-line" /></template>
                {{ row.status === 2 ? t('cccms.export.download') : t('cccms.export.generateDownload') }}
              </el-button>
              <span v-else-if="row.status === 3" class="export-error" :title="row.error">{{ row.error }}</span>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <!-- 消息详情 -->
    <el-dialog
      v-model="noticeDetailVisible"
      :title="currentNotice?.title || t('core.layout.noticeDetail')"
      width="640px"
      append-to-body
    >
      <div class="notice-detail-meta">
        <el-tag size="small" effect="plain">
          {{ currentNotice?.type === 2 ? t('core.layout.noticeAnnouncement') : t('core.layout.noticeNotification') }}
        </el-tag>
        <span>{{ currentNotice?.publish_at || currentNotice?.create_time }}</span>
      </div>
      <!-- 正文是富文本 HTML：必须过 sanitizeHtml 再 v-html，否则历史脏数据可触发 XSS -->
      <div class="notice-detail-content" v-html="safeContent"></div>
    </el-dialog>
  </el-drawer>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { exportTaskDownload, type ExportTaskRow } from '@/api/export'
import type { NoticeRow } from '@/api/notice'
import { useNoticeStore } from '@/stores/notice'
import { useExportTaskStore } from '@/stores/exportTask'
import { sanitizeHtml } from '@/utils/richText'

const { t } = useI18n({ useScope: 'global' })
const noticeStore = useNoticeStore()
const exportStore = useExportTaskStore()

// 打开时拉取消息；导出任务在 `open()` 里已刷新，切到该 tab 时数据是新的
watch(
  () => exportStore.visible,
  (visible) => {
    if (visible) {
      void noticeStore.load({ page: 1, limit: 20 })
    }
  },
)

/* ---- 我的消息 ---- */
const noticeDetailVisible = ref(false)
const currentNotice = ref<NoticeRow | null>(null)

/** 详情正文净化后渲染（库中可能存有编辑器接入前录入的历史内容） */
const safeContent = computed(() => sanitizeHtml(currentNotice.value?.content ?? ''))

async function openNotice(item: NoticeRow): Promise<void> {
  currentNotice.value = item
  noticeDetailVisible.value = true
  try {
    await noticeStore.markRead(item.id)
  } catch {
    // 标记已读失败不影响阅读
  }
}

async function markAllRead(): Promise<void> {
  try {
    await noticeStore.markAllRead()
    ElMessage.success(t('core.layout.noticeAllReadSuccess'))
  } catch {
    // 拦截器已提示
  }
}

/* ---- 导出任务 ---- */
function typeLabel(type: string): string {
  const map: Record<string, string> = {
    log: t('cccms.export.type_log'),
  }
  return map[type] ?? type
}

function statusText(status: number): string {
  const map: Record<number, string> = {
    0: t('cccms.export.statusPending'),
    1: t('cccms.export.statusRunning'),
    2: t('cccms.export.statusDone'),
    3: t('cccms.export.statusFailed'),
    4: t('cccms.export.statusExpired'),
  }
  return map[status] ?? String(status)
}

function statusTag(status: number): 'info' | 'warning' | 'success' | 'danger' {
  const map: Record<number, 'info' | 'warning' | 'success' | 'danger'> = {
    0: 'info',
    1: 'warning',
    2: 'success',
    3: 'danger',
    4: 'info',
  }
  return map[status] ?? 'info'
}

function download(row: ExportTaskRow): void {
  void exportTaskDownload(row.id)
}
</script>

<style scoped>
.msg-tabs {
  margin-top: -8px;
}

/* ---- 我的消息 ---- */
.notice-head {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 4px;
}

.notice-list {
  min-height: 120px;
}

.notice-item {
  padding: 10px 4px;
  cursor: pointer;
  border-bottom: 1px solid var(--art-card-border);
}

.notice-item:hover {
  background: var(--art-hover-bg);
}

.notice-item.is-read .notice-text {
  color: var(--art-sub);
}

.notice-item-title {
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 14px;
  color: var(--art-main);
}

.notice-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-dot {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  background: var(--art-danger);
  border-radius: 50%;
}

.notice-dot.is-hidden {
  visibility: hidden;
}

.notice-item-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  padding-left: 13px;
  margin-top: 6px;
  font-size: 12px;
  color: var(--art-muted);
}

.notice-item-time {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-item-status {
  flex-shrink: 0;
  margin-left: auto;
  color: var(--art-danger);
}

.notice-item-status.is-read {
  color: var(--art-muted);
}

.notice-detail-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
  font-size: 12px;
  color: var(--art-muted);
}

.notice-detail-content {
  font-size: 14px;
  line-height: 1.7;
  color: var(--art-main);
  /* 历史数据是纯文本（换行需保留），HTML 正文由块级元素自身排版，pre-wrap 不影响 */
  white-space: pre-wrap;
  word-break: break-word;
}

/*
 * 正文由 v-html 注入，子节点不带 scoped 属性，必须走 :deep()。
 * Tailwind preflight 清掉了标题与列表样式，这里按编辑器的排版显式恢复，
 * 保证消息详情与 ArtRichEditor 里所见一致。
 */
.notice-detail-content :deep(p) {
  margin: 0 0 8px;
}

.notice-detail-content :deep(p:last-child) {
  margin-bottom: 0;
}

.notice-detail-content :deep(h1),
.notice-detail-content :deep(h2),
.notice-detail-content :deep(h3),
.notice-detail-content :deep(h4) {
  margin: 12px 0 8px;
  font-weight: 600;
  line-height: 1.4;
}

.notice-detail-content :deep(h1) {
  font-size: 22px;
}

.notice-detail-content :deep(h2) {
  font-size: 19px;
}

.notice-detail-content :deep(h3) {
  font-size: 16px;
}

.notice-detail-content :deep(h4) {
  font-size: 15px;
}

.notice-detail-content :deep(ul),
.notice-detail-content :deep(ol) {
  margin: 0 0 8px;
  padding-left: 22px;
}

.notice-detail-content :deep(ul) {
  list-style: disc;
}

.notice-detail-content :deep(ol) {
  list-style: decimal;
}

.notice-detail-content :deep(li) {
  margin: 0 0 4px;
}

.notice-detail-content :deep(blockquote) {
  margin: 0 0 8px;
  padding-left: 10px;
  color: var(--art-sub);
  border-left: 3px solid var(--el-border-color);
}

.notice-detail-content :deep(code) {
  padding: 2px 4px;
  font-family: Consolas, Menlo, monospace;
  font-size: 13px;
  background: var(--el-fill-color);
  border-radius: 4px;
}

.notice-detail-content :deep(pre) {
  margin: 0 0 8px;
  padding: 10px 12px;
  overflow-x: auto;
  font-family: Consolas, Menlo, monospace;
  font-size: 13px;
  background: var(--el-fill-color-dark);
  border-radius: 6px;
}

.notice-detail-content :deep(pre code) {
  padding: 0;
  background: transparent;
}

.notice-detail-content :deep(a) {
  color: var(--art-primary);
  text-decoration: underline;
}

.notice-detail-content :deep(img) {
  max-width: 100%;
  height: auto;
}

.notice-detail-content :deep(hr) {
  margin: 12px 0;
  border: none;
  border-top: 1px solid var(--el-border-color-lighter);
}

/* ---- 导出任务 ---- */
.export-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.export-hint {
  font-size: 12px;
  color: var(--art-muted);
}

.export-error {
  display: inline-block;
  max-width: 90px;
  overflow: hidden;
  font-size: 12px;
  color: var(--art-danger);
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
