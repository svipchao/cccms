<template>
  <div class="trace-item">
    <div class="trace-head">
      <el-tag effect="plain" size="small">{{ row.method }}</el-tag>
      <span class="trace-path">{{ row.path }}</span>
      <el-tag :type="statusTag(row.status_code)" effect="light" size="small">
        {{ row.status_code }}
      </el-tag>
      <span class="trace-cost">{{ row.cost }} ms</span>
    </div>

    <div class="trace-meta">
      {{ row.title || '—' }}
      <span v-if="row.node"> · {{ row.node }}</span>
      · {{ row.username || '—' }}
    </div>

    <!-- 请求 ID 是"单条记录"的定位依据；操作 ID 在外层抽屉标题上 -->
    <div class="trace-request">
      {{ t('log.requestId') }}: <span class="trace-id">{{ row.trace_id || '—' }}</span>
    </div>

    <div v-if="row.message" class="trace-msg">{{ row.message }}</div>

    <!-- 参数与结果就地展开：排障时不用再跳回「详情」抽屉 -->
    <el-collapse v-if="row.params || row.result" class="trace-collapse">
      <el-collapse-item :title="t('log.params')" :name="`params-${row.id}`">
        <pre class="log-pre">{{ pretty(row.params) }}</pre>
      </el-collapse-item>
      <el-collapse-item :title="t('log.response')" :name="`result-${row.id}`">
        <pre class="log-pre">{{ pretty(row.result) }}</pre>
      </el-collapse-item>
    </el-collapse>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LogRow } from '@/api/log'

defineProps<{ row: LogRow }>()

const { t } = useI18n({ useScope: 'global' })

function statusTag(code?: number): 'success' | 'warning' | 'danger' {
  if (!code) {
    return 'danger'
  }
  if (code < 300) {
    return 'success'
  }
  return code < 400 ? 'warning' : 'danger'
}

/** 参数/结果按 JSON 存，能解析就格式化，否则原样显示（超长会被截断，JSON 不完整） */
function pretty(raw?: string): string {
  if (!raw) {
    return '—'
  }
  try {
    return JSON.stringify(JSON.parse(raw), null, 2)
  } catch {
    return raw
  }
}
</script>

<style scoped>
.trace-item {
  padding: 8px 10px;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 2px);
}

.trace-head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.trace-path {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-main);
  word-break: break-all;
}

.trace-cost {
  margin-left: auto;
  font-size: 12px;
  color: var(--art-muted);
}

.trace-meta {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.trace-request {
  margin-top: 2px;
  font-size: var(--art-fs-caption);
  color: var(--art-muted);
}

.trace-id {
  font-family: Consolas, Monaco, monospace;
  user-select: all;
}

.trace-msg {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-danger-text);
}

.trace-collapse {
  margin-top: 8px;
  border-top: none;
}

.trace-collapse :deep(.el-collapse-item__header) {
  height: 30px;
  font-size: 12px;
  color: var(--art-sub);
  background: transparent;
}

.trace-collapse :deep(.el-collapse-item__wrap) {
  background: transparent;
  border-bottom: none;
}

.trace-collapse :deep(.el-collapse-item__content) {
  padding-bottom: 0;
}

.log-pre {
  max-height: 200px;
  padding: 12px;
  margin: 0;
  overflow: auto;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  background: var(--art-card-bg);
  border-radius: calc(var(--art-radius) - 2px);
}
</style>
