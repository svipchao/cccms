import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  categoryDelete,
  categorySave,
  categoryTree,
  categoryUpdate,
  type CategoryModule,
  type CategoryNode,
} from '@/api/category'
import { useRecycle } from '@/composables/useRecycle'

/** 虚拟节点：全部 / 未分类（后端约定 category_id：0=全部，-1=未分类） */
const ALL_ID = 0
const NONE_ID = -1

export interface UseCategoryTreeOptions {
  /** i18n key 前缀 = 语言包命名空间：cccms.dict / cccms.file */
  prefix: 'cccms.dict' | 'cccms.file'
  /** 分类变化后重新加载列表（页面的 search，回到第 1 页） */
  onListReload: () => void
}

/**
 * 通用分类树（sys_category，按 module 隔离）。
 *
 * `cccms.dict`（字典分类）与 `cccms.file`（附件分类）两页的分类树几乎逐行相同，仅 module 与
 * i18n 前缀不同 —— 收敛到这里，后续改一处即可。
 */
export function useCategoryTree(module: CategoryModule, options: UseCategoryTreeOptions) {
  const { t } = useI18n({ useScope: 'global' })
  const { prefix, onListReload } = options

  const saving = ref(false)
  const currentId = ref(ALL_ID)
  const categories = ref<CategoryNode[]>([])

  function findCategory(nodes: CategoryNode[], id: number): CategoryNode | null {
    for (const node of nodes) {
      if (node.id === id) {
        return node
      }
      if (node.children?.length) {
        const hit = findCategory(node.children, id)
        if (hit) {
          return hit
        }
      }
    }
    return null
  }

  async function loadCategories(): Promise<void> {
    categories.value = await categoryTree(module, categoryRecycle.value)
    if (currentId.value > 0 && !findCategory(categories.value, currentId.value)) {
      currentId.value = ALL_ID
    }
  }

  // 分类回收站：列表闭包在 setup 阶段就引用 loadCategories，因此声明需在其之前
  const {
    recycle: categoryRecycle,
    toggle: toggleCategoryRecycle,
    onRestore: onCategoryRestore,
    onForceDelete: onCategoryForceDelete,
  } = useRecycle('category', { reload: () => loadCategories() })

  const treeData = computed(() =>
    // 回收站视图里只列已删分类，不掺「全部 / 未分类」这两个虚拟节点
    categoryRecycle.value
      ? categories.value
      : [
          { id: ALL_ID, name: t(`${prefix}.all`) },
          { id: NONE_ID, name: t(`${prefix}.uncategorized`) },
          ...categories.value,
        ],
  )

  const currentNodeName = computed(() => {
    if (currentId.value === ALL_ID) {
      return t(`${prefix}.all`)
    }
    if (currentId.value === NONE_ID) {
      return t(`${prefix}.uncategorized`)
    }
    return findCategory(categories.value, currentId.value)?.name ?? ''
  })

  /** 上级分类选择器：顶层补一个「顶级分类」 */
  const categoryOptions = computed(() => [{ id: 0, name: t(`${prefix}.topCategory`), children: categories.value }])
  /** 归属/移动目标选择器：顶层补一个「未分类」（dict 的 categorySelectOptions / file 的 moveOptions） */
  const selectorOptions = computed(() => [{ id: 0, name: t(`${prefix}.uncategorized`), children: categories.value }])

  function onNodeClick(data: Record<string, any>): void {
    currentId.value = Number(data.id ?? ALL_ID)
    onListReload()
  }

  function clearNode(): void {
    currentId.value = ALL_ID
    onListReload()
  }

  /* ---- 分类表单 ---- */
  const categoryFormRef = ref<FormInstance>()
  const categoryVisible = ref(false)
  const emptyCategoryForm = { id: 0, parent_id: 0, name: '', sort: 0, remark: '' }
  const categoryForm = reactive<Record<string, any>>({ ...emptyCategoryForm })
  const categoryRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t(`${prefix}.categoryNameRequired`), trigger: 'blur' }],
  }))

  function openCategoryCreate(parentId: number): void {
    Object.assign(categoryForm, emptyCategoryForm, { parent_id: parentId })
    categoryVisible.value = true
  }

  function openCategoryEdit(record: CategoryNode): void {
    Object.assign(categoryForm, emptyCategoryForm, record)
    categoryVisible.value = true
  }

  async function submitCategory(): Promise<void> {
    const valid = await categoryFormRef.value?.validate().catch(() => false)
    if (!valid) {
      return
    }
    saving.value = true
    try {
      if (categoryForm.id) {
        await categoryUpdate(module, { ...categoryForm })
      } else {
        await categorySave(module, { ...categoryForm })
      }
      ElMessage.success(t(`${prefix}.saveSuccess`))
      categoryVisible.value = false
      await Promise.all([loadCategories(), onListReload()])
    } finally {
      saving.value = false
    }
  }

  async function onCategoryDelete(record: CategoryNode): Promise<void> {
    try {
      await ElMessageBox.confirm(
        t(`${prefix}.categoryDeleteConfirm`, { name: record.name }),
        t(`${prefix}.deleteCategoryTitle`),
        { type: 'warning' },
      )
    } catch {
      return
    }
    await categoryDelete(module, record.id)
    ElMessage.success(t(`${prefix}.deleteSuccess`))
    await Promise.all([loadCategories(), onListReload()])
  }

  return {
    saving,
    currentId,
    categories,
    categoryRecycle,
    toggleCategoryRecycle,
    onCategoryRestore,
    onCategoryForceDelete,
    treeData,
    currentNodeName,
    categoryOptions,
    selectorOptions,
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
  }
}
