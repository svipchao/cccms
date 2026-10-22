<template>
  <div class="art-scroll">
    <div class="art-page">
      <el-alert
        v-if="status && !status.enabled"
        type="warning"
        :closable="false"
        show-icon
        :title="t('upgrade.blockedTitle')"
        :description="status.reason || t('upgrade.blockedDesc', { reason: status.reason })"
      />

      <template v-else-if="status">
        <el-card shadow="never" class="block">
          <template #header>
            <div class="head">
              <span>{{ t('upgrade.current') }}</span>
              <div class="actions">
                <el-select
                  v-if="status.remotes.length"
                  v-model="remote"
                  class="remote"
                  :placeholder="t('upgrade.sourceLabel')"
                  :disabled="checking || running"
                  @change="onRemoteChange"
                >
                  <el-option
                    v-for="item in status.remotes"
                    :key="item.name"
                    :label="item.name"
                    :value="item.name"
                  >
                    <span class="remote-name">{{ item.name }}</span>
                    <span class="remote-url">{{ item.url }}</span>
                  </el-option>
                </el-select>
                <el-button :loading="checking" @click="onCheck">{{ t('upgrade.check') }}</el-button>
                <el-button type="primary" :loading="running" :disabled="!status.can_run" @click="onRun">
                  {{ t('upgrade.run') }}
                </el-button>
              </div>
            </div>
          </template>

          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="Version">{{ status.version }}</el-descriptions-item>
            <el-descriptions-item label="Branch">{{ status.branch }}</el-descriptions-item>
            <el-descriptions-item label="Commit">
              <span class="mono">{{ status.commit || '—' }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="Commit time">{{ status.commit_time || '—' }}</el-descriptions-item>
            <el-descriptions-item label="Latest tag">{{ status.latest_tag || '—' }}</el-descriptions-item>
            <el-descriptions-item label="Ahead / Behind">
              {{ status.ahead }} / {{ status.behind }}
            </el-descriptions-item>
          </el-descriptions>

          <el-alert
            v-if="status.dirty"
            type="warning"
            :closable="false"
            show-icon
            class="tip"
            :title="status.reason || 'Working tree has uncommitted changes'"
          />
        </el-card>

        <el-card v-if="check" shadow="never" class="block">
          <template #header>
            <span>
              {{ t('upgrade.target') }} · {{ check.upstream || status.branch }}
              <el-tag v-if="check.behind === 0" type="success" effect="light" size="small" class="tag">
                {{ t('upgrade.upToDate') }}
              </el-tag>
              <el-tag v-else type="warning" effect="light" size="small" class="tag">
                {{ t('upgrade.commitCount', { count: check.behind }) }}
              </el-tag>
            </span>
          </template>
          <div class="meta">
            <span>Behind: {{ check.behind }}</span>
            <span>Ahead: {{ check.ahead }}</span>
            <span>Tag: {{ check.latest_tag || '—' }}</span>
          </div>
          <el-empty v-if="!check.commits.length" :description="t('upgrade.noCommits')" />
          <ul v-else class="commits">
            <li v-for="line in check.commits" :key="line" class="mono">{{ line }}</li>
          </ul>
        </el-card>
      </template>

      <el-skeleton v-else :rows="6" animated />
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:upgrade' })

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { upgradeCheck, upgradeRun, upgradeStatus, type UpgradeCheck, type UpgradeStatus } from '@/api/upgrade'

const { t } = useI18n({ useScope: 'global' })

const status = ref<UpgradeStatus | null>(null)
const check = ref<UpgradeCheck | null>(null)
const remote = ref('')
const checking = ref(false)
const running = ref(false)

function onRemoteChange(): void {
  // 换了来源，上一次的比对结论就不作数了。
  check.value = null
}

async function load(): Promise<void> {
  status.value = await upgradeStatus()
  const list = status.value.remotes ?? []
  if (!list.some((item) => item.name === remote.value)) {
    remote.value = (list.find((item) => item.is_default) ?? list[0])?.name ?? ''
  }
}

async function onCheck(): Promise<void> {
  checking.value = true
  try {
    check.value = await upgradeCheck(remote.value || undefined)
    await load()
  } finally {
    checking.value = false
  }
}

async function onRun(): Promise<void> {
  if (!status.value) {
    return
  }
  try {
    await ElMessageBox.confirm(
      t('upgrade.runConfirmRef', {
        ref: status.value.branch,
        commit: status.value.upstream || status.value.branch,
      }),
      t('upgrade.runTitle'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  running.value = true
  try {
    const result = await upgradeRun(remote.value || undefined)
    if (result.output) {
      ElMessage.success(result.output.slice(0, 200))
    }
    if (result.restart_required) {
      ElMessage.warning(t('upgrade.needReload'))
    }
    check.value = null
    await load()
  } finally {
    running.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.remote {
  width: 220px;
}

.remote-name {
  margin-right: 12px;
}

.remote-url {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.meta {
  display: flex;
  gap: 24px;
  margin-bottom: 12px;
  color: var(--el-text-color-secondary);
}

.tag {
  margin-left: 8px;
}

.tip {
  margin-top: 12px;
}

.commits {
  margin: 0;
  padding-left: 18px;
  line-height: 1.9;
}

.mono {
  font-family: var(--el-font-family-mono, monospace);
}
</style>
