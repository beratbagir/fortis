import type { ChartError, ChartSpec } from '../api'

/** Seri renkleri tema token'larında (index.css --series-N); tek seri marka rengi. Renk serinin sırasına değil adına bağlı. */
const PALETTE = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)', 'var(--series-6)']

export const isChartError = (chart: ChartSpec | ChartError | undefined): chart is ChartError | undefined => chart === undefined || 'error' in chart

/** Seri adı → renk; gizlenen seri diğerlerinin rengini değiştirmez. */
export function seriesColors(chart: ChartSpec): Map<string, string> {
  return new Map(chart.series.map((s, i) => [s.name, chart.series.length === 1 ? 'var(--brand)' : PALETTE[i % PALETTE.length]]))
}

const numberFormat = (digits: number) => new Intl.NumberFormat('tr-TR', { maximumFractionDigits: digits })

/** Türkçe sayı ve birim: %82,4 · 1.250 adet · 35 dk · $1.204 · 1.204 ₺ */
export function formatValue(value: number | string | null | undefined, unit: ChartSpec['y']['unit']): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'number' ? value : Number(value)
  if (Number.isNaN(n)) return String(value)
  const text = numberFormat(Math.abs(n) < 10 && !Number.isInteger(n) ? 2 : 1).format(n)
  switch (unit) {
    case '%':
      return `%${text}`
    case 'dk':
      return `${text} dk`
    case 'adet':
      return `${text} adet`
    case '$':
      return `$${text}`
    case '₺':
      return `${text} ₺`
    default:
      return text
  }
}

export const shortTick = (value: number, unit: ChartSpec['y']['unit']) => (unit === '%' ? `%${numberFormat(0).format(value)}` : numberFormat(Math.abs(value) >= 1000 ? 0 : 1).format(value))

/** "2026-09-14" → "14 Eyl", "2026-09-14T06:00" → "14 Eyl 06:00" */
export function formatDate(value: string): string {
  const m = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}:\d{2}))?/)
  if (!m) return value
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const day = date.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })
  return m[4] ? `${day} ${m[4]}` : day
}

export const label = (chart: ChartSpec, value: unknown) => (chart.x.type === 'date' ? formatDate(String(value)) : String(value ?? ''))

/** CSV: x + seriler, Türkçe başlıklar; değerler ham sayı (Excel'de hesaplanabilsin). */
export function chartCsv(chart: ChartSpec): string {
  const quote = (v: string) => (/[",\n;]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
  const header = [chart.x.label ?? chart.x.field, ...chart.series.map((s) => s.name + (chart.y.unit ? ` (${chart.y.unit})` : ''))]
  const rows = chart.data.map((row) => [String(row[chart.x.field] ?? ''), ...chart.series.map((s) => (row[s.field] === null || row[s.field] === undefined ? '' : String(row[s.field])))])
  return [header, ...rows].map((r) => r.map(quote).join(',')).join('\n')
}

/** Ekran okuyucu özeti: tür, nokta sayısı, en yüksek ve en düşük (ilk seri). */
export function summary(chart: ChartSpec): string {
  const kind = { line: 'Çizgi grafik', bar: 'Çubuk grafik', hbar: 'Yatay çubuk grafik', stacked_bar: 'Yığılmış çubuk grafik' }[chart.type]
  const first = chart.series[0]
  const points = chart.data.filter((r) => typeof r[first.field] === 'number')
  if (points.length === 0) return `${chart.title}. ${kind}.`
  const max = points.reduce((a, b) => ((b[first.field] as number) > (a[first.field] as number) ? b : a))
  const min = points.reduce((a, b) => ((b[first.field] as number) < (a[first.field] as number) ? b : a))
  return `${chart.title}. ${kind}, ${chart.data.length} nokta, ${chart.series.length} seri. ${first.name} en yüksek ${label(chart, max[chart.x.field])} ${formatValue(max[first.field], chart.y.unit)}, en düşük ${label(chart, min[chart.x.field])} ${formatValue(min[first.field], chart.y.unit)}.`
}

/** Değer ekseni: 0'dan (veya y.min'den) başlayan, 1/2/2,5/5 × 10ⁿ adımlı yaklaşık 5 aralık. */
export function niceTicks(chart: ChartSpec, stacked = false): number[] {
  const values = chart.data.flatMap((row) => {
    const nums = chart.series.map((s) => row[s.field]).filter((v): v is number => typeof v === 'number')
    return stacked ? [nums.reduce((a, b) => a + b, 0)] : nums
  })
  const lo = chart.type === 'line' && chart.y.min !== null ? chart.y.min : Math.min(0, ...values)
  const hi = Math.max(lo + 1, ...values, chart.reference?.y ?? -Infinity)
  const raw = (hi - lo) / 5
  const power = 10 ** Math.floor(Math.log10(raw))
  const step = ([1, 2, 2.5, 5, 10].find((m) => m * power >= raw) ?? 10) * power
  const start = Math.floor(lo / step) * step
  const ticks: number[] = []
  for (let t = start; t < hi + step - 1e-9; t += step) ticks.push(Number(t.toFixed(10)))
  return ticks
}
