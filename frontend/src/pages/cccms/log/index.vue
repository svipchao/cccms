<template>
  <div class="art-fill">
    <!-- 标签页：操作日志 / 登录分析（后者需要 cccms:log:analysis，无权限时不渲染入口） -->
    <div v-if="canAnalyse" class="log-tabs">
      <el-radio-group v-model="activeTab" size="small">
        <el-radio-button value="operation">{{ t('log.tabOperation') }}</el-radio-button>
        <el-radio-button value="login">{{ t('log.tabLogin') }}</el-radio-button>
      </el-radio-group>
    </div>

    <ArtTable
      v-show="activeTab === 'operation'"
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      storage-key="cccms:log:v2"
      selection
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
      <template #toolbar>
        <el-button
          v-auth="'cccms:log:delete'"
          type="danger"
          plain
          :disabled="selection.length === 0"
          @click="onBatchDelete"
          ><template #icon><i class="ri-delete-bin-line" /></template>
          {{ selection.length ? t('log.deleteSelectedCount', { count: selection.length }) : t('log.deleteSelected') }}
        </el-button>
      </template>

      <template #toolbar-right>
        <el-button v-auth="'cccms:log:export'" @click="onExport"
          ><template #icon><i class="ri-download-2-line" /></template> {{ t('common.export') }}
        </el-button>
      </template>

      <!-- 语义化操作：注解标题 + 权限节点 + 结果说明（登录失败原因等） -->
      <template #action_name="{ row }">
        <div class="log-title">{{ row.title || '—' }}</div>
        <div v-if="row.node" class="log-node">{{ row.node }}</div>
        <div v-if="row.message" class="log-msg" :class="row.status === 1 ? 'is-ok' : 'is-danger'">
          {{ row.message }}
        </div>
      </template>

      <!-- 操作人：用户名 + IP（IP 的模糊搜索在顶部搜索区） -->
      <template #operator="{ row }">
        <div>{{ row.username || '—' }}</div>
        <div v-if="row.ip" class="log-sub">{{ row.ip }}</div>
      </template>

      <!-- 请求：方法一行、路径一行；方法标签可点，按该方法筛选（再点取消） -->
      <template #request="{ row }">
        <div>
          <el-tag
            effect="plain"
            size="small"
            round
            class="log-click-tag log-method-tag"
            :class="{ 'is-active': query.method === row.method }"
            :title="t('log.clickToFilter')"
            @click.stop="filterByMethod(row.method)"
          >
            {{ row.method }}
          </el-tag>
        </div>
        <div class="log-path" :title="row.path">{{ row.path }}</div>
      </template>

      <!-- 结果：成功/失败一行、HTTP 状态码一行；结果标签可点，按该结果筛选（再点取消） -->
      <template #result="{ row }">
        <div>
          <el-tag
            :type="row.status === 1 ? 'success' : 'danger'"
            effect="plain"
            size="small"
            round
            class="log-click-tag log-status-tag"
            :class="{
              'is-active': String(row.status) === query.status,
              'is-success': row.status === 1,
              'is-danger': row.status !== 1,
            }"
            :title="t('log.clickToFilter')"
            @click.stop="filterByStatus(row.status)"
          >
            {{ row.status === 1 ? t('log.success') : t('log.failed') }}
          </el-tag>
        </div>
        <div class="log-code" :class="statusTone(row.status_code)">{{ row.status_code }}</div>
      </template>

      <!-- 追踪：操作ID（本次操作链路）+ 会话ID（页面会话轨迹），行首标注字段，无权限时只展示 -->
      <template #trace="{ row }">
        <div class="log-trace-line">
          <span class="log-trace-tag">{{ t('log.actionId') }}</span>
          <el-button
            v-if="row.action_id && canAction"
            link
            type="primary"
            class="log-trace-id"
            @click="openAction(row.action_id)"
          >
            {{ row.action_id }}
          </el-button>
          <span v-else-if="row.action_id" class="log-trace log-trace-id">{{ row.action_id }}</span>
          <span v-else>—</span>
        </div>
        <div v-if="row.session_id" class="log-trace-line">
          <span class="log-trace-tag">{{ t('log.sessionId') }}</span>
          <el-button
            v-if="canSession"
            link
            type="primary"
            class="log-trace-id"
            @click="openSession(row.session_id)"
          >
            {{ row.session_id }}
          </el-button>
          <span v-else class="log-trace log-trace-id">{{ row.session_id }}</span>
        </div>
      </template>

      <!-- 操作列只保留详情 / 删除：操作ID 与 会话ID 已并进「追踪」列 -->
      <template #action="{ row }">
        <el-button link type="primary" @click="openDetail(row)">{{ t('log.detail') }}</el-button>
        <el-button v-auth="'cccms:log:delete'" link type="danger" @click="onDeleteOne(row.id)">
          {{ t('common.delete') }}
        </el-button>
      </template>
    </ArtTable>

    <!-- 登录安全分析（P2-13）：失败趋势 / TOP 账号 / TOP IP / 异地登录 -->
    <div v-if="canAnalyse && activeTab === 'login'" v-loading="analysisLoading" class="login-analysis">
      <div class="analysis-bar">
        <el-radio-group v-model="analysisDays" size="small">
          <el-radio-button :value="7">{{ t('log.analysisRange7') }}</el-radio-button>
          <el-radio-button :value="30">{{ t('log.analysisRange30') }}</el-radio-button>
          <el-radio-button :value="90">{{ t('log.analysisRange90') }}</el-radio-button>
        </el-radio-group>
        <el-button size="small" @click="loadAnalysis"
          ><template #icon><i class="ri-refresh-line" /></template>{{ t('log.analysisRefresh') }}</el-button
        >
      </div>

      <template v-if="analysis">
        <div class="analysis-cards">
          <div v-for="card in analysisCards" :key="card.label" class="analysis-card">
            <div class="analysis-card-value" :class="card.tone">{{ card.value }}</div>
            <div class="analysis-card-label">{{ card.label }}</div>
          </div>
        </div>

        <div class="analysis-panel">
          <div class="analysis-panel-title">{{ t('log.analysisTrend') }}</div>
          <div ref="trendRef" class="analysis-chart" />
        </div>

        <div class="analysis-grid">
          <div class="analysis-panel">
            <div class="analysis-panel-title">{{ t('log.analysisTopUsers') }}</div>
            <el-empty v-if="analysis.top_users.length === 0" :description="t('log.analysisEmpty')" :image-size="60" />
            <el-table v-else :data="analysis.top_users" size="small" :show-header="false">
              <el-table-column prop="username" />
              <el-table-column prop="count" width="80" align="right" />
            </el-table>
          </div>

          <div class="analysis-panel">
            <div class="analysis-panel-title">{{ t('log.analysisTopIps') }}</div>
            <el-empty v-if="analysis.top_ips.length === 0" :description="t('log.analysisEmpty')" :image-size="60" />
            <el-table v-else :data="analysis.top_ips" size="small" :show-header="false">
              <el-table-column prop="ip" />
              <el-table-column prop="count" width="80" align="right" />
            </el-table>
          </div>
        </div>

        <div class="analysis-panel">
          <div class="analysis-panel-title">{{ t('log.analysisIpChanges') }}</div>
          <el-empty v-if="analysis.ip_changes.length === 0" :description="t('log.analysisNoChange')" :image-size="60" />
          <el-table v-else :data="analysis.ip_changes" size="small">
            <el-table-column prop="username" :label="t('log.username')" width="160" />
            <el-table-column prop="from_ip" :label="t('log.analysisFromIp')" width="160" />
            <el-table-column prop="to_ip" :label="t('log.analysisToIp')" width="160" />
            <el-table-column prop="time" :label="t('log.analysisTime')" />
          </el-table>
        </div>

        <div class="analysis-hint">{{ t('log.analysisHint') }}</div>
      </template>
    </div>

    <el-drawer v-model="detailVisible" :title="t('log.detailTitle')" size="680px">
      <div v-loading="detailLoading" class="detail-body">
      <template v-if="current">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item :label="t('log.result')">
            <el-tag :type="current.status === 1 ? 'success' : 'danger'" effect="light" size="small">
              {{ current.status === 1 ? t('log.success') : t('log.failed') }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item :label="t('log.actionName')">{{ current.title || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.node')">{{ current.node || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.username')">{{ current.username || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.time')">{{ current.create_time || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.request')">{{ current.method }} {{ current.path }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.statusCode')">
            <el-tag :type="statusTag(current.status_code)" effect="light" size="small">
              {{ current.status_code }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="IP">{{ current.ip || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.cost')">{{ current.cost ?? 0 }} ms</el-descriptions-item>
          <el-descriptions-item :label="t('log.requestId')">
            <span class="log-trace">{{ current.trace_id || '—' }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('log.actionId')" :span="2">
            <span class="log-trace">{{ current.action_id || '—' }}</span>
            <el-button
              v-if="current.action_id"
              v-auth="'cccms:log:action'"
              link
              type="primary"
              class="log-trace-btn"
              @click="openAction(current.action_id)"
            >
              {{ t('log.viewAction') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item :label="t('log.sessionId')" :span="2">
            <span class="log-trace">{{ current.session_id || '—' }}</span>
            <el-button
              v-if="current.session_id"
              v-auth="'cccms:log:session'"
              link
              type="primary"
              class="log-trace-btn"
              @click="openSession(current.session_id)"
            >
              {{ t('log.viewSession') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item v-if="current.message" :label="t('log.message')" :span="2">
            {{ current.message }}
          </el-descriptions-item>
          <el-descriptions-item label="User-Agent" :span="2">
            {{ current.ua || '—' }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="log-block">
          <div class="log-block-title">{{ t('log.params') }}</div>
          <pre class="log-pre">{{ pretty(current.params) }}</pre>
        </div>

        <div class="log-block">
          <div class="log-block-title">{{ t('log.response') }}</div>
          <pre class="log-pre">{{ pretty(current.result) }}</pre>
        </div>
      </template>
      </div>
    </el-drawer>

    <!-- 链路视图：同一次用户操作的全部记录按时间升序 -->
    <el-drawer
      v-model="actionVisible"
      :title="t('log.actionTitle', { id: actionId || '—' })"
      size="760px"
      @closed="onActionClosed"
    >
      <div v-loading="actionLoading" class="trace-body">
        <template v-if="action">
          <el-descriptions :column="3" border size="small">
            <el-descriptions-item :label="t('log.recordCount')">{{ action.total }}</el-descriptions-item>
            <el-descriptions-item :label="t('log.failedCount')">
              <el-tag :type="action.failed > 0 ? 'danger' : 'success'" effect="light" size="small">
                {{ action.failed }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item :label="t('log.totalCost')">{{ action.cost }} ms</el-descriptions-item>
          </el-descriptions>

          <div class="trace-toolbar">
            <el-button size="small" @click="copyActionId">
              <template #icon><i class="ri-file-copy-line" /></template>{{ t('log.actionCopy') }}
            </el-button>
            <el-button size="small" :loading="actionLoading" @click="reloadAction">
              <template #icon><i class="ri-refresh-line" /></template>{{ t('common.refresh') }}
            </el-button>
          </div>

          <!-- 一次操作属于某次页面会话：从这里可以继续往上看整段轨迹 -->
          <div v-if="action.session_id && canSession" class="trace-jump">
            <span
              >{{ t('log.sessionId') }}: <span class="log-trace">{{ action.session_id }}</span></span
            >
            <el-button link type="primary" @click="openSession(action.session_id)">
              {{ t('log.viewSession') }}
            </el-button>
          </div>

          <el-alert
            v-if="action.truncated"
            class="trace-alert"
            type="warning"
            :closable="false"
            show-icon
            :title="t('log.chainTruncated', { limit: ACTION_LIMIT })"
          />

          <el-empty v-if="action.total === 0" :description="t('log.actionEmpty')">
            <div class="trace-empty-hint">{{ t('log.actionEmptyHint') }}</div>
          </el-empty>

          <el-timeline v-else class="trace-timeline">
            <el-timeline-item
              v-for="row in action.list"
              :key="row.id"
              :timestamp="row.create_time"
              :type="row.status === 1 ? 'primary' : 'danger'"
              placement="top"
            >
              <TraceRecord :row="row" />
            </el-timeline-item>
          </el-timeline>

          <div class="trace-hint">{{ t('log.chainScopeHint') }}</div>
        </template>

        <!-- 加载失败：明确区分「没有记录」与「没取到」，并给重试入口 -->
        <el-empty v-else-if="!actionLoading" :description="actionError ? t('log.chainFailed') : t('log.actionEmpty')">
          <el-button size="small" @click="reloadAction">{{ t('common.refresh') }}</el-button>
        </el-empty>
      </div>
    </el-drawer>

    <!-- 会话轨迹（审计视角）：一次页面会话内的操作按时间串联，操作内再展开请求 -->
    <el-drawer
      v-model="sessionVisible"
      :title="t('log.sessionTitle', { id: sessionId || '—' })"
      size="760px"
      @closed="onSessionClosed"
    >
      <div v-loading="sessionLoading && sessionRows.length === 0" class="trace-body">
        <template v-if="sessionGroups.length > 0">
          <el-descriptions :column="3" border size="small">
            <el-descriptions-item :label="t('log.recordCount')">{{ sessionTotal }}</el-descriptions-item>
            <el-descriptions-item :label="t('log.sessionActions')">{{ sessionGroups.length }}</el-descriptions-item>
            <el-descriptions-item :label="t('log.sessionLoaded')">{{ sessionRows.length }}</el-descriptions-item>
          </el-descriptions>

          <div class="trace-toolbar">
            <el-button size="small" :loading="sessionLoading" @click="reloadSession">
              <template #icon><i class="ri-refresh-line" /></template>{{ t('common.refresh') }}
            </el-button>
          </div>

          <div v-for="group in sessionGroups" :key="group.key" class="session-group">
            <div class="session-head">
              <span class="session-time">{{ group.time }}</span>
              <el-button v-if="group.actionId && canAction" link type="primary" @click="openAction(group.actionId)">
                {{ group.actionId }}
              </el-button>
              <span v-else-if="group.actionId" class="log-trace">{{ group.actionId }}</span>
              <span v-else class="session-unknown">{{ t('log.sessionUnknownAction') }}</span>
              <el-tag effect="plain" size="small">
                {{ t('log.sessionRequestCount', { count: group.rows.length }) }}
              </el-tag>
              <el-tag v-if="group.failed > 0" type="danger" effect="light" size="small">
                {{ t('log.sessionActionFailed', { count: group.failed }) }}
              </el-tag>
              <span class="session-cost">{{ group.cost }} ms</span>
            </div>
            <TraceRecord v-for="row in group.rows" :key="row.id" :row="row" />
          </div>

          <div class="session-more">
            <el-button v-if="sessionHasMore" size="small" :loading="sessionLoading" @click="loadSessionPage(false)">
              {{ t('log.sessionLoadMore') }}
            </el-button>
            <span v-else class="trace-hint">{{ t('log.sessionNoMore') }}</span>
          </div>

          <div class="trace-hint">{{ t('log.chainScopeHint') }}</div>
        </template>

        <el-empty
          v-else-if="!sessionLoading"
          :description="sessionError ? t('log.chainFailed') : t('log.sessionEmpty')"
        >
          <el-button size="small" @click="reloadSession">{{ t('common.refresh') }}</el-button>
        </el-empty>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:log' })

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'
import {
  logAction,
  logDelete,
  logDetail,
  logExport,
  logList,
  logLoginAnalysis,
  logSession,
  type LogAction,
  type LogRow,
} from '@/api/log'
import { useExportTaskStore } from '@/stores/exportTask'
import type { ArtTableColumn } from '@/types/table'
import TraceRecord from './TraceRecord.vue'

// 按需引入：与工作台同一取舍（只用到折线图，不必整包）
echarts.use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

const { t, locale } = useI18n({ useScope: 'global' })

interface Query {
  username: string
  title: string
  path: string
  /** 请求 ID（单条记录） */
  trace_id: string
  /** 操作 ID（一次操作，多个请求） */
  action_id: string
  /** 会话 ID（一次页面会话） */
  session_id: string
  /** 结果 1 成功 / 0 失败，多选值以逗号串传递 */
  status: string
  /** 请求方法，多选值以逗号串传递 */
  method: string
  ip: string
  /** 请求参数关键字（模糊匹配，text 列全表扫，后端未给时间范围时默认只看最近 7 天） */
  params_like: string
  /** 返回结果关键字（同上） */
  result_like: string
  /** 操作时间范围（列头日期筛选写入） */
  start: string
  end: string
}

/** 请求方法枚举：接口日志里出现过的几种（GET 查询 / POST 新增 / PUT 更新 / DELETE 删除） */
const METHOD_OPTIONS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'].map((method) => ({
  label: method,
  value: method,
}))

/** 表头文案走 i18n：用 computed 包住，切换语言时能实时重渲染 */
const columns = computed<ArtTableColumn[]>(() => [
  /*
   * 请求内容 / 返回结果：列默认隐藏（表格里不显示），只为顶部搜索区提供入口。
   * 放在数组最前，保证搜索区折叠时这两项也直接可见，不必点「展开」才找到。
   */
  {
    prop: 'params',
    label: t('log.paramsContent'),
    minWidth: 200,
    defaultShow: false,
    formatter: (row) => clip(row.params),
    filter: { type: 'text', queryKey: 'params_like', placeholder: t('log.keywordPlaceholder') },
  },
  {
    prop: 'result',
    label: t('log.responseContent'),
    minWidth: 200,
    defaultShow: false,
    formatter: (row) => clip(row.result),
    filter: { type: 'text', queryKey: 'result_like', placeholder: t('log.keywordPlaceholder') },
  },

  // 可见列：合并为 8 列（时间 / 操作 / 操作人 / 请求 / 结果 / 追踪 / 耗时 / 操作）
  // 耗时单独留一列：它是排障核心指标，需要列头排序入口（并入时间列就没有排序了）
  { prop: 'create_time', label: t('log.time'), width: 170, filter: { type: 'date' }, sortable: 'custom' },
  { prop: 'title', label: t('log.actionName'), minWidth: 200, slot: 'action_name', filter: { type: 'text' } },
  { prop: 'username', label: t('log.username'), width: 130, slot: 'operator', filter: { type: 'text' } },
  { prop: 'path', label: t('log.path'), minWidth: 240, slot: 'request', filter: { type: 'text' } },
  {
    prop: 'status',
    label: t('log.result'),
    width: 120,
    align: 'center',
    slot: 'result',
    filter: {
      type: 'enum',
      options: [
        { label: t('log.success'), value: 1 },
        { label: t('log.failed'), value: 0 },
      ],
    },
  },
  { prop: 'action_id', label: t('log.trace'), width: 260, slot: 'trace', filter: { type: 'text' } },
  { prop: 'cost', label: t('log.costMs'), width: 110, align: 'right', sortable: 'custom' },
  { prop: 'action', label: t('table.action'), width: 140, fixed: 'right', slot: 'action', lockVisible: true },

  /*
   * 以下列默认隐藏：表格里已合并展示，这里只为顶部搜索区提供筛选入口。
   * ArtTable 的搜索项按「声明了 filter 的列」生成，与列是否可见无关。
   */
  { prop: 'id', label: 'ID', width: 76, defaultShow: false },
  {
    prop: 'method',
    label: t('log.method'),
    width: 90,
    defaultShow: false,
    filter: { type: 'enum', options: METHOD_OPTIONS },
  },
  { prop: 'ip', label: 'IP', width: 140, defaultShow: false, filter: { type: 'text' } },
  { prop: 'session_id', label: t('log.sessionId'), width: 220, defaultShow: false, filter: { type: 'text' } },
  { prop: 'trace_id', label: t('log.requestId'), width: 220, defaultShow: false, filter: { type: 'text' } },
])

/** 隐藏列（参数 / 结果）在列设置里被打开时只显示摘要，避免整段 JSON 撑开表格 */
function clip(raw?: string): string {
  const text = String(raw ?? '')
  return text === '' ? '—' : text.length > 80 ? `${text.slice(0, 80)}…` : text
}

/**
 * 支持从报错弹窗直接跳进来：
 *   /log?action_id=xxx  看本次操作；
 *   /log?session_id=xxx 看本次会话轨迹；
 *   /log?trace_id=xxx   按请求 ID 过滤列表（单条记录）。
 */
const route = useRoute()
const initialTraceId = typeof route.query.trace_id === 'string' ? route.query.trace_id : ''
const initialActionId = typeof route.query.action_id === 'string' ? route.query.action_id : ''
const initialSessionId = typeof route.query.session_id === 'string' ? route.query.session_id : ''

const {
  list,
  loading,
  total,
  page,
  limit,
  query,
  selection,
  load,
  search,
  onPageChange,
  onLimitChange,
  onSelectionChange,
} = useTable<LogRow, Query>({
  api: logList,
  initialQuery: {
    username: '',
    title: '',
    path: '',
    trace_id: initialTraceId,
    action_id: initialActionId,
    session_id: initialSessionId,
    status: '',
    method: '',
    ip: '',
    params_like: '',
    result_like: '',
    start: '',
    end: '',
  },
  pageSize: 15,
})

/* ---- 登录分析（P2-13） ---- */
const activeTab = ref<'operation' | 'login'>('operation')
const analysisDays = ref(7)
const analysis = ref<Awaited<ReturnType<typeof logLoginAnalysis>> | null>(null)
const analysisLoading = ref(false)
const trendRef = ref<HTMLElement>()
let trendChart: echarts.ECharts | null = null

const setting = useSettingStore()
const userStore = useUserStore()
const exportTaskStore = useExportTaskStore()
/** 没有分析权限时不渲染入口，避免「点进去 403」 */
const canAnalyse = computed(() => userStore.hasAuth('cccms:log:analysis'))

const analysisCards = computed(() => {
  const s = analysis.value?.summary
  if (!s) {
    return []
  }

  return [
    { label: t('log.analysisTotal'), value: String(s.total), tone: '' },
    { label: t('log.analysisSuccess'), value: String(s.success), tone: 'is-ok' },
    { label: t('log.analysisFailed'), value: String(s.failed), tone: s.failed > 0 ? 'is-danger' : '' },
    { label: t('log.analysisFailRate'), value: `${s.fail_rate}%`, tone: s.fail_rate > 0 ? 'is-danger' : '' },
    { label: t('log.analysisFailUsers'), value: String(s.users), tone: '' },
    { label: t('log.analysisFailIps'), value: String(s.ips), tone: '' },
  ]
})

// 请求序号：切 tab 与改天数并发触发时，先发后至的旧结果不能覆盖新结果
let analysisSeq = 0

async function loadAnalysis(): Promise<void> {
  if (!canAnalyse.value) {
    return
  }

  const seq = ++analysisSeq
  analysisLoading.value = true
  try {
    const result = await logLoginAnalysis({ days: analysisDays.value })
    if (seq !== analysisSeq) {
      return
    }
    analysis.value = result
    await nextTick()
    renderTrend()
  } finally {
    if (seq === analysisSeq) {
      analysisLoading.value = false
    }
  }
}

/** 主题色 / 文字色取自 CSS 变量，与全局主题保持一致 */
function cssVar(name: string, fallback: string): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

function renderTrend(): void {
  if (!trendRef.value || !analysis.value) {
    return
  }
  trendChart ??= echarts.init(trendRef.value)

  const primary = cssVar('--art-primary', '#2b6cff')
  const danger = cssVar('--art-danger', '#e34d59')
  const line = cssVar('--art-card-border', '#e9edf5')
  const sub = cssVar('--art-sub', '#5b6474')

  const hourly = analysis.value.range.granularity === 'hour'
  // 小时粒度只展示「时:分」，天粒度展示「月-日」，否则 X 轴会被年份挤满
  const labels = analysis.value.trend.map((item) => (hourly ? item.bucket.slice(11, 16) : item.bucket.slice(5, 10)))

  trendChart.setOption({
    grid: { left: 6, right: 18, top: 32, bottom: 4, containLabel: true },
    tooltip: { trigger: 'axis' },
    legend: { top: 0, right: 0, textStyle: { color: sub } },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: labels,
      axisLine: { lineStyle: { color: line } },
      axisTick: { show: false },
      axisLabel: { color: sub },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: line, type: 'dashed' } },
      axisLabel: { color: sub },
    },
    series: [
      {
        name: t('log.analysisTrendSuccess'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 6,
        data: analysis.value.trend.map((item) => item.success),
        itemStyle: { color: primary },
        lineStyle: { width: 2, color: primary },
      },
      {
        name: t('log.analysisTrendFailed'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 6,
        data: analysis.value.trend.map((item) => item.failed),
        itemStyle: { color: danger },
        lineStyle: { width: 2, color: danger },
      },
    ],
  })
}

// 首次切到分析页才请求（省掉无谓的聚合查询）
watch(activeTab, (tab) => {
  if (tab === 'login' && analysis.value === null) {
    void loadAnalysis()
  }
})
watch(analysisDays, () => {
  if (activeTab.value === 'login') {
    void loadAnalysis()
  }
})
// 主题 / 语言变化后重绘：echarts 是命令式渲染，不会跟着响应式更新
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  renderTrend()
})

function onResize(): void {
  trendChart?.resize()
}

/* ---- 链路视图（一次操作） + 会话轨迹（审计） ---- */
/** 与后端 `LogLogic::ACTION_LIMIT` 一致，仅用于「已截断」提示 */
const ACTION_LIMIT = 500
/** 排障（操作链路）与审计（会话轨迹）是两个权限节点：无权限时不渲染入口，避免「点一下就 403」 */
const canAction = computed(() => userStore.hasAuth('cccms:log:action'))
const canSession = computed(() => userStore.hasAuth('cccms:log:session'))

const actionVisible = ref(false)
const actionLoading = ref(false)
const actionError = ref(false)
const actionId = ref('')
const action = ref<LogAction | null>(null)
/** 请求序号：连续点开不同链路时，先发后至的旧结果不能覆盖新结果 */
let actionSeq = 0

async function loadAction(id: string, keepOnError = false): Promise<void> {
  const seq = ++actionSeq
  actionId.value = id
  actionLoading.value = true
  actionError.value = false
  if (!keepOnError) {
    action.value = null
  }

  try {
    const result = await logAction(id)
    if (seq !== actionSeq) {
      return
    }
    action.value = result
  } catch {
    if (seq !== actionSeq) {
      return
    }
    actionError.value = true
    if (!keepOnError) {
      action.value = null
    }
  } finally {
    if (seq === actionSeq) {
      actionLoading.value = false
    }
  }
}

/**
 * 点请求方法标签：按该方法筛选，再点同一个则取消。
 * 写的是顶部搜索区同一份 query，提交后筛选项会同步高亮。
 */
function filterByMethod(method: string): void {
  if (!method) {
    return
  }
  query.method = query.method === method ? '' : method
  search()
}

/** 点结果标签：按成功/失败筛选，再点同一个则取消 */
function filterByStatus(status: number): void {
  const value = String(status)
  query.status = query.status === value ? '' : value
  search()
}

async function openAction(id: string): Promise<void> {
  if (!id) {
    return
  }
  if (!canAction.value) {
    ElMessage.warning(t('log.chainNoAuth'))
    return
  }

  // 抽屉叠在一起会互相遮挡：从详情 / 会话里点进来时先收起上层
  detailVisible.value = false
  sessionVisible.value = false
  actionVisible.value = true
  await loadAction(id)
}

function reloadAction(): void {
  if (actionId.value) {
    void loadAction(actionId.value, action.value !== null)
  }
}

/** 抽屉关闭即失效：迟到的响应不能把旧链路塞回下一次打开的抽屉 */
function onActionClosed(): void {
  actionSeq++
  actionLoading.value = false
  actionError.value = false
  action.value = null
  actionId.value = ''
}

async function copyActionId(): Promise<void> {
  const id = action.value?.action_id || actionId.value
  if (!id) {
    return
  }
  await copyText(id, t('log.actionCopied'), t('log.actionCopyFailed'))
}

/* ---- 会话轨迹（审计视角） ---- */
const sessionVisible = ref(false)
const sessionLoading = ref(false)
const sessionError = ref(false)
const sessionId = ref('')
const sessionRows = ref<LogRow[]>([])
const sessionTotal = ref(0)
const sessionCursor = ref(0)
const sessionHasMore = ref(false)
let sessionSeq = 0

interface SessionGroup {
  key: string
  actionId: string
  time: string
  cost: number
  failed: number
  rows: LogRow[]
}

/**
 * 会话记录按操作分组。
 *
 * 只合并**相邻**同 operation 的记录：会话是时间序，同一操作的请求本就连续；
 * 这样"加载更多"把同一条操作切在两页时，第二次计算仍会把它们接上，
 * 也不会因为按 action 聚合而打乱时间顺序。
 */
const sessionGroups = computed<SessionGroup[]>(() => {
  const groups: SessionGroup[] = []

  for (const row of sessionRows.value) {
    const key = row.action_id || row.trace_id || `row-${row.id}`
    let group = groups[groups.length - 1]

    if (!group || group.key !== key) {
      group = {
        key,
        actionId: row.action_id || '',
        time: row.create_time,
        cost: 0,
        failed: 0,
        rows: [],
      }
      groups.push(group)
    }

    group.rows.push(row)
    group.cost += Number(row.cost || 0)
    if (row.status !== 1) {
      group.failed++
    }
  }

  return groups
})

async function loadSessionPage(reset: boolean): Promise<void> {
  const id = sessionId.value
  if (!id) {
    return
  }

  const seq = ++sessionSeq
  sessionLoading.value = true
  sessionError.value = false

  if (reset) {
    sessionRows.value = []
    sessionTotal.value = 0
    sessionCursor.value = 0
    sessionHasMore.value = false
  }

  try {
    const result = await logSession({
      session_id: id,
      after_id: reset ? 0 : sessionCursor.value,
      limit: 200,
    })
    if (seq !== sessionSeq) {
      return
    }

    sessionTotal.value = result.total
    sessionCursor.value = result.next_cursor
    sessionHasMore.value = result.has_more
    sessionRows.value = reset ? result.list : [...sessionRows.value, ...result.list]
  } catch {
    if (seq !== sessionSeq) {
      return
    }
    sessionError.value = true
  } finally {
    if (seq === sessionSeq) {
      sessionLoading.value = false
    }
  }
}

async function openSession(id: string): Promise<void> {
  if (!id) {
    return
  }
  if (!canSession.value) {
    ElMessage.warning(t('log.chainNoAuth'))
    return
  }

  detailVisible.value = false
  actionVisible.value = false
  // 先记 id 再打开：避免上一次会话的旧行在新标题下闪一下
  sessionId.value = id
  sessionVisible.value = true
  await loadSessionPage(true)
}

function reloadSession(): void {
  void loadSessionPage(true)
}

function onSessionClosed(): void {
  sessionSeq++
  sessionLoading.value = false
  sessionError.value = false
  sessionRows.value = []
  sessionTotal.value = 0
  sessionCursor.value = 0
  sessionHasMore.value = false
  sessionId.value = ''
}

/** 复制文本：http 内网等非安全上下文没有 navigator.clipboard，用临时 textarea 兜底 */
async function copyText(text: string, okMessage: string, failMessage: string): Promise<void> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else if (!fallbackCopy(text)) {
      throw new Error('clipboard unavailable')
    }
    ElMessage.success(okMessage)
  } catch {
    ElMessage.warning(failMessage)
  }
}

function fallbackCopy(text: string): boolean {
  const area = document.createElement('textarea')
  area.value = text
  area.style.position = 'fixed'
  area.style.opacity = '0'
  document.body.appendChild(area)
  area.select()
  const copied = document.execCommand('copy')
  area.remove()
  return copied
}

onMounted(() => {
  // 带 action_id / session_id 进来时直接展开对应视图，省去再点一次
  if (initialActionId) {
    void openAction(initialActionId)
  } else if (initialSessionId) {
    void openSession(initialSessionId)
  }
  window.addEventListener('resize', onResize)
})

// keep-alive 下页面已存在时再次跳进来不会重新挂载：跟上查询参数
watch(
  () => route.query.trace_id,
  (value) => {
    if (typeof value !== 'string' || !value) {
      return
    }
    query.trace_id = value
    search()
  },
)

watch(
  () => route.query.action_id,
  (value) => {
    if (typeof value !== 'string' || !value) {
      return
    }
    query.action_id = value
    search()
    void openAction(value)
  },
)

watch(
  () => route.query.session_id,
  (value) => {
    if (typeof value !== 'string' || !value) {
      return
    }
    query.session_id = value
    search()
    void openSession(value)
  },
)

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  // 组件销毁时手动释放：echarts 实例不挂在 DOM 上，不 dispose 会随路由切换堆积
  trendChart?.dispose()
  trendChart = null
})

async function onExport(): Promise<void> {
  const result = await logExport({ ...query })
  // 一律异步：提示排队后打开全局「导出任务」面板看进度
  ElMessage.success(t('log.exportQueued', { total: result.total }))
  exportTaskStore.open()
}

/* ---- 详情 ---- */
const detailVisible = ref(false)
const detailLoading = ref(false)
const current = ref<LogRow | null>(null)

/**
 * 打开详情：列表行已裁掉 params / result / ua（控制列表响应体），
 * 先用行数据把抽屉撑起来，再按 id 补拉全文；数据范围由后端兜底。
 */
async function openDetail(row: LogRow): Promise<void> {
  current.value = row
  detailVisible.value = true
  detailLoading.value = true
  try {
    const full = await logDetail(row.id)
    // 连点不同行时，迟到的响应不能覆盖当前行
    if (current.value?.id === row.id) {
      current.value = full
    }
  } catch {
    ElMessage.error(t('log.detailFailed'))
  } finally {
    detailLoading.value = false
  }
}

/** 参数/结果都按 JSON 存，能解析就格式化，否则原样显示（超长会被截断，JSON 不完整） */
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

function statusTag(code?: number): 'success' | 'warning' | 'danger' {
  if (!code) {
    return 'danger'
  }
  if (code < 300) {
    return 'success'
  }
  return code < 400 ? 'warning' : 'danger'
}

/** 状态码文字色（与标签同色系）：合并列里再套一个标签会显得拥挤 */
function statusTone(code?: number): string {
  const tone = statusTag(code)
  return tone === 'success' ? 'is-ok' : tone === 'warning' ? 'is-warn' : 'is-danger'
}

/* ---- 删除 ---- */
async function onBatchDelete(): Promise<void> {
  if (selection.value.length === 0) {
    return
  }
  try {
    await ElMessageBox.confirm(
      t('log.deleteSelectedConfirm', { count: selection.value.length }),
      t('log.batchDeleteTitle'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  await logDelete(selection.value.map((row) => row.id))
  ElMessage.success(t('log.deleteSuccess'))
  load()
}

async function onDeleteOne(id: number): Promise<void> {
  try {
    await ElMessageBox.confirm(t('log.deleteOneConfirm'), t('common.delete'), { type: 'warning' })
  } catch {
    return
  }
  await logDelete([id])
  ElMessage.success(t('log.deleteSuccess'))
  load()
}
</script>

<style scoped>
/* ---- 标签页 ---- */
.log-tabs {
  flex: none;
  margin-bottom: 12px;
}

/* ---- 登录分析 ---- */
.login-analysis {
  flex: 1;
  min-height: 0;
  padding-right: 2px;
  overflow: auto;
}

.analysis-bar {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.analysis-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.analysis-card {
  padding: 14px 16px;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
}

.analysis-card-value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--art-main);
}

.analysis-card-value.is-ok {
  color: var(--art-success);
}

.analysis-card-value.is-danger {
  color: var(--art-danger);
}

.analysis-card-label {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.analysis-panel {
  padding: 14px 16px;
  margin-bottom: 12px;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
}

.analysis-panel-title {
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
}

.analysis-chart {
  height: 260px;
}

.analysis-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 12px;
}

.analysis-hint {
  padding-bottom: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.log-title {
  color: var(--art-main);
}

/* 合并列里的次要行（IP / 耗时 / 会话ID） */
.log-sub {
  margin-top: 2px;
  font-size: 12px;
  color: var(--art-muted);
}

/* 可点击的标签（请求方法 / 结果状态）：交互与样式对齐 /oceanengine/account 的账户标签 */
.log-click-tag {
  cursor: pointer;
  user-select: none;
}

/* 请求方法标签：选中态用主题色高亮，一眼看出列表被谁过滤，再点一次取消 */
.log-method-tag.is-active {
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

/*
 * 结果标签：选中态保持成功/失败的红绿语义，只加深（加粗 + 同色浅底），
 * 不套主题蓝 —— 否则「成功」和「失败」选中后长得一模一样。
 */
.log-status-tag.is-active {
  font-weight: 600;
  border-width: 1.5px;
}

.log-status-tag.is-success.is-active {
  background: var(--el-color-success-light-9);
}

.log-status-tag.is-danger.is-active {
  background: var(--el-color-danger-light-9);
}

/* 请求列：方法标签独占一行，路径另起一行、超宽省略 */
.log-path {
  display: block;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 结果列：状态标签一行、状态码一行 */
.log-code {
  display: block;
  margin-top: 2px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
}

.log-code.is-ok {
  color: var(--art-success);
}

.log-code.is-warn {
  color: var(--el-color-warning);
}

.log-code.is-danger {
  color: var(--art-danger);
}

.detail-body {
  min-height: 200px;
}

.log-node {
  margin-top: 1px;
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  color: var(--art-muted);
}

.log-msg {
  margin-top: 1px;
  font-size: 12px;
}

/* 说明跟随记录结果：成功绿、失败红（「登录成功」不应标红） */
.log-msg.is-ok {
  color: var(--art-success);
}

.log-msg.is-danger {
  color: var(--art-danger);
}

.log-trace {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  user-select: all;
}

/* 追踪列：行首字段标注（操作ID / 会话ID）+ 可换行的 ID */
.log-trace-line {
  display: flex;
  gap: 4px;
  align-items: baseline;
  min-width: 0;
}

.log-trace-tag {
  flex-shrink: 0;
  font-size: 11px;
  line-height: 1.4;
  color: var(--art-muted);
}

.log-trace-id {
  min-width: 0;
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  line-height: 1.4;
  text-align: left;
  word-break: break-all;
}

.log-trace-btn {
  margin-left: 8px;
}

/* ---- 链路视图 ---- */
.trace-body {
  min-height: 240px;
}

.trace-toolbar {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 12px;
}

.trace-alert {
  margin-top: 12px;
}

.trace-empty-hint {
  max-width: 460px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
  text-align: center;
}

.trace-hint {
  padding: 12px 0 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.trace-jump {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
  padding: 8px 10px;
  margin-top: 12px;
  font-size: 12px;
  color: var(--art-muted);
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 2px);
}

.trace-timeline {
  padding-left: 4px;
  margin-top: 14px;
}

/* ---- 会话轨迹 ---- */
.session-group {
  margin-top: 18px;
}

.session-group + .session-group {
  padding-top: 14px;
  border-top: 1px dashed var(--art-card-border);
}

.session-head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}

.session-time,
.session-unknown {
  font-size: 12px;
  color: var(--art-muted);
}

.session-cost {
  margin-left: auto;
  font-size: 12px;
  color: var(--art-muted);
}

.session-more {
  display: flex;
  justify-content: center;
  padding: 16px 0 4px;
}

.log-block {
  margin-top: 18px;
}

.log-block-title {
  padding-left: 8px;
  margin-bottom: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-left: 3px solid var(--el-color-primary);
}

.log-pre {
  max-height: 260px;
  padding: 12px;
  overflow: auto;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 2px);
}
</style>
