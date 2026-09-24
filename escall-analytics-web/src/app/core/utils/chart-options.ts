import type { EChartsOption } from 'echarts';
import { DailyEvolution, Distribution, HourlyActivity, HourlyMetric } from '../models/analytics.models';
const blue = '#064FAE';
const base: EChartsOption = { color: [blue, '#60A5EA', '#BECFE3'], textStyle: { fontFamily: 'Segoe UI, sans-serif', fontSize: 11, color: '#667085' }, tooltip: { trigger: 'axis', confine: true, backgroundColor: '#ffffff', borderColor: '#D9E0E8', textStyle: { fontSize: 11, color: '#172033' }, extraCssText: 'box-shadow:0 4px 16px #062e6610;border-radius:7px;' } };
export function dailyChart(data: DailyEvolution[]): EChartsOption {
  return { ...base, grid: { top: 28, bottom: 30, left: 35, right: 12 },
    legend: { top: 0, right: 4, itemWidth: 10, itemHeight: 6, textStyle: { fontSize: 10, color: '#667085' }, data: ['Gestiones', 'Clientes gestionados'] },
    xAxis: { type: 'category', data: data.map(row => row.fecha.slice(8) + ' sep'), axisLine: { lineStyle: { color: '#D9E0E8' } }, axisTick: { show: false }, axisLabel: { fontSize: 10, interval: 'auto' } },
    yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: '#edf1f5', type: 'dashed' } } },
    series: [{ name: 'Gestiones', type: 'bar', data: data.map(row => row.gestiones), barMaxWidth: 19, itemStyle: { color: '#2D73CB', borderRadius: [3, 3, 0, 0] } },
      { name: 'Clientes gestionados', type: 'line', data: data.map(row => row.clientes), symbolSize: 5, smooth: 0.2, itemStyle: { color: '#74B7E5' }, lineStyle: { width: 2 } }],
  };
}
export function donutChart(data: Distribution[]): EChartsOption {
  return { ...base, tooltip: { trigger: 'item', confine: true, formatter: '{b}: {c} ({d}%)', textStyle: { fontSize: 11 } },
    series: [{ type: 'pie', radius: ['61%', '82%'], center: ['50%', '47%'], avoidLabelOverlap: true, label: { show: false }, emphasis: { scaleSize: 3 }, itemStyle: { borderColor: '#fff', borderWidth: 3, borderRadius: 4 }, data: data.map((row, index) => ({ ...row, itemStyle: { color: ['#C3D1E2', '#064FAE', '#6C9BD5'][index] } })) }],
  };
}
const amount = (value: number): string => `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;
export function paymentAgreementChart(data: Array<{ tipo: string; cantidad: number; monto: number }>): EChartsOption {
  return { ...base, grid: { top: 12, bottom: 12, left: 42, right: 94 }, tooltip: { ...base.tooltip, formatter: (params: unknown) => { const item = (params as Array<{ dataIndex: number }>)[0]; const row = data[item.dataIndex]; return `${row.tipo}<br/>Monto: <b>${amount(row.monto)}</b><br/>Pagos: <b>${row.cantidad}</b>`; } }, xAxis: { type: 'value', show: false }, yAxis: { type: 'category', inverse: true, data: data.map(row => row.tipo), axisTick: { show: false }, axisLine: { show: false }, axisLabel: { fontWeight: 650, color: '#526077' } }, series: [{ type: 'bar', barMaxWidth: 20, data: data.map(row => row.monto), itemStyle: { color: '#276DC2', borderRadius: [0, 4, 4, 0] }, label: { show: true, position: 'right', color: '#526077', fontSize: 10, formatter: (params: { dataIndex: number }) => `${amount(data[params.dataIndex].monto)}  ·  ${data[params.dataIndex].cantidad} pagos` } }] };
}
export function paymentRankingChart(data: Array<{ asesor: string; pagos: number; monto: number }>): EChartsOption {
  return { ...base, grid: { top: 7, bottom: 8, left: 120, right: 94 }, tooltip: { ...base.tooltip, formatter: (params: unknown) => { const item = (params as Array<{ dataIndex: number }>)[0]; const row = data[item.dataIndex]; return `${row.asesor}<br/>Monto: <b>${amount(row.monto)}</b><br/>Pagos: <b>${row.pagos}</b>`; } }, xAxis: { type: 'value', show: false }, yAxis: { type: 'category', inverse: true, data: data.map(row => row.asesor), axisTick: { show: false }, axisLine: { show: false }, axisLabel: { fontSize: 9, color: '#526077', width: 108, overflow: 'truncate' } }, series: [{ type: 'bar', barMaxWidth: 15, data: data.map(row => row.monto), itemStyle: { color: '#5D94CF', borderRadius: [0, 4, 4, 0] }, label: { show: true, position: 'right', color: '#526077', fontSize: 9, formatter: (params: { dataIndex: number }) => `${amount(data[params.dataIndex].monto)} · ${data[params.dataIndex].pagos}` } }] };
}
export function horizontalChart(data: readonly Distribution[], options: { percent?: boolean; labelWidth?: number; compact?: boolean } = {}): EChartsOption {
  return { ...base, grid: { top: 4, bottom: options.compact ? 4 : 20, left: options.labelWidth ?? 38, right: 36 },
    xAxis: { type: 'value', show: !options.compact, minInterval: 1, max: options.percent ? 100 : undefined, axisLabel: { fontSize: 9, formatter: options.percent ? '{value}%' : '{value}' }, splitLine: { lineStyle: { color: '#edf1f5', type: 'dashed' } } },
    yAxis: { type: 'category', inverse: true, data: data.map(row => row.name), axisTick: { show: false }, axisLine: { show: false }, axisLabel: { fontSize: 10, color: '#526077' } },
    series: [{ type: 'bar', barMaxWidth: options.compact ? 9 : 13, data: data.map(row => row.value), showBackground: !!options.percent, backgroundStyle: { color: '#F0F4F9', borderRadius: 2 }, itemStyle: { color: '#276DC2', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', fontSize: 10, color: '#526077', formatter: options.percent ? '{c}%' : '{c}' } }],
  };
}
export const HOURLY_LABELS: Record<HourlyMetric, string> = { gestiones: 'Gestiones', contactos: 'Contactos', efectivos: 'Contacto efectivo', ppc: 'PPC' };
export function hourlyChart(data: HourlyActivity[], metric: HourlyMetric): EChartsOption {
  return { ...base, grid: { top: 12, bottom: 28, left: 28, right: 12 }, xAxis: { type: 'category', data: data.map(row => row.hora), axisTick: { show: false }, axisLabel: { fontSize: 10 }, axisLine: { lineStyle: { color: '#D9E0E8' } } }, yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { type: 'dashed', color: '#edf1f5' } } }, series: [{ name: HOURLY_LABELS[metric], type: 'bar', data: data.map(row => row[metric]), barMaxWidth: 26, itemStyle: { color: '#2D73CB', borderRadius: [3, 3, 0, 0] } }] };
}
