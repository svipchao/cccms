/**
 * 代码生成器页。
 *
 * 与 `en-US/generator.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 * 数据表名 / 表注释等由用户数据或数据库带出，不在语言包内。
 */
export default {
  /* ---- 未启用（页面直接显示，不弹窗）---- */
  disabledEnvTitle: '仅限本地开发环境，已禁用代码生成',
  disabledEnvDesc:
    '{reason}。代码生成会直接写入 Model / Logic / Controller / 路由 / 前端页面，而线上是「已构建」形态，按仓库相对路径写文件只会写坏线上代码，因此这里连同生成操作一起拦截。',
  disabledSwitchTitle: '代码生成器已关闭',
  disabledSwitchDesc:
    '{reason}。这是总开关，默认关闭：本地开发需要时，在 server/.env 里加上 CCCMS_GENERATOR_ENABLE=true（或把 plugin/cccms/config/generator.php 的 enable 改为 true），然后 reload / 重启后端进程即可。',
  alertTitle: '代码生成器会直接写入磁盘文件并自动登记菜单',
  alertDesc: '已存在的文件默认不覆盖（可勾选「允许覆盖」）。生成后请执行 {cmd} 同步按钮节点。',
  configTitle: '生成配置',
  tableLabel: '数据表',
  tablePlaceholder: '请选择数据表',
  tableRequired: '请选择数据表',
  pluginLabel: '插件名',
  pluginPlaceholder: '如 cccms 或业务插件名',
  pluginRequired: '请输入插件名',
  moduleLabel: '模块名',
  modulePlaceholder: '留空则按表名推导',
  titleLabel: '模块标题',
  titlePlaceholder: '如 商品',
  titleRequired: '请输入模块标题',
  overwriteLabel: '覆盖文件',
  overwriteText: '允许覆盖已存在的文件',
  preview: '预览',
  generate: '生成代码',
  fieldsTitle: '字段（{count}）',
  colName: '字段',
  colType: '类型',
  colComment: '注释',
  colPrimary: '主键',
  yes: '是',
  previewTitle: '生成预览（{count} 个文件）',
  resultTitle: '生成结果',
  resultMenu: '已登记菜单：{path}（slug: {slug}）',
  resultSnippet: '如需纳入声明式源文件 {file}，可粘贴：',
  generateSuccess: '生成成功，请执行 cccms:perm-scan 同步按钮节点',
}
