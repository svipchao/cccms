/**
 * Role management page.
 *
 * The key set mirrors `zh-CN/role.ts` exactly (`npm run i18n:check` verifies this).
 */
export default {
  // Search / left tree / table
  pleaseInput: 'Please enter',
  treeTitle: 'Role hierarchy',
  allRoles: 'All roles',
  onlyView: 'Showing only: {name} and its descendants',
  name: 'Role name',
  code: 'Role code',
  parent: 'Parent role',
  topRole: 'Top-level role',
  dataScope: 'Data scope',
  status: 'Status',
  enabled: 'Enabled',
  disabled: 'Disabled',

  // Row actions
  copy: 'Copy',
  confirmDelete: 'Delete this role?',

  // Create / edit form
  createRole: 'New role',
  editRole: 'Edit role',
  nameRequired: 'Please enter a role name',
  codePlaceholder: 'e.g. sale_manager (the super admin role cannot be changed)',
  codeRequired: 'Please enter a role code',
  noneTop: 'None (top level)',
  parentTip:
    'Child roles automatically inherit the parent role permission nodes; only check the nodes unique to this role here.',
  scopeAll: 'All data',
  scopeDeptAndBelow: 'This department and below',
  scopeDept: 'This department',
  scopeSelf: 'Only yourself',
  scopeCustom: 'Custom rules',
  scopeCustomShort: 'Custom',
  scopeTipPrefix: 'This is the "baseline". Row-level rules bound to this role on the data permission page will',
  scopeTipAnd: 'stack (AND)',
  scopeTipMiddle:
    'on top of the baseline: selecting "This department and below" plus custom rules = only data in this department and below',
  scopeTipAnd2: 'and',
  scopeTipSuffix:
    'matching the rules. Under "All data" custom row-level rules do not apply; under "Custom rules" there is no baseline, so if no rule matches you will see no data.',
  permNodes: 'Permission nodes',
  collapseAll: 'Collapse all',
  expandAll: 'Expand all',
  selectAll: 'Select all',
  clear: 'Clear',

  // Copy role
  copyRole: 'Copy role',
  sourceRole: 'Source role',
  newRoleName: 'New role name',
  newRoleNameRequired: 'Please enter a new role name',
  newRoleCode: 'New role code',
  newRoleCodePlaceholder: 'e.g. sale_manager_copy',
  newRoleCodeRequired: 'Please enter a new role code',
  copyParentTip: 'Defaults to the source role; inheritance depth cannot exceed 5 levels.',
  saveAsTemplate: 'Save as template',
  templateTip: 'The copy will be created as "disabled" and will not affect anyone’s permissions',
  copyNameSuffix: '{name} copy',

  // Messages
  entityLabel: 'Role',
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
  copySuccess: 'Copied successfully',
  copyTemplateSuccess: 'Saved as a template (disabled)',
}
