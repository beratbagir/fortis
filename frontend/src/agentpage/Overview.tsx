import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts'
import type { AgentDetail, AgentRunRow } from '../api'
import { runStatus, scoreTone, seconds, stamp, usd } from './format'
import { formatPercent, intlLocale, t } from '../i18n'
import { href } from '../router'

function Kpi({ title, value, note }: { title: string; value: string; note?: string }) {
  return (
    <section aria-label={title} className="flex flex-col gap-1 rounded-xl border border-line bg-surface px-3.5 py-3">
      <span className="text-[11px] text-muted">{title}</span>
      <span className="font-serif text-[24px] leading-none text-ink">{value}</span>
      {note && <span className="text-[11px] text-muted">{note}</span>}
    </section>
  )
}

/** Genel bakış: 6 ölçü, son 30 gün çalışmalar, son çalışmalar, yapılandırma özeti ve geri bildirim dağılımı. */
export function Overview({ agent }: { agent: AgentDetail }) {
  const { kpis, days, config } = agent.overview
  const delta = kpis.avg_seconds_delta
  const votes = kpis.feedback.useful + kpis.feedback.not_useful

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
      <div className="flex min-w-0 flex-col gap-4">
        <div className="grid grid-cols-2 gap-2 [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))]">
          <Kpi title={t('agent.overview.runs_30d')} value={String(kpis.runs)} note={kpis.retried > 0 ? t('agent.overview.retried', { n: kpis.retried }) : t('agent.overview.excl_test')} />
          <Kpi title={t('agent.overview.success_rate')} value={kpis.success_rate === null ? '—' : formatPercent(kpis.success_rate, 0)} note={t('agent.overview.finished_runs')} />
          <Kpi title={t('agent.overview.avg_duration')} value={seconds(kpis.avg_seconds)} note={delta === null ? undefined : t('agent.overview.delta', { delta: `${delta > 0 ? '+' : ''}${delta}` })} />
          <Kpi title={t('agent.overview.cost_30d')} value={usd(kpis.cost_usd)} note={kpis.cost_per_run_usd === null ? undefined : t('agent.overview.per_run', { cost: usd(kpis.cost_per_run_usd) })} />
          <Kpi title={t('agent.overview.useful_rate')} value={kpis.useful_pct === null ? '—' : formatPercent(kpis.useful_pct, 0)} note={votes > 0 ? t('agent.overview.feedback_n', { n: votes }) : t('agent.overview.no_feedback')} />
          <Kpi title={t('agent.overview.approved_actions')} value={String(kpis.actions_approved)} note={t('agent.overview.of_proposed', { n: kpis.actions_total })} />
          {kpis.scored_runs > 0 && <Kpi title={t('agent.overview.eval_score')} value={String(kpis.score_avg)} note={t('agent.eval.scored_runs', { n: kpis.scored_runs })} />}
        </div>

        <section aria-labelledby="runs-chart" className="rounded-xl border border-line bg-surface p-4">
          <div className="mb-2 flex items-center gap-3 text-[11px] text-muted">
            <h2 id="runs-chart" className="m-0 flex-1 text-[13px] font-medium text-ink">
              {t('agent.overview.chart_title')}
            </h2>
            <Legend tone="bg-ink-2" label={t('agent.overview.succeeded')} />
            <Legend tone="bg-warn" label={t('agent.overview.retried_label')} />
            <Legend tone="bg-bad" label={t('agent.overview.failed')} />
          </div>
          <div className="h-40">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={days} margin={{ top: 4, right: 0, bottom: 0, left: 0 }}>
                <XAxis dataKey="date" tickFormatter={(d: string) => new Date(d).toLocaleDateString(intlLocale(), { day: 'numeric', month: 'short' })} interval={9} tick={{ fontSize: 10, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
                <Tooltip cursor={{ fill: 'var(--color-raised)' }} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-line)', fontSize: 12 }} />
                <Bar dataKey="succeeded" name={t('agent.overview.succeeded')} stackId="r" fill="var(--color-ink-2)" />
                <Bar dataKey="retried" name={t('agent.overview.retried_label')} stackId="r" fill="var(--color-warn)" />
                <Bar dataKey="failed" name={t('agent.overview.failed')} stackId="r" fill="var(--color-bad)" radius={[2, 2, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </section>

        <section aria-labelledby="recent-runs" className="overflow-hidden rounded-xl border border-line bg-surface">
          <h2 id="recent-runs" className="m-0 border-b border-line px-4 py-2.5 text-[13px] font-medium">
            {t('agent.overview.recent_runs')}
          </h2>
          <RunsTable agentId={agent.id} runs={agent.recent_runs} />
        </section>
      </div>

      <aside className="flex flex-col gap-4">
        <section aria-labelledby="config-summary" className="rounded-xl border border-line bg-surface p-4">
          <h2 id="config-summary" className="m-0 mb-2 text-[13px] font-medium">
            {t('agent.overview.config')}
          </h2>
          <dl className="m-0 grid grid-cols-[84px_minmax(0,1fr)] gap-x-2 gap-y-2 text-xs">
            {(
              [
                [t('agent.overview.cfg.trigger'), config.trigger],
                [t('agent.overview.cfg.delivery'), config.delivery],
                [t('agent.overview.cfg.reads'), config.reads],
                [t('agent.overview.cfg.writes'), config.writes],
                [t('agent.overview.cfg.model'), config.model],
                [t('agent.overview.cfg.memory'), config.memory],
              ] as const
            ).map(([k, v]) => (
              <div key={k} className="contents">
                <dt className="text-muted">{k}</dt>
                <dd className="m-0 break-words text-ink-2">{v}</dd>
              </div>
            ))}
          </dl>
        </section>
        <section aria-labelledby="feedback-summary" className="rounded-xl border border-line bg-surface p-4">
          <h2 id="feedback-summary" className="m-0 mb-2 text-[13px] font-medium">
            {t('agent.overview.feedback_30d')}
          </h2>
          {votes === 0 ? (
            <p className="m-0 text-xs text-muted">{t('agent.overview.feedback_empty')}</p>
          ) : (
            <div className="flex flex-col gap-2 text-xs">
              <Share label={t('agent.overview.useful')} n={kpis.feedback.useful} total={votes} tone="bg-ok" />
              <Share label={t('agent.overview.not_useful')} n={kpis.feedback.not_useful} total={votes} tone="bg-bad" />
            </div>
          )}
        </section>
      </aside>
    </div>
  )
}

function Legend({ tone, label }: { tone: string; label: string }) {
  return (
    <span className="flex items-center gap-1">
      <span className={`size-2 rounded-sm ${tone}`} aria-hidden />
      {label}
    </span>
  )
}

function Share({ label, n, total, tone }: { label: string; n: number; total: number; tone: string }) {
  const pct = Math.round((100 * n) / total)
  return (
    <div className="flex flex-col gap-1">
      <span className="flex justify-between text-ink-2">
        {label}
        <span className="font-mono">
          {n} · {formatPercent(pct, 0)}
        </span>
      </span>
      <span className="h-1.5 overflow-hidden rounded-full bg-raised">
        <span className={`block h-full ${tone}`} style={{ width: `${pct}%` }} />
      </span>
    </div>
  )
}

/** Çalışmanın kaynağı (elle, zamanlama, olay, sohbet). */
export const originLabel = (origin: AgentRunRow['origin']) => t(`agent.origin.${origin}`)

export function RunsTable({ agentId, runs }: { agentId: number; runs: AgentRunRow[] }) {
  if (runs.length === 0) return <p className="m-0 px-4 py-6 text-center text-[13px] text-muted">{t('agent.runs.empty')}</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="bg-canvas text-left text-xs text-muted">
            <th className="px-4 py-2 font-medium">{t('agent.runs.col.start')}</th>
            <th className="px-3 py-2 font-medium">{t('agent.runs.col.status')}</th>
            <th className="px-3 py-2 font-medium">{t('agent.runs.col.origin')}</th>
            <th className="px-3 py-2 text-right font-medium">{t('agent.runs.col.duration')}</th>
            <th className="px-3 py-2 text-right font-medium">{t('agent.runs.col.cost')}</th>
            <th className="px-3 py-2 text-right font-medium">{t('agent.runs.col.actions')}</th>
            <th className="px-3 py-2 text-right font-medium">{t('agent.runs.col.score')}</th>
            <th className="px-4 py-2 font-medium">{t('agent.runs.col.trace')}</th>
          </tr>
        </thead>
        <tbody>
          {runs.map((run) => {
            const [label, dot] = runStatus(run.status)
            return (
              <tr key={run.id} className="border-t border-line">
                <td className="px-4 py-2 font-mono text-xs whitespace-nowrap">{stamp(run.started_at)}</td>
                <td className="px-3 py-2 whitespace-nowrap">
                  <span className="inline-flex items-center gap-1.5">
                    <span className={`size-1.5 rounded-full ${dot}`} aria-hidden />
                    {label}
                    {run.attempts > 1 && <span className="text-[11px] text-brand-fg">· {t('agent.runs.retried')}</span>}
                    {run.is_test && <span className="text-[11px] text-muted">· test</span>}
                  </span>
                </td>
                <td className="px-3 py-2 text-xs text-muted">{originLabel(run.origin)}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">{seconds(run.duration_seconds)}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">{usd(run.cost_usd)}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">
                  {run.actions_approved}/{run.actions_total}
                </td>
                <td className={`px-3 py-2 text-right font-mono text-xs ${scoreTone(run.score)}`}>{run.score ?? '—'}</td>
                <td className="px-4 py-2">
                  <a href={href({ name: 'agent', agentId, tab: 'runs', runId: run.id })} className="text-xs text-brand-fg">
                    {t('agent.runs.view')}
                  </a>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
