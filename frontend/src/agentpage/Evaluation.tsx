import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { agentsApi, type AgentEvaluationInfo } from '../api'
import { href } from '../router'
import { needsData, scoreTone, stamp } from './format'
import { formatPercent, t } from '../i18n'

const examples = () => [t('agent.eval.example1'), t('agent.eval.example2'), t('agent.eval.example3')]
const MAX = 10

/**
 * Değerlendirme sekmesi: kurallar ve açık/kapalı. Açıksa her çalışmadan sonra hızlı model çıktıyı kurallara göre
 * puanlar; puanlamaya sadece çıktı metni gider. Kural başına geçme oranı ve son puanlar.
 */
export function Evaluation({ agentId }: { agentId: number }) {
  const evaluation = useQuery({ queryKey: ['agent-evaluation', agentId], queryFn: () => agentsApi.evaluation(agentId) })
  if (!evaluation.data) return null

  return <EvaluationForm key={agentId} agentId={agentId} data={evaluation.data} />
}

function EvaluationForm({ agentId, data }: { agentId: number; data: AgentEvaluationInfo }) {
  const queryClient = useQueryClient()
  const [rules, setRules] = useState<string[]>(data.criteria.length > 0 ? data.criteria : [''])
  const [enabled, setEnabled] = useState(data.enabled)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'ok' | 'bad'; text: string } | null>(null)
  const clean = rules.map((r) => r.trim()).filter(Boolean)
  const dirty = JSON.stringify(clean) !== JSON.stringify(data.criteria) || enabled !== data.enabled

  const save = async () => {
    setBusy(true)
    setMessage(null)
    try {
      const saved = await agentsApi.saveEvaluation(agentId, clean, enabled)
      queryClient.setQueryData(['agent-evaluation', agentId], saved)
      queryClient.invalidateQueries({ queryKey: ['agent', agentId] })
      setMessage({ tone: 'ok', text: saved.enabled ? t('agent.eval.saved_on') : t('agent.eval.saved_off') })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
      <form
        aria-label={t('agent.eval.rules_label')}
        className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4"
        onSubmit={(e) => {
          e.preventDefault()
          save()
        }}
      >
        <div className="flex items-center gap-3">
          <h2 className="m-0 flex-1 text-[13px] font-medium">{t('agent.eval.rules')}</h2>
          <label className="flex items-center gap-2 text-[13px]">
            <input type="checkbox" checked={enabled} disabled={!data.can_edit} onChange={(e) => setEnabled(e.target.checked)} className="accent-brand" />
            {t('agent.eval.score_each')}
          </label>
        </div>
        <p className="m-0 text-xs text-muted">
          {t('agent.eval.explain')}
        </p>
        <ol className="m-0 flex list-none flex-col gap-2 p-0">
          {rules.map((rule, i) => (
            <li key={i} className="flex flex-col gap-1">
              <div className="flex items-center gap-2">
              <span className="w-5 shrink-0 text-right font-mono text-xs text-muted">{i + 1}.</span>
              <input
                value={rule}
                disabled={!data.can_edit}
                onChange={(e) => setRules(rules.map((r, j) => (j === i ? e.target.value : r)))}
                aria-label={t('agent.eval.rule_n', { n: i + 1 })}
                placeholder={examples()[i % 3]}
                maxLength={200}
                className="flex-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px] text-ink disabled:opacity-70"
              />
              {data.can_edit && (
                <button type="button" aria-label={t('agent.eval.remove_rule', { n: i + 1 })} onClick={() => setRules(rules.length > 1 ? rules.filter((_, j) => j !== i) : [''])} className="text-xs text-muted hover:text-bad">
                  {t('agent.eval.remove')}
                </button>
              )}
              </div>
              {needsData(rule) && (
                <p role="note" className="m-0 ml-7 rounded-md bg-warn-soft px-2 py-1 text-[11px] text-ink-2">
                  {t('agent.eval.needs_data')}
                </p>
              )}
            </li>
          ))}
        </ol>
        {data.can_edit && (
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" disabled={rules.length >= MAX} onClick={() => setRules([...rules, ''])} className="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-2 hover:bg-raised disabled:opacity-50">
              + {t('agent.eval.add_rule')}
            </button>
            <span className="flex-1" />
            <button type="submit" disabled={busy || !dirty} className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
              {t('agent.eval.save')}
            </button>
          </div>
        )}
        {!data.can_edit && <p className="m-0 text-xs text-muted">{t('agent.eval.readonly')}</p>}
        {message && (
          <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
            {message.text}
          </p>
        )}
      </form>

      <aside className="flex flex-col gap-4">
        <section aria-label={t('agent.eval.summary')} className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4">
          <span className="text-[11px] text-muted">{t('agent.eval.avg_30d')}</span>
          <span className={`font-serif text-[28px] leading-none ${scoreTone(data.avg_score_30d)}`}>{data.avg_score_30d ?? '—'}</span>
          <span className="text-[11px] text-muted">{t('agent.eval.scored_runs', { n: data.scored_runs_30d })}</span>
        </section>
        {data.rule_pass_rates.length > 0 && (
          <section aria-label={t('agent.eval.pass_by_rule')} className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4 text-xs">
            <h2 className="m-0 text-[13px] font-medium">{t('agent.eval.pass_by_rule')}</h2>
            {data.rule_pass_rates.map((r) => (
              <div key={r.rule} className="flex flex-col gap-1">
                <span className="flex justify-between gap-2 text-ink-2">
                  <span className="min-w-0">{r.rule}</span>
                  <span className="shrink-0 font-mono">{r.pass_pct === null ? '—' : formatPercent(r.pass_pct, 0)}</span>
                </span>
                <span className="h-1.5 overflow-hidden rounded-full bg-raised">
                  <span className="block h-full bg-ok" style={{ width: `${r.pass_pct ?? 0}%` }} />
                </span>
              </div>
            ))}
          </section>
        )}
        {data.recent.length > 0 && (
          <section aria-label={t('agent.eval.recent')} className="flex flex-col gap-1 rounded-xl border border-line bg-surface p-4 text-xs">
            <h2 className="m-0 mb-1 text-[13px] font-medium">{t('agent.eval.recent')}</h2>
            {data.recent.map((r) => (
              <a key={r.run_id} href={href({ name: 'agent', agentId, tab: 'runs', runId: r.run_id })} className="flex justify-between text-ink-2 no-underline hover:text-ink">
                <span className="font-mono">
                  #{r.run_id} · {stamp(r.started_at)}
                </span>
                <span className={`font-mono ${scoreTone(r.score)}`}>{r.score}</span>
              </a>
            ))}
          </section>
        )}
      </aside>
    </div>
  )
}
