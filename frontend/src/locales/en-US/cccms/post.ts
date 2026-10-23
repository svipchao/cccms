/**
 * Post (job title) management page.
 *
 * The key set mirrors `zh-CN/post.ts` exactly (`npm run i18n:check` verifies this).
 * Post code / name are user-entered data and are not part of the language pack.
 */
export default {
  nameLabel: 'Post name',
  searchPlaceholder: 'Please enter',
  batchAction: 'Batch actions',
  batchActionWithCount: 'Batch actions ({count})',
  batchEnable: 'Enable selected',
  batchDisable: 'Disable selected',
  batchDelete: 'Delete selected',
  recycleLabel: 'Post',
  enabled: 'Enabled',
  disabled: 'Disabled',
  deleteConfirm: 'Delete this post?',
  createTitle: 'New post',
  editTitle: 'Edit post',
  codeLabel: 'Post code',
  codePlaceholder: 'e.g. sale_manager',
  codeRequired: 'Please enter the post code',
  namePlaceholder: 'Please enter the post name',
  nameRequired: 'Please enter the post name',
  sortLabel: 'Sort',
  statusLabel: 'Status',
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
  batchEnableSuccess: '{count} record(s) enabled',
  batchEnablePartial: '{count} record(s) enabled, {skipped} skipped (no permission, not found, or still in use)',
  batchDisableSuccess: '{count} record(s) disabled',
  batchDisablePartial: '{count} record(s) disabled, {skipped} skipped (no permission, not found, or still in use)',
  batchDeleteSuccess: '{count} record(s) deleted',
  batchDeletePartial: '{count} record(s) deleted, {skipped} skipped (no permission, not found, or still in use)',
  batchDeleteTitle: 'Batch delete',
  batchDeleteConfirm: 'Delete the {count} selected post(s)?',
}
