// Tree-shaken ECharts: only the charts and components the dashboard uses.
// Add a chart type here (and rebuild) before using it in the page.
import * as echarts from 'echarts/core';
import { BarChart, HeatmapChart, LineChart } from 'echarts/charts';
import {
  CalendarComponent,
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  TooltipComponent,
  VisualMapComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
  BarChart, HeatmapChart, LineChart,
  CalendarComponent, DataZoomComponent, GridComponent, LegendComponent, MarkLineComponent,
  TooltipComponent, VisualMapComponent,
  CanvasRenderer,
]);

window.echarts = echarts;
