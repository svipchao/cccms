<template>
  <div class="art-table">
    <!--
      搜索区：与表格卡片同级，单独一张卡放在表格上方。
      搜索项按列的 filter 配置自动生成，和列头筛选共用同一份 query，
      两边天然联动（列头改了，这里的值跟着变；这里查询了，列头图标也会高亮）。
      条件多时折叠，只显示前几项，点「展开」看全部。
    -->
    <el-card v-if="searchColumns.length || $slots['search-extra']" class="art-table-search" shadow="never">
      <!-- 搜索区同理：查询 / 重置按钮与 #search-extra 里的页面控件都跟随按钮尺寸偏好 -->
      <el-config-provider :size="effectiveButtonSize">
        <!--
        页面自定义搜索项（如层级切换）通过 #search-extra 注入，排在自动生成的搜索项前面；
        没有 filter 列配置的页面也能用这张搜索卡承载自己的搜索 UI。
      -->
        <slot name="search-extra" />
        <el-form v-if="searchColumns.length" class="art-table-search-form" :inline="true" @submit.prevent>
          <el-form-item
            v-for="(col, index) in searchColumns"
            v-show="searchExpanded || index < SEARCH_COLLAPSE_LIMIT"
            :key="col.prop"
            :label="col.label"
            class="art-table-search-item"
          >
            <!-- 枚举：多选下拉（与列头弹层同一套取值，多值按逗号拼接） -->
            <el-select
              v-if="col.filter?.type === 'enum'"
              v-model="searchEnum[col.prop]"
              multiple
              collapse-tags
              collapse-tags-tooltip
              clearable
              :placeholder="t('table.filterAll')"
            >
              <el-option
                v-for="opt in col.filter?.options ?? []"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </el-select>
            <!-- 日期：范围选择（带时分秒，起止分别写入 startKey / endKey） -->
            <el-date-picker
              v-else-if="col.filter?.type === 'date'"
              v-model="searchDate[col.prop]"
              v-bind="datePickerProps"
            />
            <!-- 文本：模糊查询 -->
            <el-input
              v-else
              v-model="searchText[col.prop]"
              clearable
              :placeholder="col.filter?.placeholder || t('table.filterTextPlaceholder')"
              @keyup.enter="submitSearch"
            />
          </el-form-item>
        </el-form>
        <div v-if="searchColumns.length" class="art-table-search-actions">
          <el-button type="primary" :icon="Search" @click="submitSearch">
            {{ t('common.search') }}
          </el-button>
          <el-button :icon="RefreshLeft" @click="resetSearch">{{ t('table.reset') }}</el-button>
          <!-- 搜索条件超过折叠阈值时才给展开入口 -->
          <el-button
            v-if="searchColumns.length > SEARCH_COLLAPSE_LIMIT"
            link
            type="primary"
            @click="searchExpanded = !searchExpanded"
          >
            {{ searchExpanded ? t('table.searchCollapse') : t('table.searchExpand') }}
            <el-icon class="art-table-search-arrow">
              <ArrowUp v-if="searchExpanded" />
              <ArrowDown v-else />
            </el-icon>
          </el-button>
        </div>
      </el-config-provider>
    </el-card>

    <el-card class="art-table-main" shadow="never">
      <!--
        工具栏尺寸：套一层 el-config-provider。
        它是**无渲染组件**（不产生 DOM），所以不会破坏 .art-table-toolbar 的 flex 布局；
        但它能让**页面通过 #toolbar / #toolbar-right 插槽传进来的按钮**也一起变尺寸 ——
        插槽内容在父组件作用域里创建，父组件无法从 props 改它们的尺寸，只有 provider 能向下传递。
      -->
      <el-config-provider :size="effectiveButtonSize">
        <div class="art-table-toolbar">
          <div class="art-table-toolbar-left">
            <!--
            回收站模式：左侧操作区整体换成「还原 / 彻底删除」，页面的新增/批量操作等按钮不渲染
            （在回收站里做这些操作没有意义）。
          -->
            <template v-if="recycle">
              <el-tag type="warning" effect="dark" round>{{ t('table.recycleMode') }}</el-tag>
              <template v-if="selection">
                <el-button
                  v-auth="'cccms:recycle:restore'"
                  type="primary"
                  :icon="RefreshLeft"
                  :disabled="selected.length === 0"
                  @click="emit('restore')"
                >
                  {{ t('table.restoreSelected') }}{{ selected.length ? `（${selected.length}）` : '' }}
                </el-button>
                <el-button
                  v-auth="'cccms:recycle:delete'"
                  type="danger"
                  plain
                  :icon="Delete"
                  :disabled="selected.length === 0"
                  @click="emit('force-delete')"
                >
                  {{ t('table.forceDeleteSelected') }}{{ selected.length ? `（${selected.length}）` : '' }}
                </el-button>
              </template>
            </template>
            <slot v-else name="toolbar" />
          </div>
          <div class="art-table-toolbar-right">
            <slot name="toolbar-right" />
            <!--
            筛选方案：控制器由页面 useTable 通过 provide 注入，页面无需接线。
            页面没用 useTable（如纯树表）时为 null，入口整体不渲染。
          -->
            <el-popover
              v-if="tableFilter"
              trigger="click"
              placement="bottom-end"
              :width="240"
              @show="refreshFilterPanel"
            >
              <template #reference>
                <!-- 这里同样不能套 el-tooltip：popover 与 tooltip 都是 popper trigger，会互抢 click -->
                <el-button text circle :icon="Filter" :title="t('table.filterScheme')" />
              </template>
              <div class="art-table-filter">
                <div class="art-table-filter-head">
                  <span>{{ t('table.filterScheme') }}</span>
                  <el-button link type="primary" size="small" :disabled="!filterHasSaved" @click="clearFilterSaved">
                    {{ t('table.clearMemory') }}
                  </el-button>
                </div>
                <div v-if="filterSchemes.length" class="art-table-filter-list">
                  <div v-for="name in filterSchemes" :key="name" class="art-table-filter-item">
                    <el-button link type="primary" @click="applyFilterScheme(name)">{{ name }}</el-button>
                    <el-button link type="danger" @click="removeFilterScheme(name)">{{ t('table.delete') }}</el-button>
                  </div>
                </div>
                <div v-else class="art-table-filter-empty">{{ t('table.noSavedScheme') }}</div>
                <div class="art-table-filter-save">
                  <el-input
                    v-model="filterName"
                    size="small"
                    :placeholder="t('table.schemeName')"
                    @keyup.enter="saveFilterScheme"
                  />
                  <el-button size="small" type="primary" :disabled="!filterName.trim()" @click="saveFilterScheme">
                    {{ t('table.save') }}
                  </el-button>
                </div>
              </div>
            </el-popover>
            <el-tooltip :content="t('table.refresh')" placement="top">
              <el-button text circle :icon="Refresh" :loading="loading" @click="emit('refresh')" />
            </el-tooltip>
            <!--
            密度（紧凑 / 默认 / 宽松）：一份设置同时决定表格行高与工具栏控件大小。
            放在工具栏而不是塞进「列设置」里 —— 它是整体外观，和「显示哪些列」不是一类事，
            而且调密度比调列频繁得多，值得一个一级入口。
          -->
            <el-dropdown trigger="click" placement="bottom-end" @command="onDensityChange">
              <el-button text circle :title="t('table.density')">
                <i class="ri-line-height" />
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="small" :class="{ 'art-table-density-on': effectiveSize === 'small' }">
                    {{ t('table.sizeCompact') }}
                  </el-dropdown-item>
                  <el-dropdown-item command="default" :class="{ 'art-table-density-on': effectiveSize === 'default' }">
                    {{ t('table.sizeDefault') }}
                  </el-dropdown-item>
                  <el-dropdown-item command="large" :class="{ 'art-table-density-on': effectiveSize === 'large' }">
                    {{ t('table.sizeLoose') }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
            <!-- 列设置改为弹窗：列多时弹窗比 popover 能放得下，且支持拖拽排序 -->
            <el-tooltip :content="t('table.columnSetting')" placement="top">
              <el-button text circle :icon="Setting" @click="openColumnDialog" />
            </el-tooltip>
          </div>
        </div>
      </el-config-provider>

      <div class="art-table-wrap">
        <!-- 虚拟滚动模式：仅当开启 virtual 且非树形/非回收站时生效，用 el-table-v2 + el-auto-resizer -->
        <el-auto-resizer v-if="useVirtual">
          <template #default="{ height, width }">
            <el-table-v2
              v-loading="loading"
              :columns="virtualColumns"
              :data="data"
              :width="width"
              :height="height"
              :row-key="rowKey"
              :header-height="44"
              :row-height="48"
            >
              <template #empty>
                <el-empty :description="t('table.empty')" :image-size="80" />
              </template>
            </el-table-v2>
          </template>
        </el-auto-resizer>

        <el-table
          v-else
          :key="columnKey"
          v-loading="loading"
          :data="data"
          :size="effectiveSize"
          :row-key="rowKey"
          :height="tableHeight"
          :default-expand-all="tree"
          :tree-props="{ children: 'children' }"
          :default-sort="defaultSort"
          :show-summary="showSummary"
          :summary-method="resolvedSummaryMethod"
          :sum-text="summaryText || t('table.summary')"
          stripe
          border
          highlight-current-row
          @selection-change="onSelectionChange"
          @sort-change="onSortChange"
          @header-dragend="onHeaderDragend"
        >
          <!--
            刻意不开 reserve-selection：勾选状态跨刷新保留会留下「勾着但已不在当前列表里」的
            脏状态（例如批量移动附件后），批量操作容易误伤看不见的行。
          -->
          <el-table-column v-if="selection" type="selection" width="46" />

          <el-table-column v-for="col in visibleColumns" :key="col.prop" v-bind="columnProps(col)">
            <!--
              列头筛选：统一用 #header 覆盖列头，渲染「标题 + 漏斗图标」，图标固定靠右。
              枚举列弹层里是复选下拉框，文本列是模糊查询输入框；真正的过滤都在后端做。
            -->
            <template v-if="col.filter" #header>
              <span class="art-table-head">
                <span class="art-table-head-label" :style="{ textAlign: headAlignOf(col) }">{{ col.label }}</span>
                <!--
                  日期范围的日历面板是双月并排 + 时间列，弹层要放宽才放得下，
                  其余类型（枚举 / 文本）保持窄面板。
                -->
                <el-popover
                  :width="col.filter?.type === 'date' ? 760 : 230"
                  trigger="click"
                  placement="bottom-start"
                  @show="syncFilterDraft(col)"
                >
                  <template #reference>
                    <el-icon
                      class="art-table-head-filter"
                      :class="{ 'is-active': isFilterActive(col) }"
                      :title="t('table.columnFilter')"
                      @click.stop
                    >
                      <Filter />
                    </el-icon>
                  </template>
                  <template #default="{ hide }">
                    <div class="art-table-head-panel">
                      <!--
                        枚举：复选下拉框。这里必须 teleported=false ——
                        下拉面板默认挂到 body，会被 el-popover 的「点击外部即关闭」判成外部点击，
                        勾第一个选项弹层就没了；留在弹层内部才能连续多选。
                      -->
                      <el-select
                        v-if="col.filter?.type === 'enum'"
                        v-model="enumDraft[col.prop]"
                        size="small"
                        multiple
                        collapse-tags
                        collapse-tags-tooltip
                        clearable
                        :teleported="false"
                        :placeholder="t('table.filterAll')"
                      >
                        <el-option
                          v-for="opt in col.filter?.options ?? []"
                          :key="opt.value"
                          :label="opt.label"
                          :value="opt.value"
                        />
                      </el-select>
                      <!-- 日期：范围选择（起止写 start/end 两个字段） -->
                      <div v-else-if="col.filter?.type === 'date'" class="art-table-date-picker">
                        <!--
                          同样必须 teleported=false：日历面板默认挂到 body，
                          点面板会被 el-popover 判成「点击外部」而直接关掉弹层。
                        -->
                        <el-date-picker v-model="dateDraft[col.prop]" v-bind="datePickerProps" :teleported="false" />
                      </div>
                      <!-- 文本：模糊查询输入框 -->
                      <el-input
                        v-else
                        v-model="textDraft[col.prop]"
                        size="small"
                        clearable
                        :placeholder="col.filter?.placeholder || t('table.filterTextPlaceholder')"
                        @keyup.enter="applyFilter(col, hide)"
                      />
                      <!-- 规范：重置在左、筛选在右，均为按钮形式（筛选为主题色 primary） -->
                      <div class="art-table-head-actions">
                        <el-button size="small" @click="resetFilter(col, hide)">{{ t('table.reset') }}</el-button>
                        <el-button size="small" type="primary" @click="applyFilter(col, hide)">
                          {{ t('table.filterConfirm') }}
                        </el-button>
                      </div>
                    </div>
                  </template>
                </el-popover>
              </span>
            </template>

            <template v-if="col.slot" #default="scope">
              <slot
                :name="col.slot"
                :row="scope.row"
                :index="scope.$index"
                :value="(scope.row as Record<string, unknown>)[col.prop]"
              />
            </template>
          </el-table-column>

          <!-- 回收站模式：操作列由表格统一渲染，页面的「操作」列（slot=action）被换掉 -->
          <el-table-column v-if="recycle" :label="t('table.action')" width="170" fixed="right">
            <template #default="{ row }">
              <el-button v-auth="'cccms:recycle:restore'" link type="primary" @click="emit('restore', row)">
                {{ t('table.restore') }}
              </el-button>
              <el-button v-auth="'cccms:recycle:delete'" link type="danger" @click="emit('force-delete', row)">
                {{ t('table.forceDelete') }}
              </el-button>
            </template>
          </el-table-column>

          <template #empty>
            <el-empty :description="t('table.empty')" :image-size="80" />
          </template>
        </el-table>
      </div>

      <div v-if="pagination" class="art-table-pager">
        <div class="art-table-pager-left">
          <slot name="pager-left" />
        </div>
        <el-pagination
          :small="effectiveButtonSize === 'small'"
          :current-page="page"
          :page-size="limit"
          :total="total"
          :page-sizes="pageSizes"
          :layout="pagerLayout"
          background
          @current-change="onCurrentChange"
          @size-change="onSizeChange"
        />
      </div>
    </el-card>

    <!--
      列设置弹窗：所有改动先落在草稿里，点「确定」才写回并持久化，「取消」直接丢弃。
      列表用原生 HTML5 拖拽排序（项目未引入 sortablejs，不值得为一个低频弹窗加依赖）。
    -->
    <el-dialog
      v-model="columnDialogVisible"
      :title="t('table.columnSetting')"
      width="600px"
      append-to-body
      class="art-column-dialog"
    >
      <div class="art-column-panel">
        <div class="art-column-row art-column-row--head">
          <span class="art-column-drag" />
          <span class="art-column-name">{{ t('table.columnName') }}</span>
          <span class="art-column-cell">{{ t('table.columnVisible') }}</span>
          <span class="art-column-cell">
            {{ t('table.columnSummary') }}（{{ summaryDraftCount }}/{{ dialogColumns.length }}）
          </span>
          <button
            type="button"
            class="art-column-clear-all"
            :disabled="summaryDraftCount === 0"
            :title="t('table.clearAllSummary')"
            @click="clearAllDraftSummary"
          >
            {{ t('table.columnClear') }}
          </button>
        </div>
        <div class="art-column-list">
          <div
            v-for="col in dialogColumns"
            :key="col.prop"
            class="art-column-row"
            :class="{
              'is-dragging': draggingProp === col.prop,
              'is-drop-target': dragOverProp === col.prop,
              'is-locked': col.lockVisible,
            }"
            :draggable="!col.lockVisible"
            @dragstart="onColumnDragStart(col, $event)"
            @dragover="onColumnDragOver(col, $event)"
            @dragleave="onColumnDragLeave(col, $event)"
            @drop="onColumnDrop(col, $event)"
            @dragend="onColumnDragEnd"
          >
            <i
              class="art-column-drag"
              :class="col.lockVisible ? 'ri-lock-2-line' : 'ri-draggable'"
              :title="col.lockVisible ? t('table.columnLocked') : t('table.columnDragHint')"
            />
            <span class="art-column-name" :title="col.label">{{ col.label }}</span>
            <span class="art-column-cell">
              <el-checkbox
                :model-value="!draftHidden.includes(col.prop)"
                :disabled="col.lockVisible"
                @change="(checked: string | number | boolean) => toggleDraftVisible(col.prop, Boolean(checked))"
              />
            </span>
            <span class="art-column-cell">
              <el-checkbox
                :model-value="!draftSummaryHidden.includes(col.prop)"
                :disabled="!summaryEnabled"
                :title="summaryEnabled ? '' : t('table.summaryUnavailable')"
                @change="(checked: string | number | boolean) => toggleDraftSummary(col.prop, Boolean(checked))"
              />
            </span>
            <button
              type="button"
              class="art-column-clear"
              :disabled="!summaryEnabled || draftSummaryHidden.includes(col.prop)"
              :title="t('table.clearSummary')"
              @click="clearDraftSummary(col.prop)"
            >
              <el-icon><Close /></el-icon>
            </button>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="art-column-footer">
          <el-button link type="primary" @click="resetColumnDraft">{{ t('table.reset') }}</el-button>
          <div class="art-column-footer-actions">
            <el-button @click="columnDialogVisible = false">{{ t('common.cancel') }}</el-button>
            <el-button type="primary" @click="applyColumnSetting">{{ t('common.confirm') }}</el-button>
          </div>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, h, inject, reactive, ref, useSlots, watch } from 'vue'
