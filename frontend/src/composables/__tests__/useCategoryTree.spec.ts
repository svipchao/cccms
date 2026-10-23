import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useCategoryTree } from '@/composables/useCategoryTree'
import { categoryDelete, categorySave, categoryTree, categoryUpdate, type CategoryNode } from '@/api/category'
import { ElMessage, ElMessageBox } from 'element-plus'

vi.mock('@/api/category', () => ({
  categoryTree: vi.fn(),
  categorySave: vi.fn(),
  categoryUpdate: vi.fn(),
  categoryDelete: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('element-plus', () => ({
  ElMessage: { success: vi.fn(), warning: vi.fn() },
  ElMessageBox: { confirm: vi.fn() },
}))

// 回收站开关只影响数据源，这里用最简实现替掉（它自己的行为由 useRecycle.spec 覆盖）
vi.mock('@/composables/useRecycle', () => ({
  useRecycle: () => ({
    recycle: ref(false),
    toggle: vi.fn(),
    onRestore: vi.fn(),
    onForceDelete: vi.fn(),
  }),
}))

const treeMock = vi.mocked(categoryTree)
const saveMock = vi.mocked(categorySave)
const updateMock = vi.mocked(categoryUpdate)
const deleteMock = vi.mocked(categoryDelete)

function node(id: number, name: string, children: CategoryNode[] = []): CategoryNode {
  return { id, name, module: 'dict', parent_id: 0, sort: 0, status: 1, children }
}

const NODES = [node(1, '文档', [node(2, '合同')]), node(3, '图片')]

describe('useCategoryTree', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    treeMock.mockResolvedValue(NODES as never)
  })

  it('加载分类：补上「全部 / 未分类」两个虚拟节点', async () => {
    const reload = vi.fn()
    const tree = useCategoryTree('dict', { prefix: 'cccms.dict', onListReload: reload })

    await tree.loadCategories()

    expect(tree.categories.value).toHaveLength(2)
    expect(tree.treeData.value.map((node) => node.id)).toEqual([0, -1, 1, 3])
  })

  it('当前选中的分类被删掉后回落到「全部」，避免列表卡在空分类', async () => {
    const tree = useCategoryTree('dict', { prefix: 'cccms.dict', onListReload: vi.fn() })
    await tree.loadCategories()

    tree.currentId.value = 3
    expect(tree.currentNodeName.value).toBe('图片')

    treeMock.mockResolvedValue([NODES[0]] as never)
    await tree.loadCategories()

    expect(tree.currentId.value).toBe(0)
    expect(tree.currentNodeName.value).toBe('cccms.dict.all')
  })

  it('点击节点：切换当前分类并重新加载列表', () => {
    const reload = vi.fn()
    const tree = useCategoryTree('file', { prefix: 'cccms.file', onListReload: reload })

    tree.onNodeClick({ id: 2 })
    expect(tree.currentId.value).toBe(2)
    expect(reload).toHaveBeenCalled()

    tree.clearNode()
    expect(tree.currentId.value).toBe(0)
    expect(reload).toHaveBeenCalledTimes(2)
  })

  it('新建分类走 save、编辑走 update，成功后刷新树与列表', async () => {
    const reload = vi.fn()
    const tree = useCategoryTree('dict', { prefix: 'cccms.dict', onListReload: reload })
    tree.categoryFormRef.value = { validate: () => Promise.resolve(true) } as never

    tree.openCategoryCreate(1)
    expect(tree.categoryForm.parent_id).toBe(1)
    tree.categoryForm.name = '新分类'
    await tree.submitCategory()

    expect(saveMock).toHaveBeenCalledWith('dict', expect.objectContaining({ name: '新分类', parent_id: 1 }))
    expect(ElMessage.success).toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()

    tree.openCategoryEdit(node(5, '旧分类'))
    await tree.submitCategory()

    expect(updateMock).toHaveBeenCalledWith('dict', expect.objectContaining({ id: 5 }))
  })

  it('表单校验失败时不发请求（避免半成品数据入库）', async () => {
    const tree = useCategoryTree('dict', { prefix: 'cccms.dict', onListReload: vi.fn() })
    tree.categoryFormRef.value = { validate: () => Promise.reject(new Error('invalid')) } as never

    await tree.submitCategory()

    expect(saveMock).not.toHaveBeenCalled()
    expect(updateMock).not.toHaveBeenCalled()
    expect(tree.saving.value).toBe(false)
  })

  it('删除分类：确认后调用接口并刷新；取消则不调', async () => {
    vi.mocked(ElMessageBox.confirm).mockResolvedValue('confirm' as never)
    const reload = vi.fn()
    const tree = useCategoryTree('file', { prefix: 'cccms.file', onListReload: reload })

    await tree.onCategoryDelete(node(9, '待删'))
    expect(deleteMock).toHaveBeenCalledWith('file', 9)
    expect(reload).toHaveBeenCalled()

    vi.mocked(ElMessageBox.confirm).mockRejectedValue(new Error('cancel'))
    await tree.onCategoryDelete(node(10, '不删'))
    expect(deleteMock).toHaveBeenCalledTimes(1)
  })
})
