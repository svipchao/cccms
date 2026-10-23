import { effectScope, watchEffect, type App, type DirectiveBinding, type EffectScope } from 'vue'
import { useUserStore } from '@/stores/user'

/** 每个元素一个 effect 作用域：绑定值或权限变化时重算，卸载时销毁 */
const scopes = new WeakMap<HTMLElement, EffectScope>()

/**
 * 按钮级权限指令：`v-auth="'cccms:user:save'"`，也支持数组（命中任一即通过）。
 *
 * 用 `display: none` 而不是移除 DOM：元素仍在，权限变化后可恢复，
 * 也避免 Vue 在 patch 时找不到自己创建过的节点。
 *
 * 只能挂在「根节点是单个元素」的组件上（如 el-button / el-dropdown / el-upload）。
 * 多根节点组件（如 el-dropdown-item，内部是 ElRovingFocusItem）挂不上，
 * Vue 会告警且隐藏失效，这类场景请改用 `v-if="hasAuth('...')"`。
 */
function authDirective(el: HTMLElement, binding: DirectiveBinding<string | string[] | undefined>): void {
  // 绑定值可能被改写，重建前先销毁上一次的 effect，避免多个 watchEffect 争抢同一个元素
  scopes.get(el)?.stop()

  const user = useUserStore()
  const scope = effectScope()
  scopes.set(el, scope)

  // 用 watchEffect 而不是一次性求值：权限集合变化（后台改权限后刷新 profile / 切换账号）
  // 时组件不一定重新渲染，只靠 mounted + updated 会让按钮停留在旧状态
  scope.run(() => {
    watchEffect(() => {
      const pass = !binding.value || user.hasAuth(binding.value)
      el.style.display = pass ? '' : 'none'
    })
  })
}

export function setupDirectives(app: App): void {
  app.directive('auth', {
    mounted: authDirective,
    updated: authDirective,
    unmounted(el: HTMLElement) {
      scopes.get(el)?.stop()
      scopes.delete(el)
    },
  })
}