import type { VNode } from 'vue'
import { useRoute } from 'vue-router'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { ElCheckbox, ElMessage } from 'element-plus'
import {
  ArrowDown,
  ArrowUp,
  Close,
  Delete,
  Filter,
  Refresh,
  RefreshLeft,
  Search,
  Setting,
} from '@element-plus/icons-vue'
import { TABLE_FILTER_KEY } from '@/composables/useTable'
import { densityPreference, setDensityPreference, type TableSize } from '@/composables/useTableSize'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

const props = withDefaults(
  defineProps<{
    columns: ArtTableColumn[]
    data: Record<string, any>[]
    loading?: boolean
    total?: number
    rowKey?: string
    /**
     * 表格高度：
     *   - `'100%'`（默认）撑满父容器，表格**内部**滚动 —— 仅当父容器高度确定时可用
     *     （即页面根节点用 `.art-fill`，如账户授权 / 员工管理 / 站内消息）；
     *   - 数字（px）固定高度；
     *   - `'auto'` 按内容自然铺开，由**页面整体滚动** —— 页面根节点是 `.art-scroll`
     *     （或表格被放在 `el-row / el-col / el-card` 里）时必须用它，
     *     否则 el-table 的高度反馈循环会让页面无限变高（见下方 tableHeight 的说明）。
     */
    height?: number | string | 'auto'
    /**
     * 表格本体的**默认**尺寸（`large` / `default` / `small`），透传给 `el-table`。
     *
     * 它是**回退值**：用户在工具栏「密度」下拉里选过之后会覆盖它
     * （偏好优先于 prop —— 那是用户主动设的，不该被页面的默认值顶掉）。
     * 两者都没有时才走 Element Plus 的全局尺寸配置。
     *
     * 显式声明这个 prop 是必要的：未声明的属性只会 fallthrough 到根 div，
     * 传 `size` 会静默失效（内部 el-table 收不到）。
     * 注意：`virtual`（虚拟滚动）走的是 `el-table-v2`，它本身没有 `size` 属性，那种模式下不生效。
     */
    size?: 'large' | 'default' | 'small'
    /**
     * 工具栏 / 搜索区控件的**默认**尺寸（与 `size` 分开，因为两者可以各自声明默认档位）。
     *
     * 作用范围：ArtTable 自己渲染的控件，以及**页面通过 `#toolbar` / `#toolbar-right` /
     * `#search-extra` 插槽传进来的按钮** —— 后者靠 `el-config-provider` 向下传递才能覆盖到
     * （插槽内容在父组件作用域里创建，props 直接控制不到）。
     * 分页也会跟随（`small` 档位映射为分页的 `small` 属性）。
     *
     * 与 `size` 一样是**回退值**：工具栏「密度」下拉的全局偏好会覆盖它。
     */
    buttonSize?: 'large' | 'default' | 'small'
    pageSizes?: number[]
    /** 是否显示多选列 */
    selection?: boolean
    /** 是否显示分页（树表等场景关掉） */
    pagination?: boolean
    /** 树形表格：开启后按 children 展开 */
    tree?: boolean
    /** 列显隐持久化的标识，默认按当前路由区分 */
    storageKey?: string
    /**
     * 回收站模式：数据由父页面换成「已删除」（后端 trashed=1），
     * 操作列统一换成「还原 / 彻底删除」，页面自带的 `slot: 'action'` 列与工具栏按钮不渲染。
     */
    recycle?: boolean
    /**
     * 大表格虚拟滚动：切换为 `el-table-v2`，只渲染可视区域内的行。
     * 仅对「非树形、非回收站」的普通列表生效，其余场景自动回退普通 `el-table`（行为完全不变）。
     * 适合单页行数达到数千以上的列表；普通分页列表无需开启。
     */
    virtual?: boolean
    /**
     * 初始排序（配合列的 `sortable: 'custom'` 做服务端排序）。
     *
     * 只决定**表头箭头的初始状态**，数据顺序完全由接口决定 —— 前端不排序，
     * 因为分页在服务端，前端只能对当前页排序（看着像对的，翻页就露馅）。
     * 表头列变化时表格会重新挂载（见 el-table 的 `columnKey`），所以切层级后能重新生效。
     */
    defaultSort?: { prop: string; order: 'ascending' | 'descending' }
    /**
     * 底部汇总行：返回每个单元格要显示的内容（与 el-table 的 summary-method 同签名）。
     *
     * **汇总数据必须来自后端**：分页在服务端，前端只能统计当前页 —— 那是个「看着对」的错数。
     * 不传就不渲染汇总行（后端拿不到全量汇总时正好用它关掉）。
     */
    summaryMethod?: (param: { columns: any[]; data: any[] }) => string[]
    /**
     * 底部汇总行的**按列取值**（更省事的写法，与 `summaryMethod` 二选一）。
     *
     * 键是列的 `prop`，值是后端算好的汇总值（前端只负责展示，不做统计 ——
     * 分页在服务端，只统计当前页会得到「看着对」的错数）。
     *
     * 用户在「列设置」里取消勾选某列的「汇总展示」后，该列单元格留空；
     * 一列都没勾时整个汇总行不渲染。第一个单元格仍由 `sum-text` 占据。
     */
    summaryData?: Record<string, string | number>
    /** 汇总行第一个单元格的文案 */
    summaryText?: string
    /**
     * 默认隐藏的列（列多到铺不下时用它给一组「核心列」）。
     *
     * 仅在用户**从未调整过**本页列显隐时生效：一旦用户勾过列设置，
     * localStorage 里的记录优先（尊重用户意图，不被页面默认值反复覆盖）。
     */
    defaultHidden?: string[]
  }>(),
  {
    loading: false,
    total: 0,
    rowKey: 'id',
    height: '100%',
    pageSizes: () => [10, 15, 30, 50, 100],
    selection: false,
    pagination: true,
    tree: false,
    storageKey: '',
    recycle: false,
    virtual: false,
    summaryMethod: undefined,
    summaryData: undefined,
    summaryText: '',
    defaultHidden: () => [],
  },
)

