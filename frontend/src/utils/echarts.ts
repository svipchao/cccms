/**
 * echarts 统一注册入口（按需引入）。
 *
 * 工作台 / 接口监控 / 操作日志三个页面原先各自 `echarts.use([...])`，注册集合还互不相同：
 * 少注册一个组件表现为「图表一片空白」，且只有打开那个页面才会发现；多注册一份则是重复执行。
 * 集中到这里后，新增图表类型只改一处，页面只 `import { echarts } from '@/utils/echarts'`。
 */
import * as echarts from 'echarts/core'
import { LineChart, PieChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

export { echarts }
export type { ECharts } from 'echarts/core'
