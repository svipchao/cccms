<template>
  <el-tooltip :content="active ? t('core.table.exitRecycle') : t('core.table.enterRecycle', { label })" placement="top">
    <el-button
      v-auth="['cccms:recycle:restore', 'cccms:recycle:delete']"
      text
      circle
      class="recycle-toggle"
      :class="{ 'is-active': active }"
      @click="emit('toggle')"
      ><i class="ri-delete-bin-line"
    /></el-button>
  </el-tooltip>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
/**
 * 模块页表格右上角的「回收站」开关。
 *
 * 点击后当前表格改为查已删数据（不是打开新页面/弹窗），激活时图标高亮。
 * 可见性挂在两个回收站权限上：没有任何一个，进来也没东西可点。
 *
 * `label` 是模块名词（如「部门」），只作为 tooltip 模板 `{label}回收站` 的参数传入 ——
 * 整句由语言包拼装，中英文语序不同也能各自成句。
 */
withDefaults(defineProps<{ active: boolean; label?: string }>(), { label: '' })

const emit = defineEmits<{ toggle: [] }>()

const { t } = useI18n({ useScope: 'global' })
</script>

<style scoped>
.recycle-toggle.is-active {
  color: var(--art-primary);
  background: var(--art-primary-light, var(--el-color-primary-light-9));
}
</style>