/**
 * 实际生效的密度档位（表格本体）。
 *
 * 优先级：**用户偏好 > 页面的 size prop > 'default'**。
 * 偏好排在 prop 之前是有意的 —— 它是用户主动选的，
 * 若让页面的默认值盖过它，就会出现「我明明设了紧凑，一进某个页面又变回去」。
 * 末位的 'default' 等价于 Element Plus 的全局尺寸配置。
 */
const effectiveSize = computed<TableSize>(() => densityPreference.value || props.size || 'default')

/**
 * 传给 `el-table` 的 height：`'auto'` 时传 undefined（不设固定高度，表格按内容自然铺开）。
 *
 * 【为什么必须有这个分支】`'100%'` 只在**父容器高度确定**时成立（`.art-fill` 的 flex 链：
 * `.layout-content-inner` → `.art-fill` → `.art-table` 一路都是确定高度）。
 * 一旦父容器的高度是由内容撑开的 —— 典型是「`.art-scroll` 页面里把表格放进
 * `el-row > el-col > el-card`」—— el-table 会陷入反馈循环：
 * 量到内容高度 `offsetHeight` → 触发 `doLayout()` 并回灌 `bodyScrollHeight` → 表格更高 → 再量…
 * 表现就是**页面竖向滚动条持续增长（高度无限膨胀）**，`/oceanengine/material` 就踩过这个坑，
 * 同时控制台反复刷 `ResizeObserver loop completed with undelivered notifications`。
 *
 * 判定规则很简单：**表格要在容器内部滚动（撑满一屏）→ 用 `.art-fill` + 默认 `'100%'`；
 * 表格让页面整体滚动 → 传 `'auto'`**。两者混用就会出现上面那个循环。
 */
