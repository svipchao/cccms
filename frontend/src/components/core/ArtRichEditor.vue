<template>
  <div class="art-rich-editor" :class="{ 'is-disabled': disabled }">
    <div v-if="editor" class="art-rich-editor-toolbar">
      <template v-for="(group, index) in toolbar" :key="index">
        <span v-if="index > 0" class="art-rich-editor-divider" />
        <el-tooltip v-for="item in group" :key="item.key" :content="item.title" placement="top" :show-after="300">
          <button
            type="button"
            class="art-rich-editor-btn"
            :class="{ 'is-active': item.active() }"
            :disabled="disabled"
            @click="item.run()"
          >
            <el-icon v-if="item.icon"><component :is="item.icon" /></el-icon>
            <span v-else class="art-rich-editor-glyph" :class="item.glyphClass">{{ item.glyph }}</span>
          </button>
        </el-tooltip>
      </template>
    </div>
    <!-- minHeight 只撑起外层容器高度；空白区在本层拦截点击并聚焦，避免点击编辑区下方空白无反应 -->
    <div class="art-rich-editor-body" :style="{ minHeight: `${minHeight}px` }" @click="onBodyClick">
      <EditorContent :editor="editor" />
    </div>
    <div v-if="maxLength" class="art-rich-editor-count">{{ charCount }} / {{ maxLength }}</div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Brush, ChatLineSquare, Link, List, RefreshLeft, RefreshRight, Sort } from '@element-plus/icons-vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import { normalizeHtml } from '@/utils/richText'

/**
 * 公共富文本编辑器（tiptap）。
 *
 * 只给**需要长文**的字段用：备注 / 说明这类短文本框仍然用 `el-input type="textarea"`，
 * 套上富文本只会增加录入成本，没有任何收益。
 *
 * `v-model` 绑定的是 **HTML 字符串**，空内容统一归一为 `''`（不会把 `<p></p>` 丢给后端）；
 * 用它渲染到页面前必须先过 `utils/richText.ts` 的 `sanitizeHtml()`。
 *
 * 工具栏按钮文案与 placeholder 全部走语言包（`richEditor.*`），由调用方传 placeholder。
 */
const props = withDefaults(
  defineProps<{
    /** 双向绑定的 HTML 字符串，空内容为 '' */
    modelValue?: string
    /** 占位提示（调用方用 i18n 传入） */
    placeholder?: string
    /** 只读模式：编辑器不可编辑，工具栏按钮一并禁用 */
    disabled?: boolean
    /** 编辑区最小高度（px） */
    minHeight?: number
    /** 可选字数上限，只在传入时校验；超限的输入会被回退 */
    maxLength?: number
  }>(),
  { modelValue: '', placeholder: '', disabled: false, minHeight: 200 },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n({ useScope: 'global' })

/** 最近一次合法内容：字数超限时用它回退，避免把超限内容写进 v-model */
const lastValidHtml = ref(normalizeHtml(props.modelValue))

const editor = useEditor({
  content: lastValidHtml.value,
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      // 链接：编辑时点击不跳转，统一新窗口打开并带上 noopener
      link: {
        openOnClick: false,
        autolink: true,
        HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
      },
    }),
    Placeholder.configure({
      placeholder: () => props.placeholder,
    }),
  ],
  onUpdate: ({ editor: instance }) => {
    const textLength = instance.state.doc.textContent.length
    if (props.maxLength !== undefined && textLength > props.maxLength) {
      instance.commands.setContent(lastValidHtml.value, { emitUpdate: false })
      ElMessage.warning(t('core.richEditor.maxLengthExceeded', { max: props.maxLength }))
      return
    }
    const html = normalizeHtml(instance.getHTML())
    lastValidHtml.value = html
    emit('update:modelValue', html)
  },
})

// 只读切换：tiptap 的 editable 是命令式的，需要显式同步
watch(
  () => props.disabled,
  (value) => editor.value?.setEditable(!value),
)

// 外部改写 v-model（如打开编辑弹窗回填）时同步进编辑器，避免自己触发自己的更新
watch(
  () => props.modelValue,
  (value) => {
    const instance = editor.value
    if (!instance) {
      return
    }
    const next = normalizeHtml(value ?? '')
    if (next === normalizeHtml(instance.getHTML())) {
      return
    }
    lastValidHtml.value = next
    instance.commands.setContent(next, { emitUpdate: false })
  },
)

const charCount = computed(() => editor.value?.state.doc.textContent.length ?? 0)

/**
 * 点击编辑区空白处时把焦点交给编辑器。
 *
 * 落在 `.ProseMirror` 内部的点击由 ProseMirror 自己处理（会把光标放到点击位置），
 * 这里只接管它之外的空白区域——否则点击后既不聚焦也不出现光标。
 */
function onBodyClick(event: MouseEvent): void {
  if (props.disabled) {
    return
  }
  const target = event.target as HTMLElement | null
  if (target?.closest('.ProseMirror')) {
    return
  }
  editor.value?.chain().focus('end').run()
}

interface ToolbarItem {
  key: string
  title: string
  icon?: Component
  glyph?: string
  glyphClass?: string
  active: () => boolean
  run: () => void
}

