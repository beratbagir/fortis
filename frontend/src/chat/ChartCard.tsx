import { useContext, useMemo, useRef, useState } from 'react'
import { Bar, BarChart, CartesianGrid, LabelList, Legend, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { ChartError, ChartSpec } from '../api'
import { chartCsv, formatValue, label, niceTicks, seriesColors, shortTick, summary } from './chartFormat'
import { Dialog } from '../ui/Dialog'
import { DownloadIcon, ExpandIcon, WarnIcon } from '../ui/icons'
import { CitationContext } from './chatUtils'

const slug = (title: string) =>
  title
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase() || 'grafik'

function save(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

/** SVG → PNG: tema değişkenleri hesaplanmış renklere çevrilir (kopya sayfadan bağımsız çizilsin). */
async function svgToPng(svg: SVGSVGElement, background: string): Promise<Blob | null> {
  const clone = svg.cloneNode(true) as SVGSVGElement
  const source = svg.querySelectorAll('*')
  clone.querySelectorAll('*').forEach((el, i) => {
    const style = getComputedStyle(source[i])
    for (const prop of ['fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'opacity', 'font-size', 'font-family', 'font-weight'] as const) {
      ;(el as SVGElement).style.setProperty(prop, style.getPropertyValue(prop))
    }
  })
  const { width, height } = svg.getBoundingClientRect()
  clone.setAttribute('width', String(width))
  clone.setAttribute('height', String(height))
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml' }))
  try {
    const image = new Image()
    await new Promise((resolve, reject) => {
      image.onload = resolve
      image.onerror = reject
      image.src = url
    })
    const scale = 2
    const canvas = document.createElement('canvas')
    canvas.width = width * scale
    canvas.height = height * scale
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    ctx.fillStyle = background
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.scale(scale, scale)
    ctx.drawImage(image, 0, 0)
    return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
  } finally {
    URL.revokeObjectURL(url)
  }
}

const iconButton = 'rounded-md p-1.5 text-muted hover:bg-raised hover:text-ink'

/** Doğrulanamamış veya şemaya uymayan grafik: veri gösterilmez. */
export function ChartErrorCard({ chart }: { chart: ChartError | undefined }) {
  return (
    <div role="note" className="flex items-center gap-2 rounded-xl border border-dashed border-line-strong bg-canvas px-3 py-2.5 text-[13px] text-muted">
      <WarnIcon className="shrink-0 text-warn" />
      <span>
        {chart?.error ?? 'Grafik gösterilemedi'}
        {chart?.title ? ` · ${chart.title}` : ''}
      </span>
    </div>
  )
}

/** Sohbette grafik kartı: tablo kartıyla aynı çerçeve; Grafik/Tablo, CSV, PNG, büyüt; altta kaynak ve not. */
export function ChartCard({ chart }: { chart: ChartSpec }) {
  const [view, setView] = useState<'chart' | 'table'>('chart')
  const [hidden, setHidden] = useState<Set<string>>(new Set())
  const [expanded, setExpanded] = useState(false)
  const plot = useRef<HTMLDivElement>(null)
  const { queries, numbers } = useContext(CitationContext)
  const colors = useMemo(() => seriesColors(chart), [chart])
  const id = chart.source.replace(/^q:/, '')
  const query = queries.get(id)
  const n = numbers.get(id)
  const description = summary(chart)

  const toggle = (name: string) =>
    setHidden((prev) => {
      const next = new Set(prev)
      if (next.has(name)) next.delete(name)
      else if (next.size < chart.series.length - 1) next.add(name)
      return next
    })

  const png = async () => {
    const svg = plot.current?.querySelector('.recharts-wrapper > svg') as SVGSVGElement | null
    if (!svg) return
    const blob = await svgToPng(svg, getComputedStyle(document.documentElement).getPropertyValue('--surface').trim() || 'white')
    if (blob) save(blob, `${slug(chart.title)}.png`)
  }

  return (
    <figure className="m-0 overflow-hidden rounded-xl border border-line bg-surface" aria-label={chart.title}>
      <figcaption className="flex items-center gap-1 border-b border-line px-3 py-2">
        <span className="flex-1 truncate text-[13px] font-medium text-ink">{chart.title}</span>
        <div role="group" aria-label="Görünüm" className="mr-1 flex rounded-md border border-line p-0.5 text-xs">
          {(['chart', 'table'] as const).map((v) => (
            <button key={v} type="button" aria-pressed={view === v} onClick={() => setView(v)} className={`rounded px-2 py-0.5 ${view === v ? 'bg-raised text-ink' : 'text-muted hover:text-ink'}`}>
              {v === 'chart' ? 'Grafik' : 'Tablo'}
            </button>
          ))}
        </div>
        <button type="button" aria-label="CSV indir" title="CSV indir" onClick={() => save(new Blob(['\uFEFF' + chartCsv(chart)], { type: 'text/csv;charset=utf-8' }), `${slug(chart.title)}.csv`)} className={iconButton}>
          <DownloadIcon />
        </button>
        <button type="button" aria-label="PNG indir" title="PNG indir" disabled={view !== 'chart'} onClick={png} className={`${iconButton} px-2 text-[11px] font-medium disabled:opacity-40`}>
          PNG
        </button>
        <button type="button" aria-label="Grafiği büyüt" title="Büyüt" onClick={() => setExpanded(true)} className={iconButton}>
          <ExpandIcon />
        </button>
      </figcaption>

      {view === 'chart' ? (
        <div ref={plot} role="img" aria-label={description} className="h-[220px] px-1 pt-3 md:h-[280px]">
          <ChartPlot chart={chart} colors={colors} hidden={hidden} onToggle={toggle} />
        </div>
      ) : (
        <ChartTable chart={chart} />
      )}

      <div className="flex flex-wrap gap-x-3 gap-y-1 border-t border-line px-3 py-2 text-[11px] text-muted">
        <span>
          Kaynak{n ? ` [${n}]` : ''}: {query ? `${query.source} · ${query.purpose}` : chart.source}
        </span>
        {chart.derived && <span>Değerler kaynaktan hesaplandı.</span>}
        {chart.note && <span>{chart.note}</span>}
      </div>

      {expanded && (
        <Dialog title={chart.title} wide onClose={() => setExpanded(false)}>
          <div role="img" aria-label={description} className="h-[60vh] min-h-[320px]">
            <ChartPlot chart={chart} colors={colors} hidden={hidden} onToggle={toggle} large />
          </div>
        </Dialog>
      )}
    </figure>
  )
}

function ChartTable({ chart }: { chart: ChartSpec }) {
  return (
    <div className="max-h-[280px] overflow-auto">
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="bg-canvas text-left text-xs text-muted">
            <th className="border-b border-line px-3 py-2 font-medium">{chart.x.label ?? chart.x.field}</th>
            {chart.series.map((s) => (
              <th key={s.field} className="border-b border-line px-3 py-2 text-right font-medium">
                {s.name}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {chart.data.map((row, i) => (
            <tr key={i} className="border-b border-line last:border-b-0">
              <td className="px-3 py-1.5">{label(chart, row[chart.x.field])}</td>
              {chart.series.map((s) => (
                <td key={s.field} className="px-3 py-1.5 text-right font-mono tabular-nums">
                  {formatValue(row[s.field], chart.y.unit)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

const AXIS_TICK = { fontSize: 11, fill: 'var(--text-muted)' }
const TOOLTIP_STYLE = { background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12, color: 'var(--text)' }

function ChartPlot({ chart, colors, hidden, onToggle, large = false }: { chart: ChartSpec; colors: Map<string, string>; hidden: Set<string>; onToggle: (name: string) => void; large?: boolean }) {
  const multi = chart.series.length > 1
  // Gizlenen seri çizilmez ama legend'da kalır (hide); renkler seriye bağlı olduğu için değişmez.
  const topVisible = [...chart.series].reverse().find((s) => !hidden.has(s.name))?.field
  const ticks = niceTicks(chart, chart.type === 'stacked_bar')
  // Legend ve tooltip seri sırasında (Recharts varsayılanı ada göre sıralar).
  const order = (item: { value?: unknown; name?: unknown }) => chart.series.findIndex((s) => s.name === (item.value ?? item.name))
  const domain: [number, number] = [ticks[0], ticks[ticks.length - 1]]
  const tooltip = (
    <Tooltip
      contentStyle={TOOLTIP_STYLE}
      labelStyle={{ color: 'var(--text)', fontWeight: 500 }}
      itemStyle={{ color: 'var(--text-2)' }}
      cursor={chart.type === 'line' ? { stroke: 'var(--border-strong)' } : { fill: 'var(--surface-raised)' }}
      labelFormatter={(v) => label(chart, v)}
      itemSorter={(item) => order(item)}
      formatter={(v, name) => [formatValue(v as number, chart.y.unit), name]}
    />
  )
  const legend = multi && (
    <Legend
      verticalAlign="bottom"
      iconType={chart.type === 'line' ? 'plainline' : 'square'}
      wrapperStyle={{ fontSize: 12, color: 'var(--text-2)', cursor: 'pointer' }}
      formatter={(value: string) => <span style={{ color: hidden.has(value) ? 'var(--text-faint)' : 'var(--text-2)', textDecoration: hidden.has(value) ? 'line-through' : 'none' }}>{value}</span>}
      onClick={(entry) => onToggle(String(entry.value))}
      itemSorter={(item) => order(item)}
    />
  )
  const reference = chart.reference && (
    <ReferenceLine
      {...(chart.type === 'hbar' ? { x: chart.reference.y } : { y: chart.reference.y })}
      stroke="var(--text-faint)"
      strokeDasharray="4 4"
      label={chart.reference.label ? { value: chart.reference.label, fill: 'var(--text-muted)', fontSize: 11, position: chart.type === 'hbar' ? 'top' : 'insideTopRight' } : undefined}
    />
  )
  const grid = <CartesianGrid stroke="var(--chart-grid)" vertical={chart.type === 'hbar'} horizontal={chart.type !== 'hbar'} />
  const margin = { top: 8, right: chart.type === 'line' && multi && chart.series.length <= 4 ? 56 : 16, bottom: 0, left: 0 }

  if (chart.type === 'line') {
    const last = chart.data.length - 1
    return (
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={chart.data} margin={margin} accessibilityLayer>
          {grid}
          <XAxis dataKey={chart.x.field} tickFormatter={(v) => label(chart, v)} tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: 'var(--border)' }} interval="preserveStartEnd" minTickGap={large ? 24 : 16} />
          <YAxis domain={domain} ticks={ticks} tickFormatter={(v: number) => shortTick(v, chart.y.unit)} tick={AXIS_TICK} tickLine={false} axisLine={false} width={44} />
          {tooltip}
          {reference}
          {legend}
          {chart.series.map((s) => (
            <Line
              key={s.field}
              dataKey={s.field}
              name={s.name}
              hide={hidden.has(s.name)}
              stroke={colors.get(s.name)}
              strokeWidth={2}
              dot={false}
              activeDot={{ r: 4.5, strokeWidth: 0, fill: colors.get(s.name) }}
              isAnimationActive={false}
              connectNulls
            >
              {multi && chart.series.length <= 4 && (
                <LabelList
                  dataKey={s.field}
                  content={({ x, y, index }) =>
                    index === last ? (
                      <text x={Number(x) + 6} y={Number(y)} dy={4} fontSize={11} fill="var(--text-2)">
                        {s.name}
                      </text>
                    ) : null
                  }
                />
              )}
            </Line>
          ))}
        </LineChart>
      </ResponsiveContainer>
    )
  }

  const horizontal = chart.type === 'hbar'
  const stacked = chart.type === 'stacked_bar'
  const longest = Math.max(...chart.data.map((r) => label(chart, r[chart.x.field]).length))
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={chart.data} layout={horizontal ? 'vertical' : 'horizontal'} margin={margin} barCategoryGap="20%" barGap={2} accessibilityLayer>
        {grid}
        {horizontal ? (
          <>
            <XAxis type="number" domain={domain} ticks={ticks} tickFormatter={(v: number) => shortTick(v, chart.y.unit)} tick={AXIS_TICK} tickLine={false} axisLine={false} />
            <YAxis type="category" dataKey={chart.x.field} tickFormatter={(v) => label(chart, v)} tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: 'var(--border)' }} width={Math.min(160, 12 + longest * 6.5)} />
          </>
        ) : (
          <>
            <XAxis dataKey={chart.x.field} tickFormatter={(v) => label(chart, v)} tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: 'var(--border)' }} interval="preserveStartEnd" minTickGap={8} />
            <YAxis domain={domain} ticks={ticks} tickFormatter={(v: number) => shortTick(v, chart.y.unit)} tick={AXIS_TICK} tickLine={false} axisLine={false} width={44} />
          </>
        )}
        {tooltip}
        {reference}
        {legend}
        {chart.series.map((s) => (
          <Bar
            key={s.field}
            dataKey={s.field}
            name={s.name}
            hide={hidden.has(s.name)}
            fill={colors.get(s.name)}
            stackId={stacked ? 'stack' : undefined}
            radius={stacked && s.field !== topVisible ? 0 : horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]}
            isAnimationActive={false}
            maxBarSize={48}
          />
        ))}
      </BarChart>
    </ResponsiveContainer>
  )
}