const tableHeight = computed(() => (props.height === 'auto' ? undefined : props.height))

/**
 * 实际生效的控件尺寸（工具栏 / 搜索区，含页面插槽传进来的按钮）。
 *
 * 与 `effectiveSize` **共用同一份密度偏好** —— 用户要的是「这一屏挤不挤」，
 * 而不是分别调表格和按钮。各自的 prop 仍作为回退，方便页面声明自己的默认档位。
 */
const effectiveButtonSize = computed<TableSize>(() => densityPreference.value || props.buttonSize || 'default')

/** 密度下拉的选择：三个档位直接落库 */
function onDensityChange(command: string | number | object): void {
  setDensityPreference(String(command) as TableSize)
}

const emit = defineEmits<{
  refresh: []
  'page-change': [value: number]
  'size-change': [value: number]
  'sort-change': [value: { prop: string; order: string | null }]
  'selection-change': [rows: any[]]
  /** 还原：不传 row = 还原当前勾选 */
  restore: [row?: Record<string, any>]
  /** 彻底删除：不传 row = 彻底删除当前勾选 */
  'force-delete': [row?: Record<string, any>]
}>()

/** 分页由父页面双向绑定，方便 useTable 直接复用；无分页表格可不传 */
const page = defineModel<number>('page', { default: 1 })
const limit = defineModel<number>('limit', { default: 15 })

const slots = useSlots()
const route = useRoute()

/** 当前勾选行（仅用于回收站模式的批量按钮） */
const selected = ref<any[]>([])

/* ---- 筛选方案（控制器由页面 useTable provide，页面零配置） ---- */
const tableFilter = inject(TABLE_FILTER_KEY, null)
const filterSchemes = ref<string[]>([])
const filterName = ref('')
const filterHasSaved = ref(false)

function refreshFilterPanel(): void {
  filterSchemes.value = tableFilter?.listSchemes() ?? []
  filterHasSaved.value = tableFilter?.hasSaved() ?? false
}

function saveFilterScheme(): void {
  const name = filterName.value.trim()
  if (!tableFilter || !name) {
    return
  }
  tableFilter.saveScheme(name)
  filterName.value = ''
  refreshFilterPanel()
  ElMessage.success(t('table.savedScheme', { name }))
}

function applyFilterScheme(name: string): void {
  tableFilter?.applyScheme(name)
  refreshFilterPanel()
}

function removeFilterScheme(name: string): void {
  tableFilter?.removeScheme(name)
  refreshFilterPanel()
}

function clearFilterSaved(): void {
  tableFilter?.clearSaved()
  refreshFilterPanel()
  ElMessage.success(t('table.clearedMemory'))
}

const isNarrow = useMediaQuery('(max-width: 768px)')
const pagerLayout = computed(() => (isNarrow.value ? 'prev, pager, next' : 'total, sizes, prev, pager, next, jumper'))

/* ---- 列头筛选 ---- */
/**
 * 筛选值统一存在页面 useTable 的 query 里（`writeFilter` 会落库到筛选方案并触发重新查询），
 * 所以这里只做「读取 → 渲染」与「交互 → 写回」，不自己持有筛选状态。
 * 草稿（`textDraft` / `enumDraft`）只在弹层内有效，点「筛选」才写回。
 */
const textDraft = reactive<Record<string, string>>({})
const enumDraft = reactive<Record<string, (string | number)[]>>({})
/** 日期范围草稿：`[开始, 结束]`，未选时为 null */
const dateDraft = reactive<Record<string, [string, string] | null>>({})

/* ---- 日期范围选择器的公共配置（顶部搜索区与列头弹层共用） ---- */

/**
 * 常用时间范围快捷项：今天 / 昨天 / 最近 3·7·30 天 / 本月 / 上月。
 * 返回 Date 数组，由 el-date-picker 按 `value-format` 统一格式化。
 */
const dateShortcuts = computed(() => {
  const startOfDay = (d: Date): Date => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0)
  const endOfDay = (d: Date): Date => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59)
  const shiftDays = (d: Date, days: number): Date => {
    const next = new Date(d)
    next.setDate(next.getDate() + days)
    return next
  }
  const now = new Date()

  return [
    { text: t('table.timeToday'), value: (): [Date, Date] => [startOfDay(now), endOfDay(now)] },
    {
      text: t('table.timeYesterday'),
      value: (): [Date, Date] => {
        const day = shiftDays(now, -1)
        return [startOfDay(day), endOfDay(day)]
      },
    },
    { text: t('table.timeLast3Days'), value: (): [Date, Date] => [startOfDay(shiftDays(now, -2)), endOfDay(now)] },
    { text: t('table.timeLast7Days'), value: (): [Date, Date] => [startOfDay(shiftDays(now, -6)), endOfDay(now)] },
    { text: t('table.timeLast30Days'), value: (): [Date, Date] => [startOfDay(shiftDays(now, -29)), endOfDay(now)] },
    {
      text: t('table.timeThisMonth'),
      value: (): [Date, Date] => [new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0), endOfDay(now)],
    },
    {
      text: t('table.timeLastMonth'),
      value: (): [Date, Date] => [
        new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0),
        // 上月最后一天：下月第 0 天即本月 1 号前一天
        new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59),
      ],
    },
  ]
})

/**
 * 时间范围筛选统一带时分秒（`YYYY-MM-DD HH:mm:ss`），后端按字符串直接比较，
 * 因此「按天筛选」由快捷项补足 00:00:00 ~ 23:59:59，手动选择也默认落在当天边界。
 */
const datePickerProps = computed(() => ({
  type: 'datetimerange' as const,
  rangeSeparator: '~',
  startPlaceholder: t('table.filterStart'),
  endPlaceholder: t('table.filterEnd'),
  valueFormat: 'YYYY-MM-DD HH:mm:ss',
  defaultTime: [new Date(2000, 1, 1, 0, 0, 0), new Date(2000, 1, 1, 23, 59, 59)] as [Date, Date],
  shortcuts: dateShortcuts.value,
  clearable: true,
}))

/** 列筛选写入 query 的字段名：默认与列 prop 同名 */
function filterKeyOf(col: ArtTableColumn): string {
  return col.filter?.queryKey ?? col.prop
}

/** 日期范围的起止字段名（时间列筛选固定写 start / end） */
function startKeyOf(col: ArtTableColumn): string {
  return col.filter?.startKey ?? 'start'
}

function endKeyOf(col: ArtTableColumn): string {
  return col.filter?.endKey ?? 'end'
}

/** 读取某个 query 字段并统一成字符串 */
function queryValueOf(key: string): string {
  const raw = tableFilter?.readFilter(key)
  return raw === undefined || raw === null ? '' : String(raw)
}

