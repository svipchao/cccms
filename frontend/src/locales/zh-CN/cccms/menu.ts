/**
 * 菜单管理页。
 *
 * 与 `en-US/menu.ts` 保持**完全一致的 key 集合**。
 * 菜单节点标题由后端按请求语言下发（管理员自建菜单原样返回），不在此维护；此处仅覆盖界面固定文案与节点类型枚举。
 */
export default {
  menuLabel: '菜单',
  permTip: '按钮节点由控制器 #[Permission] 注解经 cccms:perm-scan 生成，人工改动会在下次扫描时保留',

  /* ---- 节点类型 / 状态枚举 ---- */
  typeDir: '目录',
  typeMenu: '菜单',
  typeButton: '按钮',
  shown: '显示',
  hidden: '隐藏',

  /* ---- 表格与操作 ---- */
  addChild: '新增子项',
  deleteConfirm: '确定删除该节点？',
  createTitle: '新增菜单',
  editTitle: '编辑菜单',

  /* ---- 表单 ---- */
  parentNode: '上级节点',
  top: '顶级',
  typeLabel: '类型',
  name: '名称',
  namePlaceholder: '请输入名称',
  nameRequired: '请输入名称',
  node: '权限节点',
  nodePlaceholder: '如 cccms:user:index',
  nodeRequired: '请输入权限节点',
  nodeTip: '命名规范：插件:模块:动作，全局唯一',
  path: '路由地址',
  pathPlaceholder: '如 /cccms/user',
  component: '组件路径',
  componentPlaceholder: '如 cccms/user/index',
  icon: '图标',
  iconPlaceholder: '点击选择图标',
  sort: '排序',
  status: '状态',

  /* ---- 提示 ---- */
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
}
