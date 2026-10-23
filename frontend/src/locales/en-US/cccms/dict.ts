/**
 * Dictionary management page (categories / types / data).
 *
 * The key set mirrors `zh-CN/dict.ts` exactly.
 * Category names, dictionary names, item labels and values are user-entered data and are not kept here.
 */
export default {
  /* ---- Category tree ---- */
  categoryTreeTitle: 'Dictionary categories',
  addTopCategory: 'Add top-level category',
  addSubCategory: 'Add subcategory',
  renameCategory: 'Rename / reorder',
  all: 'All',
  uncategorized: 'Uncategorized',
  topCategory: 'Top-level category',

  /* ---- Dictionary types ---- */
  name: 'Dictionary name',
  typeName: 'Dictionary type',
  category: 'Category',
  remark: 'Remark',
  data: 'Dictionary data',
  inputPlaceholder: 'Please enter',
  createType: 'Add dictionary type',
  editType: 'Edit dictionary type',
  namePlaceholder: 'e.g. User gender',
  typePlaceholder: 'e.g. user_gender',
  remarkPlaceholder: 'Please enter a remark',
  nameRequired: 'Please enter a dictionary name',
  typeRequired: 'Please enter a dictionary type',
  typeDeleteConfirm: 'Deleting the type will also delete its data. Continue?',

  /* ---- Category form ---- */
  createCategory: 'Add category',
  editCategory: 'Edit category',
  parentCategory: 'Parent category',
  categoryName: 'Category name',
  categoryNamePlaceholder: 'e.g. System dictionaries',
  categoryNameRequired: 'Please enter a category name',
  deleteCategoryTitle: 'Delete category',
  categoryDeleteConfirm: 'Delete category "{name}"?',

  /* ---- Batch actions ---- */
  batchAction: 'Batch actions',
  batchActionCount: 'Batch actions ({count})',
  batchEnable: 'Batch enable',
  batchDisable: 'Batch disable',
  batchDelete: 'Batch delete',
  batchEnableDone: 'Enabled {count} item(s)',
  batchEnableSkipped: 'Enabled {affected} item(s), {skipped} skipped (no permission or not found)',
  batchDisableDone: 'Disabled {count} item(s)',
  batchDisableSkipped: 'Disabled {affected} item(s), {skipped} skipped (no permission or not found)',
  batchDeleteDone: 'Deleted {count} item(s)',
  batchDeleteSkipped: 'Deleted {affected} item(s), {skipped} skipped (no permission or not found)',
  batchTypeDeleteConfirm: 'Deleting the types will also delete their data. Delete the {count} selected type(s)?',
  batchDataDeleteConfirm: 'Delete the {count} selected dictionary item(s)?',

  /* ---- Dictionary data ---- */
  dataTitle: 'Dictionary data - {name}',
  createData: 'Add data',
  editData: 'Edit data',
  label: 'Display name',
  value: 'Value',
  sort: 'Sort',
  status: 'Status',
  enable: 'Enabled',
  disable: 'Disabled',
  labelRequired: 'Please enter a display name',
  valueRequired: 'Please enter a value',
  dataDeleteConfirm: 'Delete this item?',
  onlyView: 'Viewing: {name}',

  /* ---- Common messages ---- */
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
}