/** 当前筛选值（统一成字符串，枚举多值按逗号拼接） */
function filterValueOf(col: ArtTableColumn): string {
  return queryValueOf(filterKeyOf(col))
}

/** 日期范围当前值：两端都空时返回 null，交给 el-date-picker 显示占位 */
function dateRangeOf(col: ArtTableColumn): [string, string] | null {
  const start = queryValueOf(startKeyOf(col))
  const end = queryValueOf(endKeyOf(col))
  return start === '' && end === '' ? null : [start, end]
}

function isFilterActive(col: ArtTableColumn): boolean {
  if (col.filter?.type === 'date') {
    return dateRangeOf(col) !== null
  }
  return filterValueOf(col) !== ''
}

/** 表头对齐：跟随列的 align，保证「标题居中 / 靠右」时漏斗图标依然贴右 */
function headAlignOf(col: ArtTableColumn): 'left' | 'center' | 'right' {
  return col.align ?? 'left'
}

/** 打开弹层时把草稿对齐到当前条件，避免显示上一次的输入 */
function syncFilterDraft(col: ArtTableColumn): void {
  if (col.filter?.type === 'enum') {
    enumDraft[col.prop] = enumFilteredValue(col)
  } else if (col.filter?.type === 'date') {
    dateDraft[col.prop] = dateRangeOf(col)
  } else {
    textDraft[col.prop] = filterValueOf(col)
  }
}

/** 提交本列筛选并收起弹层：枚举多值按逗号拼接，文本去空格，日期范围批量写起止字段 */
function applyFilter(col: ArtTableColumn, close: () => void): void {
  if (col.filter?.type === 'enum') {
    const list = enumDraft[col.prop] ?? []
    tableFilter?.writeFilter(filterKeyOf(col), list.length ? list.join(',') : '')
  } else if (col.filter?.type === 'date') {
    const range = dateDraft[col.prop]
    tableFilter?.writeFilters({
      [startKeyOf(col)]: range?.[0] ?? '',
      [endKeyOf(col)]: range?.[1] ?? '',
    })
  } else {
    tableFilter?.writeFilter(filterKeyOf(col), (textDraft[col.prop] ?? '').trim())
  }
  close()
}

/** 重置本列筛选（清空该列的查询条件）并收起弹层 */
function resetFilter(col: ArtTableColumn, close: () => void): void {
  if (col.filter?.type === 'enum') {
    enumDraft[col.prop] = []
  } else if (col.filter?.type === 'date') {
    dateDraft[col.prop] = null
  } else {
    textDraft[col.prop] = ''
  }
  // 日期范围涉及两个字段：统一走批量写入，避免触发两次重新查询
  if (col.filter?.type === 'date') {
    tableFilter?.writeFilters({ [startKeyOf(col)]: '', [endKeyOf(col)]: '' })
  } else {
    tableFilter?.writeFilter(filterKeyOf(col), '')
  }
  close()
}

/** 枚举多选：把逗号拼接的值还原成选项里的原始值，否则勾选态对不上（数字 vs 字符串） */
function enumFilteredValue(col: ArtTableColumn): (string | number)[] {
  const raw = filterValueOf(col)
  if (!raw) {
    return []
  }
  const options = col.filter?.options ?? []
  return raw.split(',').map((part) => options.find((o) => String(o.value) === part)?.value ?? part)
}

/** 预置草稿，避免 el-select 拿到 undefined（列变化时补建，不覆盖已有输入） */
function ensureFilterDrafts(): void {
  for (const col of ownColumns.value) {
    if (col.filter?.type === 'enum') {
      enumDraft[col.prop] ??= enumFilteredValue(col)
    } else if (col.filter?.type === 'date') {
      dateDraft[col.prop] ??= dateRangeOf(col)
    } else if (col.filter) {
      textDraft[col.prop] ??= filterValueOf(col)
    }
  }
}

/**
 * 页面声明的列：回收站模式下「操作」列（约定 `slot='action'`）由表格统一接管，故剔除。
 * 列设置面板与筛选草稿预置用的也是它 —— 否则会留下一个「勾了也不显示」的僵尸项。
 */
const ownColumns = computed(() => (props.recycle ? props.columns.filter((c) => c.slot !== 'action') : props.columns))

// 列声明后立刻预置筛选草稿（声明顺序上必须在 ownColumns 之后）
watch(ownColumns, ensureFilterDrafts, { immediate: true })

/* ---- 顶部搜索区（按列的 filter 配置自动生成） ---- */
/** 折叠时保留的搜索项数量；超出部分点「展开」才显示 */
const SEARCH_COLLAPSE_LIMIT = 3

const searchExpanded = ref(false)

/** 参与顶部搜索的列：声明了 filter 的列即搜索项，列变了搜索项跟着变 */
const searchColumns = computed(() => ownColumns.value.filter((col) => col.filter))

/**
 * 顶部搜索草稿：与列头筛选的草稿分开存，输入过程中不触发请求，
 * 点「查询」才把全部字段一次性写回 query（只重新查询一次）。
 */
const searchText = reactive<Record<string, string>>({})
const searchEnum = reactive<Record<string, (string | number)[]>>({})
/** 日期范围草稿：`[开始, 结束]`，未选时为 null */
const searchDate = reactive<Record<string, [string, string] | null>>({})

/** 当前筛选条件的指纹：query 或列声明变化时重新对齐草稿 */
const searchSignature = computed(() =>
  searchColumns.value
    .map((col) =>
      col.filter?.type === 'date'
        ? `${queryValueOf(startKeyOf(col))}~${queryValueOf(endKeyOf(col))}`
        : filterValueOf(col),
    )
    .join('|'),
)

/** 把草稿对齐到当前 query —— 列头筛选、筛选方案、查询/重置后都会走这里 */
function syncSearchDraft(): void {
  for (const col of searchColumns.value) {
    if (col.filter?.type === 'enum') {
      searchEnum[col.prop] = enumFilteredValue(col)
    } else if (col.filter?.type === 'date') {
      searchDate[col.prop] = dateRangeOf(col)
    } else {
      searchText[col.prop] = filterValueOf(col)
    }
  }
}

// 声明顺序上必须在 searchColumns / query 之后
watch(searchSignature, syncSearchDraft, { immediate: true })

/** 提交顶部搜索：所有筛选字段一次性写回 query */
function submitSearch(): void {
  const fields: Record<string, unknown> = {}
  for (const col of searchColumns.value) {
    if (col.filter?.type === 'enum') {
      const list = searchEnum[col.prop] ?? []
      fields[filterKeyOf(col)] = list.length ? list.join(',') : ''
    } else if (col.filter?.type === 'date') {
      const range = searchDate[col.prop]
      fields[startKeyOf(col)] = range?.[0] ?? ''
      fields[endKeyOf(col)] = range?.[1] ?? ''
    } else {
      fields[filterKeyOf(col)] = (searchText[col.prop] ?? '').trim()
    }
  }
  tableFilter?.writeFilters(fields)
}

/** 重置顶部搜索：清空全部筛选字段（列头筛选的勾选与高亮同步清掉） */
function resetSearch(): void {
  const fields: Record<string, unknown> = {}
  for (const col of searchColumns.value) {
    if (col.filter?.type === 'date') {
      fields[startKeyOf(col)] = ''
      fields[endKeyOf(col)] = ''
    } else {
      fields[filterKeyOf(col)] = ''
    }
  }
  tableFilter?.writeFilters(fields)
  syncSearchDraft()
}

/* ---- 列设置：显隐 / 排序 / 汇总展示（均按页面持久化） ---- */
// 前缀带版本号：旧版本存过「默认隐藏某列」的记录，换前缀可让老缓存一次性失效
const HIDDEN_PREFIX = 'cccms_table_hidden:v2:'
const ORDER_PREFIX = 'cccms_table_order:v1:'
const SUMMARY_PREFIX = 'cccms_table_summary:v1:'

function storageId(): string {
  return props.storageKey || route.path
}

/**
 * 读一份列设置记录；`null` 表示「用户从未设置过」。
 *
 * 必须区分「没存过」和「存的是空数组」：后者是用户的真实选择（例如全部显示 / 全部不汇总），
 * 若按「空即未设置」处理，用户每次刷新都会被打回页面默认值。
 */
