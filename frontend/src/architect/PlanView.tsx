import { useState } from 'react'
import type { ArchitectAgent, PlanStep } from '../api'
import { STEP_LABEL, stepBadge } from './plan'

const money = (n: number) => `$${n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}`

/** Adıma tıklanınca ilgili ayar (tetikleyici, araçlar ve kısıtlar, onay) özetlenir; düzenleme agent sayfasında (A3.4). */
function StepSettings({ step, agent }: { step: PlanStep; agent: ArchitectAgent }) {
  const lines: string[] = (() => {
    switch (step.type) {
      case 'trigger':
        return agent.triggers.map((t) =>
          t.type === 'manual' ? 'Elle: Şimdi çalıştır' : t.type === 'schedule' ? `Zamanlama: ${t.config.cron} (Europe/Istanbul)` : `Olay: ${t.config.event}${t.config.min_delay_minutes ? ` ≥ ${t.config.min_delay_minutes} dk` : ''}`,
        )
      case 'read':
      case 'lookup':
      case 'draft':
        return agent.tools.filter((t) => t.kind === 'read').map((t) => `${t.label}${t.system ? ` · ${t.system}` : ''}`)
      case 'approve':
        return Object.entries(agent.approval_policy ?? {})
          .filter(([key]) => key !== 'approvers')
          .map(([tool, rule]) => `${tool}: ${(rule as { mode?: string }).mode === 'never' ? 'onaysız' : (rule as { mode?: string }).mode === 'internal_only_auto' ? 'iç alıcılara onaysız, diğerleri onaylı' : 'her zaman onay'}`)
      case 'act':
        return agent.tools
          .filter((t) => t.kind === 'write')
          .map((t) => `${t.label}${t.constraints?.recipients ? ` → ${(t.constraints.recipients as string[]).join(', ')}` : ''}${t.constraints?.channels ? ` → #${(t.constraints.channels as string[]).join(', #')}` : ''}`)
      default:
        return []
    }
  })()

  if (lines.length === 0) return null

  return (
    <ul className="m-0 mt-2 flex list-none flex-col gap-1 border-t border-line p-0 pt-2 text-[11px] text-ink-2">
      {lines.map((line) => (
        <li key={line}>{line}</li>
      ))}
    </ul>
  )
}

/** Architect'in sağ tarafı: agent'ın açıklayıcı planı (dikey kartlar, karar dalı) ve özet kutuları. */
export function PlanView({ agent }: { agent: ArchitectAgent }) {
  const [open, setOpen] = useState<number | null>(null)
  const cost = agent.estimated_cost_usd

  return (
    <div className="flex flex-col items-center gap-5">
      {agent.plan.length === 0 ? (
        <p className="m-0 mt-16 max-w-[320px] text-center text-[13px] text-muted">Architect taslağı hazırlayınca agent'ın adımları burada görünür.</p>
      ) : (
        <ol aria-label="Agent planı" className="m-0 flex w-full max-w-[420px] list-none flex-col items-center p-0">
          {agent.plan.map((step, i) => (
            <li key={i} className="flex w-full flex-col items-center">
              {i > 0 && <span className="h-4 w-px bg-line-strong" aria-hidden />}
              <div className="relative w-full">
                <button
                  type="button"
                  aria-expanded={open === i}
                  onClick={() => setOpen(open === i ? null : i)}
                  data-step={step.type}
                  className={`flex w-full gap-3 rounded-xl border bg-surface px-3.5 py-2.5 text-left shadow-[0_1px_2px_var(--shadow)] hover:border-line-strong ${step.type === 'approve' ? 'border-brand' : 'border-line'}`}
                >
                  <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-raised font-mono text-[10px] font-medium text-ink-2" aria-hidden>
                    {stepBadge(step)}
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="text-[10px] tracking-wide text-muted">{STEP_LABEL[step.type]}</span>
                    <span className="text-[13px] font-medium text-ink">{step.title}</span>
                    {step.detail && <span className="text-[11px] text-muted">{step.detail}</span>}
                    {open === i && <StepSettings step={step} agent={agent} />}
                  </span>
                </button>
                {step.type === 'decide' && step.no && (
                  <span className="mt-1.5 flex items-center gap-2 text-[11px] text-muted md:absolute md:top-1/2 md:left-full md:mt-0 md:ml-3 md:w-48 md:-translate-y-1/2">
                    <span className="hidden h-px w-4 bg-line-strong md:block" aria-hidden />
                    Hayır
                    <span className="rounded-md border border-line bg-surface px-2 py-0.5 text-ink-2">{step.no}</span>
                  </span>
                )}
              </div>
            </li>
          ))}
        </ol>
      )}

      <div className="grid w-full max-w-[560px] gap-2 sm:grid-cols-3">
        <SummaryBox title="Okuduğu sistemler" value={agent.reads.length > 0 ? agent.reads.join(', ') : '—'} />
        <SummaryBox title="Yazdıkları (onaydan sonra)" value={agent.writes.length > 0 ? agent.writes.join(', ') : 'Hiçbir şey yazmaz'} />
        <SummaryBox title="Tahmini maliyet" value={cost === null ? 'Test sonrası hesaplanır' : `≈ ${money(cost)} / çalışma`} />
      </div>
    </div>
  )
}

function SummaryBox({ title, value }: { title: string; value: string }) {
  return (
    <section aria-label={title} className="flex flex-col gap-0.5 rounded-lg border border-line bg-surface px-3 py-2">
      <span className="text-[10px] text-muted">{title}</span>
      <span className="text-[13px] text-ink">{value}</span>
    </section>
  )
}
