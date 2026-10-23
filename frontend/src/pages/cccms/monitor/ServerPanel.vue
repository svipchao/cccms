<template>
  <div v-loading="loading" class="srv">
    <!-- 越线只在后端判定（`warn`），这里只按标记上色，避免和 Prometheus 告警阈值漂移 -->
    <div class="srv__note">
      <span>{{ t('cccms.monitor.serverSubtitle') }}</span>
      <small v-if="data">{{ t('cccms.monitor.serverSampledAt', { time: data.time }) }}</small>
    </div>

    <div class="srv__gauges">
      <div v-for="item in gauges" :key="item.key" class="srv__gauge">
        <div class="srv__gauge-head">
          <span>{{ item.label }}</span>
          <small v-if="item.hint">{{ item.hint }}</small>
        </div>
        <b class="srv__value" :class="{ 'is-bad': item.warn }">{{ item.text }}</b>
        <el-progress
          :percentage="item.percentage"
          :status="item.warn ? 'exception' : undefined"
          :stroke-width="6"
          :show-text="false"
        />
        <small class="srv__foot">
          {{ t('cccms.monitor.threshold', { n: item.threshold }) }}
          <template v-if="item.foot"> · {{ item.foot }}</template>
        </small>
      </div>
    </div>

    <section class="srv__panel">
      <h3 class="srv__panel-head">{{ t('cccms.monitor.machine') }}</h3>
      <el-descriptions :column="2" size="small" border>
        <el-descriptions-item :label="t('cccms.monitor.osName')">{{ data?.os.name || dash }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.osRelease')">{{
          data?.os.release || dash
        }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.arch')">{{ data?.os.arch || dash }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.host')">{{ data?.os.host || dash }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.load')">{{ loadText }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.processes')">{{ number(data?.processes) }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.uptime')">{{ duration(data?.uptime) }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.php')">{{ phpText }}</el-descriptions-item>
        <el-descriptions-item :label="t('cccms.monitor.phpMemoryLabel')" :span="2">{{
          phpMemoryText
        }}</el-descriptions-item>
      </el-descriptions>
    </section>

    <div class="srv__panels">
      <section class="srv__panel">
        <h3 class="srv__panel-head">{{ t('cccms.monitor.redisInfo') }}</h3>
        <el-descriptions v-if="data?.redis" :column="2" size="small" border>
          <el-descriptions-item :label="t('cccms.monitor.usedMemory')">
            {{ bytes(data.redis.used_memory) }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.peakMemory')">
            {{ bytes(data.redis.used_memory_peak) }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.connectedClients')">
            {{ data.redis.connected_clients }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.blockedClients')">
            <span :class="{ 'is-bad': data.redis.blocked_clients > 0 }">{{ data.redis.blocked_clients }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.opsPerSecond')">{{
            data.redis.ops_per_second
          }}</el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.keyspace')">
            {{ data.redis.keyspace_hits }} / {{ data.redis.keyspace_misses }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.evictedKeys')">
            <span :class="{ 'is-bad': data.redis.evicted_keys > 0 }">{{ data.redis.evicted_keys }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.rejectedConnections')">
            <span :class="{ 'is-bad': data.redis.rejected_connections > 0 }">{{
              data.redis.rejected_connections
            }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.duration')" :span="2">
            {{ duration(data.redis.uptime_seconds) }}
          </el-descriptions-item>
        </el-descriptions>
        <el-empty v-else :description="t('cccms.monitor.noRedis')" :image-size="60" />
      </section>

      <section class="srv__panel">
        <h3 class="srv__panel-head">{{ t('cccms.monitor.mysqlInfo') }}</h3>
        <el-descriptions v-if="data?.mysql" :column="2" size="small" border>
          <el-descriptions-item :label="t('cccms.monitor.version')">{{
            data.mysql.version || dash
          }}</el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.maxConnections')">{{
            data.mysql.max_connections
          }}</el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.threads')">
            {{ data.mysql.threads_connected }} / {{ data.mysql.threads_running }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.connectionUsage')">
            <span :class="{ 'is-bad': data.warn.connections }">{{ data.mysql.connection_usage }}%</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.questions')">{{ data.mysql.questions }}</el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.slowQueries')">
            <span :class="{ 'is-bad': data.mysql.slow_queries > 0 }">{{ data.mysql.slow_queries }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.abortedConnects')">
            <span :class="{ 'is-bad': data.mysql.aborted_connects > 0 }">{{ data.mysql.aborted_connects }}</span>
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.bufferPool')">
            {{ bytes(data.mysql.buffer_pool_bytes) }}
          </el-descriptions-item>
          <el-descriptions-item :label="t('cccms.monitor.duration')" :span="2">
            {{ duration(data.mysql.uptime_seconds) }}
          </el-descriptions-item>
        </el-descriptions>
        <el-empty v-else :description="t('cccms.monitor.noMysql')" :image-size="60" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:monitor-server' })

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { monitorServer, type MonitorServer } from '@/api/monitor'

const { t } = useI18n({ useScope: 'global' })

const data = ref<MonitorServer | null>(null)
const loading = ref(false)

const dash = computed(() => t('cccms.monitor.dash'))

/** 后端给的是 0~100 的百分比；进度条只接受这个区间 */
function clamp(value: number | null | undefined): number {
  return Math.min(100, Math.max(0, value ?? 0))
}

function percentText(value: number | null | undefined): string {
  return typeof value === 'number' ? `${value}%` : t('cccms.monitor.dash')
}

function number(value: number | null | undefined): string {
  return typeof value === 'number' ? String(value) : t('cccms.monitor.dash')
}

/** 字节：保留一位小数就够看，大于 100 时不再带小数（避免「512.3 GB」这种噪声） */
function bytes(value: number | null | undefined): string {
  if (typeof value !== 'number' || value <= 0) {
    return '0 B'
  }
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)))
  const size = value / 1024 ** index

  return `${index === 0 || size >= 100 ? Math.round(size) : size.toFixed(1)} ${units[index]}`
}

/** 秒 → 人可读时长；未知（非 Linux）显示「—」而不是 0 */
function duration(seconds: number | null | undefined): string {
  if (typeof seconds !== 'number' || seconds <= 0) {
    return t('cccms.monitor.dash')
  }
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (days > 0) {
    return `${days}d ${hours}h`
  }
  if (hours > 0) {
    return `${hours}h ${minutes}m`
  }

  return `${minutes}m`
}

const gauges = computed(() => {
  const d = data.value
  const mysqlUsage = d?.mysql?.connection_usage ?? null

  return [
    {
      key: 'cpu',
      label: t('cccms.monitor.cpu'),
      hint: d?.cpu.cores ? t('cccms.monitor.cpuCores', { n: d.cpu.cores }) : '',
      text: percentText(d?.cpu.percent ?? null),
      percentage: clamp(d?.cpu.percent),
      warn: d?.warn.cpu ?? false,
      threshold: d?.thresholds.cpu ?? 0,
      foot: d?.cpu.load ? `${t('cccms.monitor.load')} ${d.cpu.load.join(' / ')}` : '',
    },
    {
      key: 'memory',
      label: t('cccms.monitor.memory'),
      hint: '',
      text: percentText(d?.memory.percent ?? null),
      percentage: clamp(d?.memory.percent),
      warn: d?.warn.memory ?? false,
      threshold: d?.thresholds.memory ?? 0,
      foot:
        d?.memory.total && d.memory.available !== null
          ? t('cccms.monitor.memoryUsage', { available: bytes(d.memory.available), total: bytes(d.memory.total) })
          : '',
    },
    {
      key: 'disk',
      label: t('cccms.monitor.disk'),
      hint: '',
      text: percentText(d?.disk.used_percent ?? null),
      percentage: clamp(d?.disk.used_percent),
      warn: d?.warn.disk ?? false,
      threshold: d?.thresholds.disk ?? 0,
      foot:
        d?.disk.total && d.disk.free !== null
          ? t('cccms.monitor.diskUsage', { free: bytes(d.disk.free), total: bytes(d.disk.total) })
          : '',
    },
    {
      key: 'connections',
      label: t('cccms.monitor.connectionUsage'),
      hint: d?.mysql ? `${d.mysql.threads_connected} / ${d.mysql.max_connections}` : '',
      text: percentText(mysqlUsage),
      percentage: clamp(mysqlUsage),
      warn: d?.warn.connections ?? false,
      threshold: d?.thresholds.connections ?? 0,
      foot: '',
    },
  ]
})

const loadText = computed(() => {
  const load = data.value?.cpu.load

  return load ? load.map((item) => item.toFixed(2)).join(' / ') : t('cccms.monitor.dash')
})

const phpText = computed(() => {
  const php = data.value?.php
  if (!php) {
    return t('cccms.monitor.dash')
  }

  return `${php.version} (${php.sapi}) · ${t('cccms.monitor.phpWorkers', { n: php.workers })}`
})

const phpMemoryText = computed(() => {
  const php = data.value?.php
  if (!php) {
    return t('cccms.monitor.dash')
  }

  // memory_limit = -1 时 parseBytes 返回 0，表示不限制
  return t('cccms.monitor.phpMemory', {
    used: bytes(php.memory_used),
    limit: php.memory_limit > 0 ? bytes(php.memory_limit) : '∞',
  })
})

async function refresh(): Promise<void> {
  loading.value = true
  try {
    data.value = await monitorServer()
  } catch {
    ElMessage.error(t('cccms.monitor.serverLoadFailed'))
  } finally {
    loading.value = false
  }
}

// 进入面板才挂载（`v-if`），首屏不会多一次 200ms 的 CPU 采样
onMounted(() => void refresh())

defineExpose({ refresh })
</script>

<style scoped>
.srv {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.srv__note {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  align-items: baseline;
  font-size: 12px;
  color: var(--art-secondary);
}

.srv__note small {
  color: var(--art-secondary);
}

.srv__gauges {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.srv__gauge {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

.srv__gauge-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  font-size: 12px;
  color: var(--art-secondary);
}

.srv__value {
  font-size: 20px;
  font-weight: 600;
  color: var(--art-main);
}

.srv .is-bad {
  color: #f56c6c;
}

.srv__foot {
  font-size: 11px;
  color: var(--art-secondary);
}

.srv__panels {
  display: flex;
  gap: 12px;
}

.srv__panel {
  flex: 1;
  min-width: 0;
  padding: 14px 16px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

/*
 * 「主机 / 运行时」是 .srv（纵向 flex）的直接子项，却和下面并排的两块共用 .srv__panel。
 * 不覆盖的话 flex: 1 会沿着 main axis 把它纵向拉满剩余高度 —— 描述列表还在顶上，
 * 卡片背景却被撑高，看着就是「卡片下方一大片留白」。这里只让它贴合内容。
 */
.srv > .srv__panel {
  flex: none;
}

.srv__panel-head {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
}

@media (max-width: 900px) {
  .srv__panels {
    flex-direction: column;
  }
}
</style>
