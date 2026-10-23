/**
 * 公共富文本编辑器（ArtRichEditor）文案。
 *
 * 与 `en-US/richEditor.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 * 工具栏按钮 title、placeholder 输入提示与字数统计都在这里。
 */
export default {
  bold: '加粗',
  italic: '斜体',
  underline: '下划线',
  strike: '删除线',
  heading1: '一级标题',
  heading2: '二级标题',
  heading3: '三级标题',
  bulletList: '无序列表',
  orderedList: '有序列表',
  blockquote: '引用',
  inlineCode: '行内代码',
  codeBlock: '代码块',
  link: '链接',
  clearFormat: '清除格式',
  undo: '撤销',
  redo: '重做',
  linkPrompt: '请输入链接地址（留空表示取消链接）',
  linkPlaceholder: 'https://example.com 或 /path',
  linkInvalid: '链接需以 http(s)://、mailto:、tel:、/ 或 # 开头',
  maxLengthExceeded: '内容不能超过 {max} 个字符',
}
