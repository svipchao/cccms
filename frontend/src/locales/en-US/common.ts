/**
 * English language pack — generic buttons and global messages.
 *
 * The key set mirrors `zh-CN/common.ts` exactly (`npm run i18n:check` verifies this).
 */
export default {
  confirm: 'Confirm',
  cancel: 'Cancel',
  save: 'Save',
  delete: 'Delete',
  create: 'Create',
  edit: 'Edit',
  search: 'Search',
  reset: 'Reset',
  export: 'Export',
  refresh: 'Refresh',
  back: 'Back',
  close: 'Close',
  tip: 'Tip',
  success: 'Operation succeeded',
  requestFailed: 'Request failed',
  requestError: 'Request error ({status})',
  networkError: 'Network error, please check whether the service is running',
  loginExpired: 'Your session has expired, please sign in again',
  // Appended to error messages so users can search the action in the log page
  actionIdSuffix: ' (action {id})',
  // Built-in copy for the node picker (ArtNodePicker); callers need not be aware of it
  nodePicker: {
    clear: 'Clear',
    expandAll: 'Expand all',
    collapseAll: 'Collapse all',
    selectAll: 'Select all',
    searchPlaceholder: 'Enter keywords to search',
    searching: 'Searching…',
    noMatchUser: 'No matching user',
  },
}
