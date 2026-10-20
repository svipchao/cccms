<template>
  <el-tabs
    ref="tabsRef"
    v-bind="$attrs"
    :model-value="activeName"
    :stretch="stretch"
    class="art-tabs"
    :class="[`art-tabs--${variant}`, { 'is-card': isCard, 'is-bar-only': barOnly }]"
    @update:model-value="onUpdate"
  >
    <!-- 插槽全量透传：默认插槽（el-tab-pane）与 label 等具名插槽原样交给 el-tabs -->
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps ?? {}" />
    </template>
  </el-tabs>
</template>

<script setup lang="ts" generic="T extends string | number">
import { computed, ref, useAttrs } from 'vue'
import { ElTabs } from 'element-plus'

defineOptions({ inheritAttrs: false })

/**
 * el-tabs 的封装：等分宽度 + 统一视觉 + 插槽全透传。
 *
 * 用法与 el-tabs 完全一致（v-model / type / tab-position / @tab-change 等照样传）：
 *
 *   <ArtTabs v-model="tab">
 *     <el-tab-pane :label="t('xxx.tabA')" name="a">...</el-tab-pane>
 *     <el-tab-pane name="b">
 *       <template #label><i class="ri-user-line" /> 自定义标签</template>
 *       ...
 *     </el-tab-pane>
 *   </ArtTabs>
 *
 * - `stretch`（默认 true）：标签等分整行宽度。EP 原名就是 stretch，顺手透传；
 *   标签特别多、需要横向滚动时传 `:stretch="false"` 恢复自适应宽度。
 * - `bar-only`：只渲染切换条、不渲染内容区。用于「账户 / 项目 / 单元」这类由外部
 *   内容跟随 v-model 变化的层级切换（此时 el-tab-pane 只用来提供标签）。
 * - `variant`：
 *     soft（默认）—— 中性底槽 + 选中项主色浅底，适合页面级主切换；
 *     solid        —— 中性底槽 + 选中项主色实底反白，强调更强，适合状态类切换。
 *
 * 底槽背景与圆角都吃 token：主色改（系统设置 → 主题色）底槽跟着变，
 * 圆角改（系统设置 → 圆角）容器与选中块一起变，不在组件里写死。
 */
withDefaults(
  defineProps<{
    /** 标签等分宽度（等价于 el-tabs 的 stretch，默认开启） */
    stretch?: boolean
    /** 视觉风格：白底浮起 / 主色实底 */
    variant?: 'soft' | 'solid'
    /** 只渲染切换条（不渲染内容区），内容由外部跟随 v-model 自行渲染 */
    barOnly?: boolean
  }>(),
  { stretch: true, variant: 'soft', barOnly: false },
)

/** 与 el-tabs 一致，支持 v-model（不传时由 el-tabs 自己选中第一个页签） */
const activeName = defineModel<T>()

const attrs = useAttrs()

/** card / border-card 有自己的边框样式，自定义底槽要跳过 */
const isCard = computed(() => attrs.type === 'card' || attrs.type === 'border-card')

const tabsRef = ref<InstanceType<typeof ElTabs>>()

/** el-tabs 的 update:modelValue 只会给 string | number，这里按调用方声明的 T 收窄 */
function onUpdate(value: string | number) {
  activeName.value = value as T
}

defineExpose({ tabsRef })
</script>

<style scoped>
.art-tabs {
  /* 底槽保持中性（跟随卡片底色），主调落在「选中块」上 */
  --art-tabs-bg: var(--art-card-bg);
  --art-tabs-border: var(--art-card-border);
  --art-tabs-bg-hover: var(--art-hover-bg);
  /* 选中块吃主色浅色阶：主题色运行时覆盖 --el-color-primary-light-*，这里自动跟随 */
  --art-tabs-item-bg: var(--el-color-primary-light-9);

  /* 圆角一律派生自系统圆角（applyRadius 会实时改 --art-radius） */
  --art-tabs-radius: var(--art-radius);
  --art-tabs-item-radius: max(0px, calc(var(--art-radius) - 2px));
}

/* 暗色下 light-9 与卡片底太接近，选中块提一档，保证还能看出「被选中」 */
:global(html.dark) .art-tabs {
  --art-tabs-item-bg: var(--el-color-primary-light-8);
}

/* ---- 底槽：中性描边导轨，替代 EP 的整条灰线 ---- */
.art-tabs:not(.is-card) :deep(.el-tabs__nav-wrap) {
  box-sizing: border-box;
  margin-bottom: 0;
  padding: 4px;
  background: var(--art-tabs-bg);
  border: 1px solid var(--art-tabs-border);
  border-radius: var(--art-tabs-radius);
}

.art-tabs:not(.is-card) :deep(.el-tabs__nav-wrap::after) {
  display: none;
}

.art-tabs:not(.is-card) :deep(.el-tabs__header.is-bottom) {
  margin-top: 14px;
  margin-bottom: 0;
}

/* ---- 单个页签：文字层级 + 悬停浅主色 + 系统圆角 ---- */
.art-tabs:not(.is-card) :deep(.el-tabs__item) {
  height: 34px;
  gap: 6px;
  padding: 0 12px;
  font-size: 14px;
  font-weight: 500;
  color: var(--art-sub);
  border-radius: var(--art-tabs-item-radius);
  transition:
    color 0.2s,
    background-color 0.2s;
}

.art-tabs:not(.is-card) :deep(.el-tabs__item.is-active) {
  font-weight: 600;
  color: var(--el-color-primary);
}

.art-tabs:not(.is-card) :deep(.el-tabs__item:not(.is-active):hover) {
  color: var(--el-color-primary);
  background: var(--art-tabs-bg-hover);
}

/* 选中态由底槽里的「块」表达，EP 的整格滑动条不再需要 */
.art-tabs:not(.is-card) :deep(.el-tabs__active-bar) {
  display: none;
}

/* 纯切换条模式：内容区整块不渲染（页面自己在外层放内容） */
.art-tabs.is-bar-only :deep(.el-tabs__content) {
  display: none;
}

/* ---- soft：选中项填充主色浅色阶，靠底色区分而不是靠白底 ---- */
.art-tabs--soft:not(.is-card) :deep(.el-tabs__item.is-active) {
  background: var(--art-tabs-item-bg);
  box-shadow: inset 0 0 0 1px var(--el-color-primary-light-8);
}

/* ---- solid：选中项主色实底反白 ---- */
.art-tabs--solid:not(.is-card) :deep(.el-tabs__item.is-active) {
  color: #fff;
  background: var(--el-color-primary);
}

.art-tabs--solid:not(.is-card) :deep(.el-tabs__item.is-active:hover) {
  color: #fff;
}
</style>
