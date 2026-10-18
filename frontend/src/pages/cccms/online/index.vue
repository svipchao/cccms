<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      row-key="jti"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
    >
      <template #toolbar>
        <el-alert type="info" :closable="false" show-icon :title="t('online.alert')" />
      </template>

      <template #username="{ row }">
        <div class="online-user">{{ row.username || '—' }}</div>
        <div class="online-nick">{{ row.nickname || '—' }}</div>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:online:kick'" link type="danger" @click="onKick(row)">
          {{ t('online.kick') }}
        </el-button>
        <el-button v-auth="'cccms:online:kick_user'" link type="warning" @click="onKickUser(row)">
          {{ t('online.kickUser') }}
        </el-button>
      </template>
    </ArtTable>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:online' })

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { onlineKick, onlineKickUser, onlineList, type OnlineSession } from '@/api/online'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

interface Query {
  username: string
  ip: string
  /** 登录时间范围 */
  login_start: string
  login_end: string
  /** 最后活跃时间范围 */
  active_start: string
  active_end: string
  /** 过期时间范围 */
  expire_start: string
  expire_end: string
}

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  {
    prop: 'username',
    label: t('online.user'),
    slot: 'username',
    filter: { type: 'text' },
  },
  { prop: 'device', label: t('online.device'), width: 90 },
  { prop: 'os', label: t('online.os'), width: 110 },
  { prop: 'browser', label: t('online.browser'), width: 100 },
  { prop: 'ip', label: 'IP', width: 140, filter: { type: 'text' } },
  // 三列时间各用一组 startKey / endKey，互不覆盖
  {
    prop: 'login_at',
    label: t('online.loginAt'),
    width: 170,
    filter: { type: 'date', startKey: 'login_start', endKey: 'login_end' },
    sortable: 'custom',
  },
  {
    prop: 'last_at',
    label: t('online.lastActive'),
    width: 170,
    filter: { type: 'date', startKey: 'active_start', endKey: 'active_end' },
    sortable: 'custom',
  },
  {
    prop: 'expire_at',
    label: t('online.expireAt'),
    width: 170,
    filter: { type: 'date', startKey: 'expire_start', endKey: 'expire_end' },
  },
  { prop: 'ua', label: 'User-Agent', minWidth: 240 },
  { prop: 'action', label: t('table.action'), width: 210, fixed: 'right', slot: 'action', lockVisible: true },
])

const { list, loading, total, page, limit, load, onPageChange, onLimitChange } = useTable<OnlineSession, Query>({
  api: onlineList,
  initialQuery: {
    username: '',
    ip: '',
    login_start: '',
    login_end: '',
    active_start: '',
    active_end: '',
    expire_start: '',
    expire_end: '',
  },
})

async function onKick(row: OnlineSession): Promise<void> {
  try {
    await ElMessageBox.confirm(t('online.kickConfirm', { name: row.username }), t('online.kick'), {
      type: 'warning',
    })
  } catch {
    return
  }
  await onlineKick(row.jti)
  ElMessage.success(t('online.kickSuccess'))
  load()
}

async function onKickUser(row: OnlineSession): Promise<void> {
  try {
    await ElMessageBox.confirm(t('online.kickUserConfirm', { name: row.username }), t('online.kickUserTitle'), {
      type: 'warning',
    })
  } catch {
    return
  }
  const res = await onlineKickUser(row.user_id)
  ElMessage.success(t('online.kickUserSuccess', { count: res.sessions }))
  load()
}
</script>

<style scoped>
.online-user {
  color: var(--art-main);
}

.online-nick {
  margin-top: 1px;
  font-size: 12px;
  color: var(--art-muted);
}
</style>
