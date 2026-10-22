/**
 * Tenant management page + header tenant switcher.
 *
 * Key set must match `zh-CN/tenant.ts` exactly (`npm run i18n:check` verifies it).
 * Tenant name / code / contact are user-entered and live outside the language packs.
 */
export default {
  // ---- List ----
  searchPlaceholder: 'Search by name or code',
  createTitle: 'Create tenant',
  editTitle: 'Edit tenant',
  nameLabel: 'Tenant name',
  namePlaceholder: 'Please enter the tenant name',
  codeLabel: 'Tenant code',
  codePlaceholder: 'e.g. acme; letters, digits, underscore and hyphen only',
  domainLabel: 'Custom domain',
  domainPlaceholder: 'e.g. crm.example.com; leave empty for none',
  domainTip: 'Once bound, this domain can only access this tenant and cannot sign in to the platform',
  domainInvalid: 'Invalid domain: enter the host name only (e.g. crm.example.com), without protocol or port',
  contactLabel: 'Contact',
  contactPlaceholder: 'Please enter the contact',
  phoneLabel: 'Phone',
  phonePlaceholder: 'Please enter the phone number',
  expireLabel: 'Expires at',
  expirePlaceholder: 'Leave empty for no expiry',
  expireNever: 'Never',
  remarkLabel: 'Remark',
  remarkPlaceholder: 'Please enter a remark',
  statusLabel: 'Status',
  userCountLabel: 'Accounts',
  enabled: 'Enabled',
  disabled: 'Disabled',
  platformTag: 'Platform',
  platformHint: 'The platform tenant is built-in and cannot be edited or deleted',
  nameRequired: 'Please enter the tenant name',
  codeRequired: 'Please enter the tenant code',
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
  deleteConfirm: 'Delete this tenant?',
  deleteTitle: 'Delete tenant',

  // ---- Header switcher (super admin only) ----
  switchTenant: 'Switch tenant',
  currentTenant: 'Current tenant',
  switchConfirm: 'Switch to "{name}"? You will only see that tenant\'s data.',
  switchTitle: 'Switch tenant',
  switchSuccess: 'Switched to "{name}"',
  backToPlatform: 'Back to platform',
  loadFailed: 'Failed to load the tenant list',
}
