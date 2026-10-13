import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import { Bar, BarChart, CartesianGrid, LabelList, Legend, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { ChartError, ChartSpec } from '../api'
import { chartCsv, endLabelOffsets, endLabelText, formatValue, label, niceTicks, seriesColors, shortTick, summary } from './chartFormat'
import { Dialog } from '../ui/Dialog'
import { DownloadIcon, ExpandIcon, WarnIcon } from '../ui/icons'
import { CitationContext } from './chatUtils'
import { t } from '../i18n'

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

/** Tema değişkeni veya CSS rengi → hesaplanmış renk (canvas için). */
function resolveColor(value: string): string {
  const probe = document.createElement('span')
  probe.style.color = value
  document.body.appendChild(probe)
  const color = getComputedStyle(probe).color
  probe.remove()
  return color
}

interface PngExtras {
  title: string
  legend: { name: string; color: string }[]
  footer: string
}

/**
 * SVG → PNG: tema değişkenleri hesaplanmış renklere çevrilir (kopya sayfadan bağımsız çizilsin). Başlık, renk açıklaması
 * (legend HTML olduğu için SVG'de yok) ve kaynak satırı görüntüye eklenir; dosya tek başına anlaşılır.
 */
async function svgToPng(svg: SVGSVGElement, extras: PngExtras): Promise<Blob | null> {
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
    const pad = 16
    const titleH = 48
    // Recharts legend için SVG'nin altında yer ayırır (legend HTML olduğundan SVG'de boş); açıklama oraya çizilir.
    const legendH = 0
    const footerH = 24
    const scale = 2
    const canvas = document.createElement('canvas')
    canvas.width = (width + pad * 2) * scale
    canvas.height = (titleH + height + legendH + footerH + pad) * scale
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    const font = getComputedStyle(document.body).fontFamily
    ctx.scale(scale, scale)
    ctx.fillStyle = resolveColor('var(--surface)')
    ctx.fillRect(0, 0, width + pad * 2, titleH + height + legendH + footerH + pad)
    ctx.fillStyle = resolveColor('var(--text)')
    ctx.font = `600 14px ${font}`
    ctx.textBaseline = 'middle'
    ctx.fillText(extras.title, pad, pad + 8)
    ctx.drawImage(image, pad, titleH)

    // Renk açıklaması: ortalanmış çizgi/kare + ad (metin tema renginde).
    ctx.font = `12px ${font}`
    const items = extras.legend.map((item) => ({ ...item, w: 22 + ctx.measureText(item.name).width }))
    let x = pad + Math.max(0, (width - items.reduce((sum, item) => sum + item.w + 16, -16)) / 2)
    const y = titleH + height - 14
    for (const item of items.length > 1 ? items : []) {
      ctx.fillStyle = resolveColor(item.color)
      ctx.fillRect(x, y - 1.5, 14, 3)
      ctx.fillStyle = resolveColor('var(--text-2)')
      ctx.fillText(item.name, x + 20, y)
      x += item.w + 16
    }

    ctx.fillStyle = resolveColor('var(--text-muted)')
    ctx.font = `11px ${font}`
    ctx.fillText(extras.footer, pad, titleH + height + legendH + footerH / 2 + 4)
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
        {chart?.error ?? t('chat.chart.invalid')}
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
    const footer = [t('chat.chart.source', { source: query ? `${query.source} · ${query.purpose}` : chart.source }), chart.derived ? t('chat.chart.derived') : null, chart.note].filter(Boolean).join(' · ')
    const blob = await svgToPng(svg, { title: chart.title, legend: chart.series.filter((s) => !hidden.has(s.name)).map((s) => ({ name: s.name, color: colors.get(s.name) ?? 'var(--brand)' })), footer })
    if (blob) save(blob, `${slug(chart.title)}.png`)
  }

  return (
    <figure className="m-0 overflow-hidden rounded-xl border border-line bg-surface" aria-label={chart.title}>
      <figcaption className="flex items-center gap-1 border-b border-line px-3 py-2">
        <span className="flex-1 truncate text-[13px] font-medium text-ink">{chart.title}</span>
        <div role="group" aria-label={t('chat.chart.view')} className="mr-1 flex rounded-md border border-line p-0.5 text-xs">
          {(['chart', 'table'] as const).map((v) => (
            <button key={v} type="button" aria-pressed={view === v} onClick={() => setView(v)} className={`rounded px-2 py-0.5 ${view === v ? 'bg-raised text-ink' : 'text-muted hover:text-ink'}`}>
              {v === 'chart' ? t('chat.chart.chart') : t('chat.table.title')}
            </button>
          ))}
        </div>
        <button type="button" aria-label={t('chat.csv')} title={t('chat.csv')} onClick={() => save(new Blob(['\uFEFF' + chartCsv(chart)], { type: 'text/csv;charset=utf-8' }), `${slug(chart.title)}.csv`)} className={iconButton}>
          <DownloadIcon />
        </button>
        <button type="button" aria-label={t('chat.chart.png')} title={t('chat.chart.png')} disabled={view !== 'chart'} onClick={png} className={`${iconButton} px-2 text-[11px] font-medium disabled:opacity-40`}>
          PNG
        </button>
        <button type="button" aria-label={t('chat.chart.expand')} title={t('chat.expand')} onClick={() => setExpanded(true)} className={iconButton}>
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
        <span>{n ? t('chat.chart.source_n', { n, source: query ? `${query.source} · ${query.purpose}` : chart.source }) : t('chat.chart.source', { source: query ? `${query.source} · ${query.purpose}` : chart.source })}</span>
        {chart.derived && <span>{t('chat.chart.derived')}.</span>}
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
  const directLabels = chart.type === 'line' && multi && chart.series.length <= 4
  const longestName = Math.max(...chart.series.map((s) => endLabelText(s.name).length))
  const margin = { top: 8, right: directLabels ? Math.min(130, 14 + longestName * 6.5) : 16, bottom: 0, left: 0 }
  // Çizim alanı yüksekliği (etiket aralığı için): kap yüksekliği − x ekseni − legend − üst boşluk.
  const box = useRef<HTMLDivElement>(null)
  const [boxHeight, setBoxHeight] = useState(0)
  useEffect(() => {
    const el = box.current
    if (!el || typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(() => setBoxHeight(el.clientHeight))
    observer.observe(el)
    return () => observer.disconnect()
  }, [])
  const offsets = directLabels ? endLabelOffsets(chart, hidden, domain, Math.max(80, (boxHeight || 220) - 30 - (multi ? 26 : 0) - margin.top)) : new Map<string, number>()

  if (chart.type === 'line') {
    const last = chart.data.length - 1
    return (
      <div ref={box} className="h-full">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={chart.data} margin={margin} accessibilityLayer>
            {grid}
            <XAxis
              dataKey={chart.x.field}
              tickFormatter={(v) => label(chart, v)}
              tick={AXIS_TICK}
              tickLine={false}
              axisLine={{ stroke: 'var(--border)' }}
              interval="equidistantPreserveStart"
              minTickGap={large ? 24 : 12}
            />
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
                {directLabels && (
                  <LabelList
                    dataKey={s.field}
                    content={({ x, y, index }) =>
                      index === last ? (
                        <text x={Number(x) + 6} y={Number(y) + (offsets.get(s.name) ?? 0)} dy={4} fontSize={11} fill="var(--text-2)">
                          <title>{s.name}</title>
                          {endLabelText(s.name)}
                        </text>
                      ) : null
                    }
                  />
                )}
              </Line>
            ))}
          </LineChart>
        </ResponsiveContainer>
      </div>
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
