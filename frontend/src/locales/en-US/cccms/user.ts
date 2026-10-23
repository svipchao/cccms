/**
 * User management page.
 *
 * The key set mirrors `zh-CN/user.ts` exactly (`npm run i18n:check` verifies this).
 */
export default {
  // Search / table
  username: 'Username',
  pleaseInput: 'Please enter',
  nickname: 'Nickname',
  status: 'Status',
  all: 'All',
  enabled: 'Enabled',
  disabled: 'Disabled',
  lastLogin: 'Last login',

  // Batch actions
  batchAction: 'Batch actions',
  batchActionCount: 'Batch actions ({count})',
  batchEnable: 'Batch enable',
  batchDisable: 'Batch disable',
  batchAssign: 'Batch assign roles / departments / positions',
  batchDelete: 'Batch delete',
  batchToggle: 'Batch enable/disable',
  batchVerbDelete: 'Deleted',
  batchVerbUpdate: 'Updated',
  batchVerbAssign: 'Assigned',
  batchDone: '{action} {affected} item(s)',
  batchSkipped: '{action} {affected} item(s); {skipped} skipped (no permission, yourself, super admin or not found)',
  confirmBatchDelete: 'Delete the {count} selected users? They can be restored from the recycle bin.',
  confirmBatchEnable: 'Enable the {count} selected users?',
  confirmBatchDisable: 'Disable the {count} selected users? They will lose access on their next request.',
  assignTip: 'Replaces the matching fields of the {count} selected users (leave empty to keep unchanged).',
  keepAsIs: 'Keep unchanged',
  assignAtLeastOne: 'Please select at least one of role / department / position to assign',

  // Row actions and recycle bin
  entityLabel: 'User',
  resetPassword: 'Reset password',
  confirmDelete: 'Delete this user?',

  // Create / edit form
  createUser: 'New user',
  editUser: 'Edit user',
  password: 'Password',
  newPassword: 'New password',
  passwordPlaceholder: 'At least 6 characters, using 2 of: uppercase, lowercase, digits, symbols',
  usernameRequired: 'Please enter a username',
  nicknamePlaceholder: 'Please enter a nickname',
  phone: 'Phone',
  phonePlaceholder: 'Please enter a phone number',
  email: 'Email',
  emailPlaceholder: 'Please enter an email',
  role: 'Role',
  rolePlaceholder: 'Please select a role',
  dept: 'Department',
  deptPlaceholder: 'Please select a department',
  post: 'Position',
  postPlaceholder: 'Please select a position',
  passwordRequired: 'Please enter a password',
  newPasswordRequired: 'Please enter a new password',

  // Messages
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
  resetSuccess: 'Reset successfully',
}
