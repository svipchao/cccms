/**
 * Shared rich text editor (ArtRichEditor) messages.
 *
 * The key set mirrors `zh-CN/richEditor.ts` exactly (`npm run i18n:check` verifies this).
 * Toolbar button titles, the placeholder input hint and the character counter live here.
 */
export default {
  bold: 'Bold',
  italic: 'Italic',
  underline: 'Underline',
  strike: 'Strikethrough',
  heading1: 'Heading 1',
  heading2: 'Heading 2',
  heading3: 'Heading 3',
  bulletList: 'Bullet list',
  orderedList: 'Ordered list',
  blockquote: 'Blockquote',
  inlineCode: 'Inline code',
  codeBlock: 'Code block',
  link: 'Link',
  clearFormat: 'Clear formatting',
  undo: 'Undo',
  redo: 'Redo',
  linkPrompt: 'Enter a URL (leave blank to remove the link)',
  linkPlaceholder: 'https://example.com or /path',
  linkInvalid: 'The link must start with http(s)://, mailto:, tel:, / or #',
  maxLengthExceeded: 'Content cannot exceed {max} characters',
}
