/**
 * Menu management page.
 *
 * The key set mirrors `zh-CN/menu.ts` exactly.
 * Menu node titles are localized by the backend per request (admin-created menus are returned as-is) and are
 * not kept here; this file only covers fixed UI text and the node type enum.
 */
export default {
  menuLabel: 'Menus',
  permTip:
    'Button nodes are generated from controller #[Permission] annotations by cccms:perm-scan; manual changes are preserved on the next scan',

  /* ---- Node type / status enum ---- */
  typeDir: 'Directory',
  typeMenu: 'Menu',
  typeButton: 'Button',
  shown: 'Shown',
  hidden: 'Hidden',

  /* ---- Table and actions ---- */
  addChild: 'Add child',
  deleteConfirm: 'Delete this node?',
  createTitle: 'Add menu',
  editTitle: 'Edit menu',

  /* ---- Form ---- */
  parentNode: 'Parent node',
  top: 'Top level',
  typeLabel: 'Type',
  name: 'Name',
  namePlaceholder: 'Please enter a name',
  nameRequired: 'Please enter a name',
  node: 'Permission node',
  nodePlaceholder: 'e.g. cccms:user:index',
  nodeRequired: 'Please enter a permission node',
  nodeTip: 'Naming rule: plugin:module:action, globally unique',
  path: 'Route path',
  pathPlaceholder: 'e.g. /cccms/user',
  component: 'Component path',
  componentPlaceholder: 'e.g. cccms/user/index',
  icon: 'Icon',
  iconPlaceholder: 'Click to select an icon',
  sort: 'Sort',
  status: 'Status',

  /* ---- Messages ---- */
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
}
