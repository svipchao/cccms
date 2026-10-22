/**
 * Attachment management page.
 *
 * The key set mirrors `zh-CN/file.ts` exactly.
 * File names, category names and similar are user-entered data and are not kept here.
 */
export default {
  /* ---- Left category tree ---- */
  categoryTreeTitle: 'Attachment categories',
  addTopCategory: 'Add top-level category',
  addSubCategory: 'Add subcategory',
  renameCategory: 'Rename / reorder',
  all: 'All',
  uncategorized: 'Uncategorized',
  topCategory: 'Top-level category',

  /* ---- Search / table ---- */
  nameLabel: 'File name',
  searchPlaceholder: 'Please enter',
  extLabel: 'Extension',
  extPlaceholder: 'e.g. png',
  categoryColumnLabel: 'Category',
  typeLabel: 'Type',
  sizeLabel: 'Size',
  uploadTimeLabel: 'Uploaded at',
  preview: 'Preview',
  view: 'View',
  download: 'Download',
  publicLabel: 'Public',
  publicTip: 'Public: anyone with the link can read it. Private: the link stops working; only signed-in users with permission can download',

  /* ---- Toolbar ---- */
  recycleLabel: 'Attachment',
  upload: 'Upload attachment',
  move: 'Move to category',
  moveWithCount: 'Move to category ({count})',
  onlyView: 'Viewing: {name}',
  toolbarTip: 'Max 10MB per file; identical content is de-duplicated automatically',
  deleteConfirm: 'Delete this attachment?',

  /* ---- Category form ---- */
  createCategoryTitle: 'Add category',
  editCategoryTitle: 'Edit category',
  parentCategoryLabel: 'Parent category',
  categoryNameLabel: 'Category name',
  categoryNamePlaceholder: 'e.g. Contract attachments',
  sortLabel: 'Sort',
  remarkLabel: 'Remark',
  remarkPlaceholder: 'Please enter a remark',
  categoryNameRequired: 'Please enter a category name',
  deleteCategoryTitle: 'Delete category',
  deleteCategoryConfirm: 'Delete category "{name}"?',

  /* ---- Move to category ---- */
  moveTitle: 'Move to category',
  targetCategoryLabel: 'Target category',

  /* ---- PDF preview ---- */
  pdfPreview: 'PDF preview',
  openInNewWindow: 'Open in new window',
  close: 'Close',

  /* ---- Messages ---- */
  saveSuccess: 'Saved successfully',
  deleteSuccess: 'Deleted successfully',
  uploadTooLarge: 'A single file cannot exceed 10MB',
  uploadSuccess: 'Uploaded successfully',
  uploadFailed: 'Upload failed',
  uploadChunkNoPermission: 'No chunked upload permission. Grant the "Chunked upload" node to this account first',
  moveSuccess: 'Moved {count} attachment(s)',
  publicUpdated: 'Visibility updated',
  privatePreviewFailed: 'Failed to read the private attachment, please refresh and retry',
}
