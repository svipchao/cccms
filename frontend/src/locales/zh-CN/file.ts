/**
 * 附件管理页。
 *
 * 与 `en-US/file.ts` 保持**完全一致的 key 集合**。
 * 文件名、分类名等均为用户录入数据，不在此维护。
 */
export default {
  /* ---- 左侧分类树 ---- */
  categoryTreeTitle: '附件分类',
  addTopCategory: '新增顶级分类',
  addSubCategory: '新增子分类',
  renameCategory: '重命名 / 调整',
  all: '全部',
  uncategorized: '未分类',
  topCategory: '顶级分类',

  /* ---- 查询 / 表格 ---- */
  nameLabel: '文件名',
  searchPlaceholder: '请输入',
  extLabel: '扩展名',
  extPlaceholder: '如 png',
  categoryColumnLabel: '所属分类',
  typeLabel: '类型',
  sizeLabel: '大小',
  uploadTimeLabel: '上传时间',
  preview: '预览',
  view: '查看',
  download: '下载',
  publicLabel: '公开',
  publicTip: '公开：直链任何人可访问；私有：直链失效，仅登录后可按权限下载',

  /* ---- 工具栏 ---- */
  recycleLabel: '附件',
  upload: '上传附件',
  move: '移动到分类',
  moveWithCount: '移动到分类（{count}）',
  onlyView: '仅看：{name}',
  toolbarTip: '单文件上限 10MB；相同内容自动去重',
  deleteConfirm: '确定删除该附件？',

  /* ---- 分类表单 ---- */
  createCategoryTitle: '新增分类',
  editCategoryTitle: '编辑分类',
  parentCategoryLabel: '上级分类',
  categoryNameLabel: '分类名称',
  categoryNamePlaceholder: '如 合同附件',
  sortLabel: '排序',
  remarkLabel: '备注',
  remarkPlaceholder: '请输入备注',
  categoryNameRequired: '请输入分类名称',
  deleteCategoryTitle: '删除分类',
  deleteCategoryConfirm: '确定删除分类「{name}」？',

  /* ---- 移动到分类 ---- */
  moveTitle: '移动到分类',
  targetCategoryLabel: '目标分类',

  /* ---- PDF 预览 ---- */
  pdfPreview: 'PDF 预览',
  openInNewWindow: '在新窗口打开',
  close: '关闭',

  /* ---- 提示 ---- */
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
  uploadTooLarge: '单个文件不能超过 10MB',
  uploadSuccess: '上传成功',
  uploadFailed: '上传失败',
  uploadChunkNoPermission: '没有分片上传权限，请先为该账号授予「分片上传」节点',
  moveSuccess: '已移动 {count} 个附件',
  publicUpdated: '公开状态已更新',
  privatePreviewFailed: '私有附件读取失败，请刷新后重试',
}