function readStoredList(prefix: string): string[] | null {
  try {
    const raw = localStorage.getItem(prefix + storageId())
    if (raw === null) {
      return null
    }
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : null
  } catch {
    // 解析异常时回落到默认值
    return null
  }
}

function writeStoredList(prefix: string, value: string[]): void {
  try {
    localStorage.setItem(prefix + storageId(), JSON.stringify(value))
  } catch {
    // localStorage 不可用时忽略
  }
}

/** 隐藏列：页面声明了「默认隐藏」时以它为准；用户动过列设置后，本地记录优先 */
const hiddenColumns = ref<string[]>(readStoredList(HIDDEN_PREFIX) ?? [...props.defaultHidden])
/** 拖拽排序后的列顺序（只存 prop，页面后续新增的列自动追加到末尾） */
const columnOrder = ref<string[]>(readStoredList(ORDER_PREFIX) ?? [])
/** 汇总行里**不展示**的列（空数组 = 全部展示，列设置里勾掉哪列就记哪列） */
const summaryHiddenColumns = ref<string[]>(readStoredList(SUMMARY_PREFIX) ?? [])

watch(
  () => props.columns,
  () => {
    hiddenColumns.value = readStoredList(HIDDEN_PREFIX) ?? [...props.defaultHidden]
    columnOrder.value = readStoredList(ORDER_PREFIX) ?? []
    summaryHiddenColumns.value = readStoredList(SUMMARY_PREFIX) ?? []
  },
)

function persistHidden(): void {
  writeStoredList(HIDDEN_PREFIX, hiddenColumns.value)
}

function persistOrder(): void {
  writeStoredList(ORDER_PREFIX, columnOrder.value)
}

function persistSummary(): void {
  writeStoredList(SUMMARY_PREFIX, summaryHiddenColumns.value)
}

/** prop → 列定义：弹窗与排序都按 prop 索引，列被页面删掉后不会留下僵尸行 */
const columnMap = computed(() => new Map(ownColumns.value.map((col) => [col.prop, col])))

/**
 * 锁定列（操作列等）固定在末尾：`fixed: 'right'` 要求必须是最后几列，
 * 否则用户把别的列拖到它后面，固定列的布局会错位。
 */
function orderLockedLast(list: string[]): string[] {
  const normal: string[] = []
  const locked: string[] = []
  for (const prop of list) {
    if (columnMap.value.get(prop)?.lockVisible) {
      locked.push(prop)
    } else {
      normal.push(prop)
    }
  }
  return [...normal, ...locked]
}

/**
 * 按用户拖拽的顺序重排；未记录过的新列按声明顺序追加到末尾。
 * `sort` 在现代引擎里是稳定排序，所以同一权重内仍保持页面声明顺序。
 */
const orderedColumns = computed(() => {
  const weight = new Map(columnOrder.value.map((prop, index) => [prop, index]))
  const missing = Number.MAX_SAFE_INTEGER
  const sorted = [...ownColumns.value].sort((a, b) => (weight.get(a.prop) ?? missing) - (weight.get(b.prop) ?? missing))
  const byProp = new Map(sorted.map((col) => [col.prop, col]))
  return orderLockedLast(sorted.map((col) => col.prop))
    .map((prop) => byProp.get(prop))
    .filter((col): col is ArtTableColumn => col !== undefined)
})

/* ---- 列设置弹窗（草稿态：确定才生效，取消即丢弃） ---- */
const columnDialogVisible = ref(false)
/** 草稿顺序：弹窗里拖拽后的 prop 列表 */
const draftOrder = ref<string[]>([])
const draftHidden = ref<string[]>([])
const draftSummaryHidden = ref<string[]>([])
/** 草稿里点过「重置」：点确定时连列宽一起恢复默认 */
const draftClearWidths = ref(false)

/** 弹窗列表：按草稿顺序映射回列定义 */
const dialogColumns = computed(() =>
  draftOrder.value.map((prop) => columnMap.value.get(prop)).filter((col): col is ArtTableColumn => col !== undefined),
)

/** 汇总展示已勾选数量（表头显示「汇总展示（6/24）」） */
const summaryDraftCount = computed(
  () => dialogColumns.value.filter((col) => !draftSummaryHidden.value.includes(col.prop)).length,
)

function openColumnDialog(): void {
  draftOrder.value = orderedColumns.value.map((col) => col.prop)
  draftHidden.value = [...hiddenColumns.value]
  draftSummaryHidden.value = [...summaryHiddenColumns.value]
  draftClearWidths.value = false
  columnDialogVisible.value = true
}

/** 草稿里只保留当前仍存在的列，避免页面改列后把历史脏数据写回 */
function sanitizeDraftList(list: string[]): string[] {
  return list.filter((prop) => columnMap.value.has(prop))
}

function toggleDraftVisible(prop: string, visible: boolean): void {
  const next = new Set(draftHidden.value)
  if (visible) {
    next.delete(prop)
  } else {
    next.add(prop)
  }
  draftHidden.value = [...next]
}

function toggleDraftSummary(prop: string, selected: boolean): void {
  const next = new Set(draftSummaryHidden.value)
  if (selected) {
    next.delete(prop)
  } else {
    next.add(prop)
  }
  draftSummaryHidden.value = [...next]
}

/** 行尾「清空」：取消该列的汇总展示 */
function clearDraftSummary(prop: string): void {
  toggleDraftSummary(prop, false)
}

/** 表头「清空」：一次清掉全部列的汇总展示 */
function clearAllDraftSummary(): void {
  draftSummaryHidden.value = dialogColumns.value.map((col) => col.prop)
}

/** 重置：回到页面声明顺序 + 全部显示 + 全部汇总，并标记确定时清掉列宽 */
function resetColumnDraft(): void {
  draftOrder.value = ownColumns.value.map((col) => col.prop)
  draftHidden.value = []
  draftSummaryHidden.value = []
  draftClearWidths.value = true
}

function applyColumnSetting(): void {
  columnOrder.value = orderLockedLast(sanitizeDraftList(draftOrder.value))
  persistOrder()

  // 锁定列（如操作列）永远可见：即使草稿里被关掉也不写回
  hiddenColumns.value = sanitizeDraftList(draftHidden.value).filter((prop) => !columnMap.value.get(prop)?.lockVisible)
  persistHidden()

  summaryHiddenColumns.value = sanitizeDraftList(draftSummaryHidden.value)
  persistSummary()

  if (draftClearWidths.value) {
    columnWidths.value = {}
    persistWidths()
  }
  draftClearWidths.value = false
  columnDialogVisible.value = false
}

/* ---- 弹窗内拖拽排序（原生 HTML5 DnD，不额外引依赖） ---- */
const draggingProp = ref('')
const dragOverProp = ref('')

function onColumnDragStart(col: ArtTableColumn, event: DragEvent): void {
  if (col.lockVisible) {
    event.preventDefault()
    return
  }
  draggingProp.value = col.prop
  dragOverProp.value = ''
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    // Firefox 必须写一次数据，否则拖拽不会启动
    event.dataTransfer.setData('text/plain', col.prop)
  }
}

function onColumnDragOver(col: ArtTableColumn, event: DragEvent): void {
  if (!draggingProp.value || draggingProp.value === col.prop) {
    return
  }
  event.preventDefault()
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move'
  }
  dragOverProp.value = col.prop
}

function onColumnDragLeave(col: ArtTableColumn, event: DragEvent): void {
  // dragleave 会从行内子元素上冒泡：指针还在本行里时不要清掉插入线，否则会闪
  const row = event.currentTarget as HTMLElement | null
  const next = event.relatedTarget as Node | null
  if (row && next && row.contains(next)) {
    return
  }
  if (dragOverProp.value === col.prop) {
    dragOverProp.value = ''
  }
}

