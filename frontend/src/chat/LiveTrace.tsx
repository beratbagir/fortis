import { useEffect, useState } from 'react'
import type { TraceStep } from '../api'
import { querySource, toolLabel } from '../toolLabels'
import { CheckIcon, ChevronIcon, SparkIcon, WarnIcon } from '../ui/icons'
import { queryText } from './chatUtils'
import { intlLocale, t } from '../i18n'

const seconds = (ms: number) => t('chat.trace.seconds', { n: (ms / 1000).toLocaleString(intlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) })
const count = (n: number) => n.toLocaleString(intlLocale())

/** Adımın başlığı ve alt satırları. */
function describe(step: TraceStep): { title: string; lines: string[]; mono?: string; footer?: string } {
  if (step.kind === 'query') {
    const source = step.source ?? querySource(step.tool ?? '')
    const lines: string[] = []
    if (step.status === 'error') lines.push(step.error ?? t('chat.trace.query_failed'))
    if (step.rows !== undefined) {
      lines.push(
        [
          step.total_rows !== undefined && step.total_rows > step.rows
            ? t('chat.trace.rows_of', { rows: count(step.rows), total: count(step.total_rows) })
            : t('chat.trace.rows', { rows: count(step.rows) }),
          step.masked ? t('chat.masked') : null,
        ]
          .filter(Boolean)
          .join(' · '),
      )
    }

    return {
      title: `${t('chat.trace.querying', { source })}${step.purpose ? ` · ${step.purpose}` : ''}`,
      lines,
      mono: step.query ? queryText(step.query) : undefined,
      footer: step.status === 'done' ? t('chat.trace.fetched', { source }) : undefined,
    }
  }

  if (step.kind === 'tool') {
    return { title: toolLabel(step.tool ?? null), lines: step.status === 'error' && step.error ? [step.error] : [] }
  }

  return { title: step.label ?? '', lines: step.detail ? [step.detail] : [] }
}

function StepMark({ status }: { status: TraceStep['status'] }) {
  if (status === 'done') return <CheckIcon className="text-ok" />
  if (status === 'error') return <WarnIcon className="text-bad" />
  if (status === 'waiting') return <span className="block size-3 rounded-full border-2 border-dashed border-warn" aria-hidden />

  return <span className="block size-3 animate-spin rounded-full border-2 border-line-strong border-t-brand" aria-hidden />
}

interface Props {
  steps: TraceStep[]
  /** Cevap hâlâ hazırlanıyor (süre canlı sayar). */
  running: boolean
  /** Bitmiş cevapta toplam süre. */
  durationMs?: number
  onAudit?: () => void
  /** Bitmiş izi açık başlat (ör. soru kartı bekleniyor). */
  defaultOpen?: boolean
}

/**
 * Cevabın canlı izi: model seçimi, izin kapsamı, sorgular, diğer araçlar, bekleyen soru. Cevap bitince kendiliğinden
 * daralır ("31,4 sn düşündü · 41 adım · Denetim kaydı").
 */
export function LiveTrace({ steps, running, durationMs, onAudit, defaultOpen = false }: Props) {
  const [open, setOpen] = useState(running || defaultOpen)
  const [started] = useState(() => Date.now())
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!running) return
    const timer = setInterval(() => setNow(Date.now()), 100)
    return () => clearInterval(timer)
  }, [running])

  // Cevap bitince iz daralır.
  const [wasRunning, setWasRunning] = useState(running)
  if (wasRunning !== running) {
    setWasRunning(running)
    if (!running) setOpen(false)
  }

  const elapsed = running ? now - started : (durationMs ?? 0)
  const last = steps.slice(-4)

  return (
    <section aria-label={t('chat.trace.aria')} className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2 text-[13px] text-muted">
        <SparkIcon className="text-brand-fg" />
        <button type="button" aria-expanded={open} onClick={() => setOpen((o) => !o)} className="inline-flex items-center gap-2 rounded-md hover:text-ink">
          {running ? <span className="font-medium text-ink">{t('chat.trace.thinking')}</span> : <span>{t('chat.trace.thought', { time: seconds(elapsed) })}</span>}
          {running && <span className="font-mono text-xs">{seconds(elapsed)}</span>}
          <span className="font-mono text-xs">· {t('chat.trace.steps', { count: steps.length })}</span>
          <span className="flex gap-0.5" aria-hidden>
            {last.map((s) => (
              <span key={s.id} className={`block size-2.5 rounded-[3px] ${s.status === 'error' ? 'bg-bad' : s.status === 'running' ? 'bg-line-strong' : s.status === 'waiting' ? 'bg-warn' : 'bg-faint'}`} />
            ))}
          </span>
          <ChevronIcon open={open} />
        </button>
        {!running && onAudit && (
          <button type="button" onClick={onAudit} className="text-xs text-muted underline-offset-2 hover:text-ink hover:underline">
            {t('chat.audit.title')}
          </button>
        )}
      </div>

      {open && (
        <ol className="m-0 ml-1.5 flex list-none flex-col gap-3 border-l border-line p-0 pl-4">
          {steps.map((step) => {
            const d = describe(step)
            return (
              <li key={step.id} className="relative flex flex-col gap-0.5 text-[13px]">
                <span className="absolute top-0.5 -left-[23px] flex size-3.5 items-center justify-center bg-bg">
                  <StepMark status={step.status} />
                </span>
                <span className={`font-medium ${step.status === 'error' ? 'text-bad' : 'text-ink'}`}>{d.title}</span>
                {d.lines.map((line, i) => (
                  <span key={i} className={step.status === 'error' ? 'text-bad' : 'text-muted'}>
                    {line}
                  </span>
                ))}
                {step.kind === 'scope' && step.sources && (
                  <span className="mt-1 flex flex-wrap gap-1">
                    {step.sources.map((s) => (
                      <span key={s.key} className={`rounded-md border px-1.5 py-0.5 text-[11px] ${s.in_scope ? 'border-line text-ink-2' : 'border-line text-faint line-through'}`}>
                        {s.label}
                      </span>
                    ))}
                  </span>
                )}
                {d.mono && <span className="truncate font-mono text-[11px] text-faint">{d.mono}</span>}
                {d.footer && <span className="text-[11px] text-faint">{d.footer}</span>}
              </li>
            )
          })}
        </ol>
      )}
    </section>
  )
}
