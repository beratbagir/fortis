import type { ChartError, ChartSpec } from '../api'
import { getLocale, intlLocale, t } from '../i18n'

/** Seri renkleri tema token'larında (index.css --series-N); tek seri marka rengi. Renk serinin sırasına değil adına bağlı. */
const PALETTE = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)', 'var(--series-5)', 'var(--series-6)']

export const isChartError = (chart: ChartSpec | ChartError | undefined): chart is ChartError | undefined => chart === undefined || 'error' in chart

/** Seri adı → renk; gizlenen seri diğerlerinin rengini değiştirmez. */
export function seriesColors(chart: ChartSpec): Map<string, string> {
  return new Map(chart.series.map((s, i) => [s.name, chart.series.length === 1 ? 'var(--brand)' : PALETTE[i % PALETTE.length]]))
}

const numberFormat = (digits: number) => new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: digits })

/** Birim etiketi (y.unit protokol değeri: %, dk, adet, $, ₺). */
export const unitLabel = (unit: ChartSpec['y']['unit']) => (unit === 'dk' || unit === 'adet' ? t(`chat.unit.${unit}`) : unit)

/** Dile göre sayı ve birim. Türkçe: %82,4 · 1.250 adet · 35 dk · $1.204 · 1.204 ₺; İngilizce: 82.4% · 1,250 · 35 min · $1,204 · ₺1,204 */
export function formatValue(value: number | string | null | undefined, unit: ChartSpec['y']['unit']): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'number' ? value : Number(value)
  if (Number.isNaN(n)) return String(value)
  const text = numberFormat(Math.abs(n) < 10 && !Number.isInteger(n) ? 2 : 1).format(n)
  const tr = getLocale() === 'tr'
  switch (unit) {
    case '%':
      return tr ? `%${text}` : `${text}%`
    case 'dk':
    case 'adet':
      return unitLabel(unit) ? `${text} ${unitLabel(unit)}` : text
    case '$':
      return `$${text}`
    case '₺':
      return tr ? `${text} ₺` : `₺${text}`
    default:
      return text
  }
}

export const shortTick = (value: number, unit: ChartSpec['y']['unit']) =>
  unit === '%' ? (getLocale() === 'tr' ? `%${numberFormat(0).format(value)}` : `${numberFormat(0).format(value)}%`) : numberFormat(Math.abs(value) >= 1000 ? 0 : 1).format(value)

/** "2026-09-14" → "14 Eyl", "2026-09-14T06:00" → "14 Eyl 06:00" */
export function formatDate(value: string): string {
  const m = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}:\d{2}))?/)
  if (!m) return value
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const day = date.toLocaleDateString(intlLocale(), { day: 'numeric', month: 'short' })
  return m[4] ? `${day} ${m[4]}` : day
}

export const label = (chart: ChartSpec, value: unknown) => (chart.x.type === 'date' ? formatDate(String(value)) : String(value ?? ''))

/** CSV: x + seriler, seçili dilde başlıklar; değerler ham sayı (Excel'de hesaplanabilsin). */
export function chartCsv(chart: ChartSpec): string {
  const quote = (v: string) => (/[",\n;]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
  const header = [chart.x.label ?? chart.x.field, ...chart.series.map((s) => s.name + (unitLabel(chart.y.unit) ? ` (${unitLabel(chart.y.unit)})` : ''))]
  const rows = chart.data.map((row) => [String(row[chart.x.field] ?? ''), ...chart.series.map((s) => (row[s.field] === null || row[s.field] === undefined ? '' : String(row[s.field])))])
  return [header, ...rows].map((r) => r.map(quote).join(',')).join('\n')
}

/** Ekran okuyucu özeti: tür, nokta sayısı, en yüksek ve en düşük (ilk seri). */
export function summary(chart: ChartSpec): string {
  const kind = t(`chat.chart.kind.${chart.type}`)
  const first = chart.series[0]
  const points = chart.data.filter((r) => typeof r[first.field] === 'number')
  if (points.length === 0) return `${chart.title}. ${kind}.`
  const max = points.reduce((a, b) => ((b[first.field] as number) > (a[first.field] as number) ? b : a))
  const min = points.reduce((a, b) => ((b[first.field] as number) < (a[first.field] as number) ? b : a))
  return t('chat.chart.summary', {
    title: chart.title,
    kind,
    points: chart.data.length,
    series: chart.series.length,
    name: first.name,
    maxAt: label(chart, max[chart.x.field]),
    max: formatValue(max[first.field], chart.y.unit),
    minAt: label(chart, min[chart.x.field]),
    min: formatValue(min[first.field], chart.y.unit),
  })
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

/** Çizgi sonu etiketi: uzun adlar kısaltılır. */
export const endLabelText = (name: string) => (name.length > 18 ? `${name.slice(0, 17)}…` : name)

/**
 * Çizgi sonu etiketlerinin dikey kayması (px): son değerleri yakın seriler en az `gap` px aralıkla dizilir, alt sınırı
 * aşarsa hepsi yukarı kayar. `plotHeight` çizim alanının yüksekliği; değerler eksen aralığına göre konumlanır.
 */
export function endLabelOffsets(chart: ChartSpec, hidden: Set<string>, domain: [number, number], plotHeight: number, gap = 13): Map<string, number> {
  const lastRow = chart.data[chart.data.length - 1] ?? {}
  const range = domain[1] - domain[0] || 1
  const items = chart.series
    .filter((s) => !hidden.has(s.name) && typeof lastRow[s.field] === 'number')
    .map((s) => ({ name: s.name, target: (1 - ((lastRow[s.field] as number) - domain[0]) / range) * plotHeight }))
    .sort((a, b) => a.target - b.target)
  const placed = items.map((item) => item.target)
  for (let i = 1; i < placed.length; i++) placed[i] = Math.max(placed[i], placed[i - 1] + gap)
  const overflow = placed.length > 0 ? placed[placed.length - 1] - plotHeight : 0
  if (overflow > 0) for (let i = placed.length - 1; i >= 0; i--) placed[i] = Math.min(placed[i] - overflow, i < placed.length - 1 ? placed[i + 1] - gap : Infinity)
  return new Map(items.map((item, i) => [item.name, placed[i] - item.target]))
}