function onColumnDrop(target: ArtTableColumn, event: DragEvent): void {
  event.preventDefault()
  const from = draggingProp.value
  onColumnDragEnd()
  if (!from || from === target.prop) {
    return
  }
  const list = [...draftOrder.value]
  const fromIndex = list.indexOf(from)
  const toIndex = list.indexOf(target.prop)
  if (fromIndex < 0 || toIndex < 0) {
    return
  }
  list.splice(fromIndex, 1)
  list.splice(toIndex, 0, from)
  draftOrder.value = orderLockedLast(list)
}

function onColumnDragEnd(): void {
  draggingProp.value = ''
  dragOverProp.value = ''
}

/* ---- 列宽记忆（拖拽表头后按页面持久化） ---- */
const WIDTH_PREFIX = 'cccms_table_width:'

function loadWidths(): Record<string, number> {
  try {
    const raw = localStorage.getItem(WIDTH_PREFIX + storageId())
    if (raw) {
      const parsed = JSON.parse(raw) as Record<string, number>
      return parsed && typeof parsed === 'object' ? parsed : {}
    }
  } catch {
    // 解析异常时回落为「无自定义列宽」
  }
  return {}
}

function persistWidths(): void {
  try {
    localStorage.setItem(WIDTH_PREFIX + storageId(), JSON.stringify(columnWidths.value))
  } catch {
    // localStorage 不可用时忽略
  }
}

const columnWidths = ref<Record<string, number>>(loadWidths())

watch(
  () => props.columns,
  () => {
    columnWidths.value = loadWidths()
  },
)

/** el-table 的 header-dragend：column.property 即列的 prop */
function onHeaderDragend(newWidth: number, _oldWidth: number, column: { property?: string }): void {
  const prop = column?.property
  if (!prop) {
    return
  }
  columnWidths.value = { ...columnWidths.value, [prop]: Math.round(newWidth) }
  persistWidths()
}

const visibleColumns = computed(() =>
  orderedColumns.value.filter((c) => c.lockVisible || !hiddenColumns.value.includes(c.prop)),
)

/**
 * 可见列集合的指纹，作为 el-table 的 key。
 *
 * el-table 的列是在子组件挂载时注册的，动态增删列在部分版本下不会重算列宽与布局，
 * 表现就是「勾了列设置但表格没变」。用 key 强制重挂载最稳妥，
 * 列设置属于低频操作，重挂载代价可以接受。
 * 指纹里带**顺序**，所以拖拽排序后也会重挂载，列宽与固定列重新计算。
 */
const columnKey = computed(() => visibleColumns.value.map((c) => c.prop).join('|'))

/* ---- 底部汇总行（按列的「汇总展示」开关过滤） ---- */
/** 页面提供了汇总来源（方法或按列取值）才谈得上展示汇总 */
const summaryEnabled = computed(() => props.summaryMethod !== undefined || props.summaryData !== undefined)

/** 一列都没勾「汇总展示」时整个汇总行收起，与「清空」的语义一致 */
const showSummary = computed(
  () => summaryEnabled.value && ownColumns.value.some((col) => !summaryHiddenColumns.value.includes(col.prop)),
)

/**
 * 包装页面的汇总来源，把未勾选「汇总展示」的列置空。
 *
 * 第一个单元格固定留空 —— el-table 会用 `sum-text` 覆盖它。
 * 汇总值仍由页面（后端）提供，组件只做「显示 / 不显示」的过滤。
 */
function resolvedSummaryMethod(param: { columns: any[]; data: any[] }): string[] {
  const base = props.summaryMethod?.(param) ?? []
  return param.columns.map((column, index) => {
    if (index === 0) {
      // 传了自定义 summaryMethod 时 el-table **不会**再用 sum-text，标签得由这里给
      return base[0] || props.summaryText || t('table.summary')
    }
    const prop = String(column?.property ?? '')
    if (!prop || summaryHiddenColumns.value.includes(prop)) {
      return ''
    }
    const value = props.summaryData?.[prop]
    if (value !== undefined) {
      return String(value)
    }
    return base[index] ?? ''
  })
}

function columnProps(col: ArtTableColumn): Record<string, unknown> {
  const out: Record<string, unknown> = {
    prop: col.prop,
    label: col.label,
    align: col.align ?? 'left',
    headerAlign: col.align ?? 'left',
    showOverflowTooltip: col.showOverflowTooltip ?? true,
  }
  // 拖拽过的列宽优先于页面声明的宽度（用户意图优先）
  const savedWidth = columnWidths.value[col.prop]
  if (savedWidth) {
    out.width = savedWidth
  } else if (col.width !== undefined) {
    out.width = col.width
  }
  if (col.minWidth !== undefined) {
    out.minWidth = col.minWidth
  }
  if (col.fixed !== undefined) {
    out.fixed = col.fixed
  }
  if (col.sortable !== undefined) {
    out.sortable = col.sortable
  }
  if (col.formatter !== undefined) {
    out.formatter = col.formatter
  }
  // 列筛选（枚举 / 文本）统一由 #header 插槽自绘，不使用 el-table 原生 filters
  return out
}

/* ---- 虚拟滚动（P2-15） ---- */

/** 树形 / 回收站场景不支持 el-table-v2，自动回退普通表格 */
const useVirtual = computed(() => props.virtual && !props.tree && !props.recycle)

const VIRTUAL_DEFAULT_WIDTH = 150

/** 虚拟模式下被勾选的行主键集合 */
const virtualSelectedKeys = ref<(string | number)[]>([])

function virtualRowKey(row: any): string | number {
  return row?.[props.rowKey] ?? row?.id
}

const virtualAllChecked = computed(
  () => props.data.length > 0 && virtualSelectedKeys.value.length === props.data.length,
)
const virtualIndeterminate = computed(
  () => virtualSelectedKeys.value.length > 0 && virtualSelectedKeys.value.length < props.data.length,
)

function syncVirtualSelection(): void {
  const rows = props.data.filter((row) => virtualSelectedKeys.value.includes(virtualRowKey(row)))
  // 复用普通模式的勾选状态与 selection-change 事件，页面无需区分实现
  selected.value = rows
  emit('selection-change', rows)
}

function toggleVirtualAll(checked: string | number | boolean | undefined): void {
  virtualSelectedKeys.value = checked ? props.data.map((row) => virtualRowKey(row)) : []
  syncVirtualSelection()
}

function toggleVirtualRow(row: any): void {
  const key = virtualRowKey(row)
  const idx = virtualSelectedKeys.value.indexOf(key)
  if (idx >= 0) {
    virtualSelectedKeys.value = virtualSelectedKeys.value.filter((k) => k !== key)
  } else {
    virtualSelectedKeys.value = [...virtualSelectedKeys.value, key]
  }
  syncVirtualSelection()
}

/** 把 ArtTable 的列契约映射成 el-table-v2 的 Column（width 必填，插槽用 cellRenderer 自绘） */
const virtualColumns = computed<Record<string, any>[]>(() => {
  const cols: Record<string, any>[] = []
  if (props.selection) {
    cols.push({
      key: '__selection',
      width: 46,
      align: 'center',
      headerCellRenderer: () =>
        h(ElCheckbox, {
          modelValue: virtualAllChecked.value,
          indeterminate: virtualIndeterminate.value,
          onChange: toggleVirtualAll,
        }),
      cellRenderer: ({ rowData }: { rowData: any }) =>
        h(ElCheckbox, {
          modelValue: virtualSelectedKeys.value.includes(virtualRowKey(rowData)),
          onChange: () => toggleVirtualRow(rowData),
        }),
    })
  }
  for (const col of visibleColumns.value) {
    let width = columnWidths.value[col.prop]
    if (!width && typeof col.width === 'number') {
      width = col.width
    }
    if (!width && typeof col.minWidth === 'number') {
      width = col.minWidth
    }
    if (!width) {
      width = VIRTUAL_DEFAULT_WIDTH
    }
    const item: Record<string, any> = {
      key: col.prop,
      dataKey: col.prop,
      title: col.label,
      width,
    }
    if (col.align) {
      item.align = col.align
    }
    if (col.fixed === true || col.fixed === 'left') {
      item.fixed = 'left'
    } else if (col.fixed === 'right') {
      item.fixed = 'right'
    }
    if (col.slot && slots[col.slot]) {
      const slotFn = slots[col.slot]!
      item.cellRenderer = ({
        rowData,
        rowIndex,
        cellData,
      }: {
        rowData: any
        rowIndex: number
        cellData: unknown
      }): VNode => h('div', slotFn({ row: rowData, index: rowIndex, value: cellData }))
    }
    cols.push(item)
  }
  return cols
})

