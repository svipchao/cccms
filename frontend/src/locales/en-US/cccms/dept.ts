/**
 * Department management page.
 *
 * The key set mirrors `zh-CN/dept.ts` exactly (`npm run i18n:check` verifies this).
 * User-entered data (department name, leader, ...) is not part of the language pack.
 */
export default {
  treeTitle: 'Organization',
  filteredLabel: 'Viewing: {name}',
  treeTip: 'Trees are supported: click "Add sub-department" to attach a child quickly',
  recycleLabel: 'Department',
  allDepts: 'All departments',
  topLevel: 'Top level',
  enabled: 'Enabled',
  disabled: 'Disabled',
  addChild: 'Add sub-department',
  deleteConfirm: 'Delete this department?',
  createTitle: 'New department',
  editTitle: 'Edit department',
  parentLabel: 'Parent department',
  nameLabel: 'Department name',
  namePlaceholder: 'Please enter the department name',
  nameRequired: 'Please enter the department name',
  leaderLabel: 'Leader',
  leaderPlaceholder: 'Please enter the leader',
  phoneLabel: 'Phone',
  phonePlaceholder: 'Please enter the phone number',
  emailLabel: 'Email',
  emailPlaceholder: 'Please enter the email',
  sortLabel: 'Sort',
  statusLabel: 'Status',
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
}