/** 工具栏：Element Plus 没有排版类图标，B/I/U/S/H1-H3 这类用字形文本，其余用图标 */
const toolbar = computed<ToolbarItem[][]>(() => {
  const isActive = (name: string, attrs?: Record<string, unknown>): boolean =>
    editor.value?.isActive(name, attrs) ?? false
  const chain = () => editor.value?.chain().focus()

  return [
    [
      {
        key: 'bold',
        title: t('core.richEditor.bold'),
        glyph: 'B',
        glyphClass: 'is-bold',
        active: () => isActive('bold'),
        run: () => chain()?.toggleBold().run(),
      },
      {
        key: 'italic',
        title: t('core.richEditor.italic'),
        glyph: 'I',
        glyphClass: 'is-italic',
        active: () => isActive('italic'),
        run: () => chain()?.toggleItalic().run(),
      },
      {
        key: 'underline',
        title: t('core.richEditor.underline'),
        glyph: 'U',
        glyphClass: 'is-underline',
        active: () => isActive('underline'),
        run: () => chain()?.toggleUnderline().run(),
      },
      {
        key: 'strike',
        title: t('core.richEditor.strike'),
        glyph: 'S',
        glyphClass: 'is-strike',
        active: () => isActive('strike'),
        run: () => chain()?.toggleStrike().run(),
      },
    ],
    [
      {
        key: 'heading1',
        title: t('core.richEditor.heading1'),
        glyph: 'H1',
        glyphClass: 'is-heading',
        active: () => isActive('heading', { level: 1 }),
        run: () => chain()?.toggleHeading({ level: 1 }).run(),
      },
      {
        key: 'heading2',
        title: t('core.richEditor.heading2'),
        glyph: 'H2',
        glyphClass: 'is-heading',
        active: () => isActive('heading', { level: 2 }),
        run: () => chain()?.toggleHeading({ level: 2 }).run(),
      },
      {
        key: 'heading3',
        title: t('core.richEditor.heading3'),
        glyph: 'H3',
        glyphClass: 'is-heading',
        active: () => isActive('heading', { level: 3 }),
        run: () => chain()?.toggleHeading({ level: 3 }).run(),
      },
    ],
    [
      {
        key: 'bulletList',
        title: t('core.richEditor.bulletList'),
        icon: List,
        active: () => isActive('bulletList'),
        run: () => chain()?.toggleBulletList().run(),
      },
      {
        key: 'orderedList',
        title: t('core.richEditor.orderedList'),
        icon: Sort,
        active: () => isActive('orderedList'),
        run: () => chain()?.toggleOrderedList().run(),
      },
      {
        key: 'blockquote',
        title: t('core.richEditor.blockquote'),
        icon: ChatLineSquare,
        active: () => isActive('blockquote'),
        run: () => chain()?.toggleBlockquote().run(),
      },
    ],
    [
      {
        key: 'inlineCode',
        title: t('core.richEditor.inlineCode'),
        glyph: '<>',
        glyphClass: 'is-mono',
        active: () => isActive('code'),
        run: () => chain()?.toggleCode().run(),
      },
      {
        key: 'codeBlock',
        title: t('core.richEditor.codeBlock'),
        glyph: '{ }',
        glyphClass: 'is-mono',
        active: () => isActive('codeBlock'),
        run: () => chain()?.toggleCodeBlock().run(),
      },
      {
        key: 'link',
        title: t('core.richEditor.link'),
        icon: Link,
        active: () => isActive('link'),
        run: () => void setLink(),
      },
    ],
    [
      {
        key: 'clear',
        title: t('core.richEditor.clearFormat'),
        icon: Brush,
        active: () => false,
        run: () => chain()?.unsetAllMarks().clearNodes().run(),
      },
    ],
    [
      {
        key: 'undo',
        title: t('core.richEditor.undo'),
        icon: RefreshLeft,
        active: () => false,
        run: () => chain()?.undo().run(),
      },
      {
        key: 'redo',
        title: t('core.richEditor.redo'),
        icon: RefreshRight,
        active: () => false,
        run: () => chain()?.redo().run(),
      },
    ],
  ]
})

/** 链接：用弹窗输入 URL；留空表示取消链接 */
async function setLink(): Promise<void> {
  const instance = editor.value
  if (!instance) {
    return
  }
  const previous = (instance.getAttributes('link').href as string | undefined) ?? ''
  let value: string
  try {
    const result = await ElMessageBox.prompt(t('core.richEditor.linkPrompt'), t('core.richEditor.link'), {
      inputValue: previous,
      inputPlaceholder: t('core.richEditor.linkPlaceholder'),
      confirmButtonText: t('core.common.confirm'),
      cancelButtonText: t('core.common.cancel'),
      inputValidator: (input: string) => {
        const url = (input ?? '').trim()
        if (url === '') {
          return true
        }
        // 只允许站内相对路径与常见协议，挡掉 javascript: 这类注入
        return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url) ? true : t('core.richEditor.linkInvalid')
      },
    })
    value = (result.value ?? '').trim()
  } catch {
    // 用户取消，不做任何改动
    return
  }

  if (value === '') {
    instance.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }
  instance.chain().focus().extendMarkRange('link').setLink({ href: value }).run()
}
</script>