/**
 * 翻页 / 刷新后剔除已不在当前数据里的勾选，避免批量操作误伤看不见的行
 * （与普通表格不开 reserve-selection 的语义保持一致）。
 */
watch(
  () => props.data,
  () => {
    if (!useVirtual.value || virtualSelectedKeys.value.length === 0) {
      return
    }
    const keys = props.data.map((row) => virtualRowKey(row))
    const next = virtualSelectedKeys.value.filter((key) => keys.includes(key))
    if (next.length !== virtualSelectedKeys.value.length) {
      virtualSelectedKeys.value = next
      syncVirtualSelection()
    }
  },
)

function onCurrentChange(value: number): void {
  page.value = value
  emit('page-change', value)
}

function onSizeChange(value: number): void {
  limit.value = value
  emit('size-change', value)
}

function onSelectionChange(rows: any[]): void {
  // 回收站模式的批量按钮需要知道勾了几条
  selected.value = rows
  emit('selection-change', rows)
}

function onSortChange(payload: { prop: string | null; order: string | null }): void {
  emit('sort-change', { prop: payload.prop ?? '', order: payload.order })
}
</script>

<style scoped>
.art-table {
  display: flex;
  /* flex:1 兼容被放进 flex 父容器（如 ArtSplitView 右栏）的场景，height:100% 兜底普通父容器 */
  flex: 1;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.art-table-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.art-table-main :deep(.el-card__body) {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  padding: 12px 16px 14px;
}

.art-table-toolbar {
  display: flex;
  flex-shrink: 0;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.art-table-toolbar-left {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.art-table-toolbar-right {
  display: flex;
  flex-shrink: 0;
  gap: 2px;
  align-items: center;
}

.art-table-wrap {
  flex: 1;
  min-height: 0;
}

/* ---- 搜索卡片（表格上方独立一张卡，折叠项用 v-show 隐藏，展开后换行铺开） ---- */
.art-table-search {
  flex-shrink: 0;
  margin-bottom: 10px;
}

.art-table-search :deep(.el-card__body) {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  /* 下方留少一点：表单项自身有 margin-bottom，合计约 12px */
  padding: 14px 16px 6px;
}

.art-table-search-form {
  flex: 1;
  min-width: 0;
}

/* el-form inline 的默认间距偏松，收紧一点 */
.art-table-search-form :deep(.el-form-item) {
  margin-right: 12px;
  margin-bottom: 8px;
}

.art-table-search-form :deep(.el-form-item__label) {
  font-size: 13px;
  color: var(--art-sub);
}

.art-table-search-form :deep(.el-input),
.art-table-search-form :deep(.el-select),
.art-table-search-form :deep(.el-date-editor) {
  width: 190px;
}

/* 日期范围要放下「起 ~ 止」两个日期，比普通输入框宽一点 */
.art-table-search-form :deep(.el-range-editor) {
  width: 400px;
}

.art-table-search-actions {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  align-items: center;
  /* 与第一个搜索项的控制区垂直居中对齐 */
  padding-top: 1px;
}

.art-table-search-arrow {
  margin-left: 2px;
}

.art-table-pager {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  padding-top: 12px;
}

.art-table-pager-left {
  font-size: 13px;
  color: var(--art-sub);
}

/* ---- 列设置弹窗（拖拽手柄 / 列名 / 展示列 / 汇总展示 / 清空） ---- */
.art-column-panel {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

/* 表头与数据行共用同一套网格列宽，保证四列严格对齐 */
.art-column-row {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) 72px 132px 48px;
  gap: 8px;
  align-items: center;
  padding: 6px 8px;
  border-radius: 6px;
}

.art-column-row--head {
  margin-bottom: 4px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-bottom: 1px solid var(--art-card-border);
  border-radius: 0;
}

.art-column-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 52vh;
  padding-right: 2px;
  overflow-y: auto;
}

.art-column-row:not(.art-column-row--head):hover {
  background: var(--el-fill-color-light);
}

/* 拖拽中的行半透明；落点行整行高亮（不画上下边线：向上/向下拖的插入位置本就不同） */
.art-column-row.is-dragging {
  opacity: 0.4;
}

.art-column-row.is-drop-target {
  background: var(--el-color-primary-light-9);
  box-shadow: inset 0 0 0 1px var(--el-color-primary-light-5);
}

.art-column-drag {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: var(--art-muted);
  cursor: grab;
}

.art-column-row.is-locked .art-column-drag {
  cursor: not-allowed;
}

.art-column-name {
  overflow: hidden;
  font-size: 13px;
  color: var(--art-main);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.art-column-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  line-height: 1.4;
  color: var(--art-sub);
  text-align: center;
}

/* el-checkbox 默认自带右外边距，会把勾选框顶偏 */
.art-column-cell :deep(.el-checkbox) {
  margin-right: 0;
}

/* 表头单元格跟随表头的正文色，不要被数据行的次要色压暗 */
.art-column-row--head .art-column-cell {
  color: var(--art-main);
}

/* 行尾「清空」与表头「清空」：纯文本按钮，避免再多一种按钮样式 */
.art-column-clear,
.art-column-clear-all {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  font: inherit;
  color: var(--art-muted);
  cursor: pointer;
  background: none;
  border: 0;
}

.art-column-clear-all {
  font-size: 13px;
  font-weight: 400;
  color: var(--el-color-primary);
}

.art-column-clear:hover:not(:disabled),
.art-column-clear-all:hover:not(:disabled) {
  color: var(--el-color-danger);
}

.art-column-clear:disabled,
.art-column-clear-all:disabled {
  color: var(--el-text-color-disabled);
  cursor: not-allowed;
}

.art-column-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.art-column-footer-actions {
  display: flex;
  gap: 8px;
}

/* 弹窗挂在 body 上，内部结构拿不到 scoped 作用域，用 :global 兜住 */
:global(.art-column-dialog) {
  max-width: calc(100vw - 32px);
}

:global(.art-column-dialog .el-dialog__body) {
  padding-top: 8px;
}

/* 密度下拉里当前生效的档位：加粗 + 主题色，一眼看出现在用的是哪档 */
.art-table-density-on {
  color: var(--el-color-primary);
  font-weight: 600;
}

.art-table-filter {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.art-table-filter-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-bottom: 1px solid var(--art-card-border);
}

.art-table-filter-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 200px;
  overflow-y: auto;
}

.art-table-filter-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.art-table-filter-item :deep(.el-button) {
  padding: 0;
}

.art-table-filter-empty {
  padding: 8px 0;
  font-size: 12px;
  color: var(--art-muted);
  text-align: center;
}

.art-table-filter-save {
  display: flex;
  gap: 6px;
}

/* ---- 列头筛选（枚举复选下拉 / 文本输入，统一弹层） ---- */
.art-table-head {
  display: flex;
  gap: 4px;
  align-items: center;
  width: 100%;
  min-width: 0;
}

/* 撑满剩余宽度：标题按列对齐方式排布，漏斗图标被顶到列头最右侧 */
.art-table-head-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.art-table-head-filter {
  flex-shrink: 0;
  font-size: 12px;
  color: var(--art-muted);
  cursor: pointer;
}

.art-table-head-filter:hover {
  color: var(--el-color-primary);
}

/* 有筛选条件时高亮，和 el-table 原生筛选图标的高亮语义保持一致 */
.art-table-head-filter.is-active {
  color: var(--el-color-primary);
}

.art-table-head-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* 日期范围：撑满弹层宽度（日历面板挂在这里，teleported=false） */
.art-table-date-picker :deep(.el-date-editor) {
  width: 100%;
}

/* 规范：重置在左、筛选在右，均为按钮形式（筛选为主题色 primary） */
.art-table-head-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
