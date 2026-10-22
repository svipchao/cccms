/**
 * echarts 按需入口（**只允许通过动态 import 引用**）。
 *
 * 为什么单独建一个模块，而不是在页面里 `import('echarts/charts')`：
 *   `import('echarts/charts')` 拿到的是整个命名空间，`charts.LineChart` 这种
 *   运行期属性访问无法被 Rollup 静态分析 → 整套图表类型（bar/radar/sankey…）
 *   会全部打进产物（实测从 532KB 涨到 1.09MB）。
 *   这里用**具名静态导入**，Rollup 能精确裁剪，只留下用到的图表；
 *   页面侧只 `import('@/utils/echarts')`，产物依旧是独立的懒加载 chunk。
 *
 * 用法：
 *   const { default: echarts } = await import('@/utils/echarts')
 *   echarts.init(el)
 */
import * as echarts from 'echarts/core'
import { LineChart, PieChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

export type { ECharts } from 'echarts/core'
export default echarts
