/**
 * Data permission rules page.
 *
 * The key set mirrors `zh-CN/data_rule.ts` exactly.
 * Rule names, table names, field names and binding target names are user-entered or
 * backend-provided data and are not kept here.
 */
export default {
  /* ---- Page hint ---- */
  tip: 'The preset level (a role’s "Data scope") defines the baseline, and custom row-level rules stack on top of it (AND); under "All data" rules do not apply, and under "Custom rules" nothing is visible if no rule matches. Selecting none of the four binding dimensions = a global rule; how the binding dimensions combine is decided by "Binding mode": any match (OR) / all match (AND). A row-level rule with a "Target table" only applies to that table. Target tables can only be tables with data permission enabled, and fields cascade from the target table. The "Check" column flags risks such as mutually exclusive conditions (when several rules match the same user they stack with AND, and mutually exclusive conditions make nothing visible).',

  /* ---- Search / table ---- */
  nameLabel: 'Rule name',
  searchPlaceholder: 'Please enter',
  targetTableLabel: 'Target table',
  allPlaceholder: 'All',
  fieldNameLabel: 'Field name',
  fieldLabel: 'Field',
  bindLabel: 'Bindings',
  actionColumnLabel: 'Action',
  conditionLabel: 'Condition',
  conflictColumnLabel: 'Check',
  recycleLabel: 'Data permission rule',
  create: 'New rule',
  managedTables: 'Managed tables',

  /* ---- Binding display ---- */
  anyTable: 'Any table',
  global: 'Global',
  bindAnd: 'AND',
  bindOr: 'OR',
  bindAndTip: 'Applies only when all filled bindings match',
  bindOrTip: 'Applies when any filled binding matches',
  bindUser: 'User: {name}',
  bindPost: 'Post: {name}',
  bindRole: 'Role: {name}',
  bindDept: 'Department: {name}',
  conflict: 'Conflict',
  hint: 'Notice',

  /* ---- Rule action / operator ---- */
  actionRow: 'Row-level filter',
  actionHidden: 'Field hidden',
  actionReadonly: 'Field read-only',
  actionMask: 'Field masking',
  actionEncrypt: 'Field encryption',
  opEq: 'equals',
  opNe: 'not equals',
  opGt: 'greater than',
  opGe: 'greater than or equal',
  opLt: 'less than',
  opLe: 'less than or equal',
  opLike: 'contains',
  opIn: 'in',
  opBetween: 'between',

  /* ---- Form: bindings ---- */
  createTitle: 'New rule',
  editTitle: 'Edit rule',
  namePlaceholder: 'e.g. Support sees only open tickets',
  nameRequired: 'Please enter a rule name',
  fieldRequired: 'Please select the target table and field',
  deleteConfirm: 'Delete this rule?',
  userSearchPlaceholder: 'Search by account or nickname',
  userTip: 'Fuzzy search by account / nickname; matches when the login account is this user.',
  postTip: 'Matches when the user is assigned this post.',
  roleTipPrefix: 'Only ',
  roleTipBold: 'directly assigned',
  roleTipSuffix: ' roles count: child roles are not included and parent roles are not inherited.',
  deptTipPrefix: 'Multiple selection allowed; binding a department',
  deptTipBold: 'includes its descendants',
  deptTipSuffix: ': binding "Head office" covers everyone in all its departments.',
  addToSelected: 'Add to selected',
  addHintMulti:
    'Departments support multiple selection: each "Add" merges into the selected list, duplicates are ignored.',
  addHintSingle: 'Single-select dimension: "Add" replaces the existing selection for this dimension.',
  selectedBindings: 'Selected bindings',
  clearAll: 'Clear all',
  noneSelected: 'Nothing selected —— selecting none of the four = a global rule',
  bindUserLabel: 'User',
  bindPostLabel: 'Post',
  bindRoleLabel: 'Role',
  bindDeptLabel: 'Department',
  bindTabWithCount: '{name} ({count})',

  /* ---- Form: binding mode ---- */
  bindModeLabel: 'Binding mode',
  bindModeOr: 'Any match (OR)',
  bindModeAnd: 'All match (AND)',
  bindModeTipPrefix: '"OR" = among the selected items, it applies when ',
  bindModeTipBoldOr: 'any one matches',
  bindModeTipMiddle: ' (broader); "AND" = it applies only to',
  bindModeTipBoldAnd: ' people satisfying all selected items',
  bindModeTipSuffix: ' (narrower, e.g. "Zhang San AND in the support post").',
  bindModeTipTail:
    'Unselected items do not participate; selecting none of the four = a global rule and this switch has no effect.',

  /* ---- Form: target table / action / value ---- */
  targetPlaceholder: 'Select target table and field',
  anyTableOption: 'Any table (applies to all enabled modules)',
  fieldHintAnyTable:
    'Any table: applies to all modules with data permission; the field name must exist in every table, so specify a target table when unsure.',
  fieldHintTable:
    'Select the target table first, then the field; only fields of that table are listed, with the field comment in parentheses.',
  actionLabel: 'Rule action',
  optionWithCode: '{name} ({code})',
  actionTip: 'row = row-level filter; the others are field-level (they affect output, while readonly affects input).',
  encryptTitle: 'encrypt only guards against "raw database reads", it is not access control',
  encryptDesc:
    'The decryption key is issued to admins who can configure this page and to super admins, so when the API leaks the ciphertext equals plaintext. To control the visibility of sensitive fields use "field masking (mask)" together with the data permission level; a scheme that decrypts on the backend before responding needs separate evaluation.',
  operatorLabel: 'Operator',
  valueTypeLabel: 'Value type',
  staticValue: 'Static value',
  dynamicValue: 'Dynamic variable',
  valueTypeTipPrefix: 'Dynamic variables are resolved in real time from the ',
  valueTypeTipBold: 'current user',
  valueTypeTipSuffix: ', which can express dynamic scopes such as "this department and below".',
  valueLabel: 'Value',
  valuePlaceholder: 'Select a variable or type a literal',
  staticValuePlaceholder: 'e.g. 1, or separated by commas: 1,2,3',
  valueHintStatic: 'in / between use comma separation (between looks like 10,20).',
  valueHintBetween: "Exactly two values are required, e.g. {'{'}user.id{'}'},100.",
  valueHintIn: "Multiple variables are merged into a set for \"in (IN)\", e.g. dept_id in {'{'}dept.subtree{'}'}.",
  valueHintOther:
    'When a variable expands into several values it is treated as "in (IN)"; prefer the "in" operator to express set semantics.',
  remarkLabel: 'Remark',

  /* ---- Managed tables ---- */
  managedTableTitle: 'Data permission managed tables',
  managedTableTip:
    'Only tables registered here appear in the "Target table" candidates; all other tables are hidden. Registration requires the table to have data permission enabled (the model declares participation and queries go through the model), otherwise rules will not take effect; verify with php webman cccms:data-scope-check.',
  managedTableDesc:
    'Registration only decides "whether custom rules can be configured". Unregistered tables (such as departments) are not without data permission; they just use the preset baseline from the role level and cannot have separate rules.',
  addTablePlaceholder: 'Select a table to add as managed',
  semanticNamePlaceholder: 'Semantic name (empty = table comment)',
  add: 'Add',
  tableNameLabel: 'Table name',
  tableMissing: 'Table not found',
  semanticNameLabel: 'Semantic name',
  fieldCountLabel: 'Fields',
  controlledLabel: 'Managed',
  remove: 'Remove',
  removeConfirm: 'After removal, rules on this table are suspended. Continue?',
  noManagedTable: 'No managed tables registered yet',
  tableAdded: 'Added as a managed table',
  tableSaved: 'Saved',
  tableRemoved: 'Removed',
  tableRemovedWithRules: 'Removed; {count} rule(s) on this table are suspended',

  /* ---- Common messages ---- */
  close: 'Close',
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
}
