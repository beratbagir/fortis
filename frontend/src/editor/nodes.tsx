import type { NodeProps } from '@xyflow/react'
import { useContext } from 'react'
import { WarnIcon } from '../ui/icons'
import type { BlockData, LabelData, SlotData } from './layout'
import { CanvasContext } from './canvasContext'
import { tone } from './tones'

const MARK_STYLE = {
  added: { border: '2px solid var(--success)', badge: 'Yeni', badgeClass: 'bg-ok-soft text-ok' },
  changed: { border: '2px solid var(--warning)', badge: 'Değişti', badgeClass: 'bg-warn-soft text-warn' },
  removed: { border: '2px dashed var(--danger)', badge: 'Silinecek', badgeClass: 'bg-bad-soft text-bad' },
} as const

function frame(data: BlockData): { border: string; shadow: string } {
  if (data.errors.length > 0) return { border: '2px solid var(--danger)', shadow: '0 6px 20px var(--shadow)' }
  if (data.mark) return { border: MARK_STYLE[data.mark].border, shadow: '0 6px 20px var(--shadow)' }
  if (data.selected) return { border: '2px solid var(--brand)', shadow: '0 6px 20px var(--shadow)' }
  return { border: '1px solid var(--border)', shadow: '0 2px 10px var(--shadow)' }
}

/** Blok kartının içi: ikon, başlık, tür, rozetler ve en fazla üç ayar satırı. Yükseklikler layout.ts ile aynı. */
function Card({ data, flat = false }: { data: BlockData; flat?: boolean }) {
  const { node, def, fields, mark } = data
  const colors = tone(def?.tone)
  const removed = mark === 'removed'

  return (
    <div className={`flex flex-col ${removed ? 'opacity-70' : ''}`} style={{ padding: flat ? 0 : 14 }}>
      <div className="flex h-[38px] items-center gap-3">
        <div className="flex size-[38px] shrink-0 items-center justify-center rounded-xl text-[13px] font-extrabold" style={{ background: colors.bg, color: colors.fg }} aria-hidden>
          {def?.glyph ?? '</>'}
        </div>
        <div className="flex min-w-0 flex-1 flex-col">
          <span className={`truncate text-[15px] leading-tight font-extrabold ${removed ? 'line-through' : ''}`}>{node.title ?? node.id}</span>
          <span className="truncate text-xs font-semibold text-faint">{node.label}</span>
        </div>
        {data.errors.length > 0 && (
          <span className="flex items-center gap-1 rounded-full bg-bad-soft px-2 py-0.5 text-[11px] font-extrabold text-bad">
            <WarnIcon /> Hata
          </span>
        )}
        {mark && <span className={`rounded-full px-2 py-0.5 text-[11px] font-extrabold ${MARK_STYLE[mark].badgeClass}`}>{MARK_STYLE[mark].badge}</span>}
        {!mark && node.readonly && <span className="rounded-full bg-raised px-2 py-0.5 text-[11px] font-extrabold text-ink-2">Salt okunur</span>}
        {!mark && !node.readonly && node.writes && (
          <span className="rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-extrabold text-accent" title="Test çalıştırmada gerçekten gönderilmez">
            Dış sisteme yazar
          </span>
        )}
      </div>
      {fields.length > 0 && (
        <dl className="m-0 mt-3 flex flex-col gap-1.5">
          {fields.map((f) => (
            <div key={f.k} className="flex h-[33px] items-center justify-between gap-3 rounded-[10px] bg-canvas px-2.5 text-[13px]">
              <dt className="shrink-0 font-semibold text-muted">{f.k}</dt>
              <dd className={`m-0 min-w-0 truncate text-right font-bold ${removed ? 'line-through' : ''}`}>{f.v}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  )
}

function describe(data: BlockData): string {
  const parts = [data.node.title ?? data.node.id, data.node.label]
  if (data.mark) parts.push(MARK_STYLE[data.mark].badge)
  if (data.errors.length) parts.push(`hata: ${data.errors.join(' ')}`)
  return parts.join(', ')
}

export function BlockNode({ data }: NodeProps) {
  const block = data as BlockData
  const { select } = useContext(CanvasContext)
  const style = frame(block)

  return (
    <button
      type="button"
      onClick={() => select(block.node.id)}
      aria-pressed={block.selected}
      aria-label={describe(block)}
      className="block h-full w-full cursor-pointer rounded-[18px] bg-surface text-left"
      style={{ border: style.border, boxShadow: style.shadow }}
    >
      <Card data={block} />
    </button>
  )
}

/** Döngü ve Koşul: kutunun üstünde bloğun kendisi, altında alt bloklar (Koşulun dalları yan yana). */
export function GroupNode({ data }: NodeProps) {
  const block = data as BlockData
  const { select } = useContext(CanvasContext)
  const style = frame(block)

  return (
    <div className="relative h-full w-full rounded-[22px] bg-canvas" style={{ border: block.selected || block.mark || block.errors.length ? style.border : '1px solid var(--border-strong)' }}>
      <button
        type="button"
        onClick={() => select(block.node.id)}
        aria-pressed={block.selected}
        aria-label={describe(block)}
        className="block w-full cursor-pointer rounded-t-[22px] bg-transparent text-left"
      >
        <Card data={block} />
      </button>
      {block.branchLabels.map((b) => (
        <span key={b.key} className="absolute -translate-x-1/2 rounded-full bg-surface px-2.5 py-0.5 text-xs font-extrabold text-info ring-1 ring-line-strong" style={{ left: b.x, top: b.y }} data-branch={b.key}>
          {b.label}
        </span>
      ))}
    </div>
  )
}

export function SlotNode({ data }: NodeProps) {
  const slot = data as SlotData
  const { openInsert } = useContext(CanvasContext)
  const highlighted = slot.active || slot.dropTarget

  return (
    <div className="flex h-full flex-col items-center">
      <div className="w-0.5 flex-1 bg-line-strong" />
      <button
        type="button"
        disabled={slot.disabled}
        aria-label={slot.label}
        aria-pressed={slot.active}
        onClick={() => openInsert(slot.point)}
        className={`flex size-[26px] shrink-0 items-center justify-center rounded-full text-[15px] leading-none font-bold disabled:cursor-default disabled:opacity-60 ${
          highlighted ? 'bg-brand text-on-brand shadow-[0_0_0_6px_var(--color-brand-soft)]' : 'border border-line-strong bg-surface text-faint hover:border-brand hover:text-brand-fg'
        }`}
      >
        +
      </button>
      <div className="w-0.5 flex-1 bg-line-strong" />
    </div>
  )
}

export function LabelNode({ data }: NodeProps) {
  return (
    <div className="flex h-full items-end justify-center pb-1 text-xs font-extrabold tracking-wide text-faint uppercase">{(data as LabelData).text}</div>
  )
}