<style scoped>
.art-rich-editor {
  width: 100%;
  border: 1px solid var(--el-border-color);
  border-radius: 6px;
  overflow: hidden;
  background: var(--art-card-bg);
}

.art-rich-editor.is-disabled {
  background: var(--el-disabled-bg-color);
}

.art-rich-editor-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  align-items: center;
  padding: 4px 6px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-light);
}

.art-rich-editor-divider {
  width: 1px;
  height: 16px;
  margin: 0 4px;
  background: var(--el-border-color);
}

.art-rich-editor-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  font-size: 14px;
  line-height: 1;
  color: var(--art-sub);
  cursor: pointer;
  background: transparent;
  border: none;
  border-radius: 6px;
}

.art-rich-editor-btn:hover:not(:disabled) {
  color: var(--art-main);
  background: var(--el-fill-color);
}

.art-rich-editor-btn.is-active {
  color: var(--art-primary);
  background: var(--el-color-primary-light-9);
}

.art-rich-editor-btn:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.art-rich-editor-glyph {
  font-size: 13px;
  font-weight: 500;
}

.art-rich-editor-glyph.is-bold {
  font-weight: 700;
}

.art-rich-editor-glyph.is-italic {
  font-family: Georgia, 'Times New Roman', serif;
  font-style: italic;
}

.art-rich-editor-glyph.is-underline {
  text-decoration: underline;
}

.art-rich-editor-glyph.is-strike {
  text-decoration: line-through;
}

.art-rich-editor-glyph.is-heading {
  font-size: 11px;
  font-weight: 700;
}

.art-rich-editor-glyph.is-mono {
  font-family: Consolas, Menlo, monospace;
  font-size: 11px;
  letter-spacing: -0.5px;
}

/* 空白区也可点，给出文本光标暗示；只读态不暗示可编辑 */
.art-rich-editor:not(.is-disabled) .art-rich-editor-body {
  cursor: text;
}

.art-rich-editor-body :deep(.ProseMirror) {
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.7;
  color: var(--art-main);
  outline: none;
}

.art-rich-editor-body :deep(.ProseMirror p) {
  margin: 0 0 8px;
}

.art-rich-editor-body :deep(.ProseMirror p:last-child) {
  margin-bottom: 0;
}

.art-rich-editor-body :deep(.ProseMirror h1),
.art-rich-editor-body :deep(.ProseMirror h2),
.art-rich-editor-body :deep(.ProseMirror h3),
.art-rich-editor-body :deep(.ProseMirror h4) {
  margin: 12px 0 8px;
  font-weight: 600;
  line-height: 1.4;
}

.art-rich-editor-body :deep(.ProseMirror h1) {
  font-size: 22px;
}

.art-rich-editor-body :deep(.ProseMirror h2) {
  font-size: 19px;
}

.art-rich-editor-body :deep(.ProseMirror h3) {
  font-size: 16px;
}

/* Tailwind preflight 会清掉列表样式，这里显式恢复 */
.art-rich-editor-body :deep(.ProseMirror ul),
.art-rich-editor-body :deep(.ProseMirror ol) {
  margin: 0 0 8px;
  padding-left: 22px;
}

.art-rich-editor-body :deep(.ProseMirror ul) {
  list-style: disc;
}

.art-rich-editor-body :deep(.ProseMirror ol) {
  list-style: decimal;
}

.art-rich-editor-body :deep(.ProseMirror blockquote) {
  margin: 0 0 8px;
  padding-left: 10px;
  color: var(--art-sub);
  border-left: 3px solid var(--el-border-color);
}

.art-rich-editor-body :deep(.ProseMirror code) {
  padding: 2px 4px;
  font-family: Consolas, Menlo, monospace;
  font-size: 13px;
  background: var(--el-fill-color);
  border-radius: 4px;
}

.art-rich-editor-body :deep(.ProseMirror pre) {
  margin: 0 0 8px;
  padding: 10px 12px;
  overflow-x: auto;
  font-family: Consolas, Menlo, monospace;
  font-size: 13px;
  background: var(--el-fill-color-dark);
  border-radius: 6px;
}

.art-rich-editor-body :deep(.ProseMirror pre code) {
  padding: 0;
  background: transparent;
}

.art-rich-editor-body :deep(.ProseMirror a) {
  color: var(--art-primary);
  text-decoration: underline;
}

.art-rich-editor-body :deep(.ProseMirror img) {
  max-width: 100%;
  height: auto;
}

.art-rich-editor-body :deep(.ProseMirror p.is-editor-empty:first-child::before),
.art-rich-editor-body :deep(.ProseMirror .is-empty::before) {
  float: left;
  height: 0;
  color: var(--el-text-color-placeholder);
  pointer-events: none;
  content: attr(data-placeholder);
}

.art-rich-editor-count {
  padding: 2px 10px 6px;
  font-size: 12px;
  color: var(--art-muted);
  text-align: right;
}
</style>
