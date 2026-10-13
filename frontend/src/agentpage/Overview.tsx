import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts'
import type { AgentDetail, AgentRunRow } from '../api'
import { RUN_STATUS, scoreTone, seconds, stamp, usd } from './format'
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
          <Kpi title="Çalışma · 30 gün" value={String(kpis.runs)} note={kpis.retried > 0 ? `${kpis.retried} yeniden denendi` : 'test hariç'} />
          <Kpi title="Başarı oranı" value={kpis.success_rate === null ? '—' : `%${kpis.success_rate}`} note="biten çalışmalar" />
          <Kpi title="Ortalama süre" value={seconds(kpis.avg_seconds)} note={delta === null ? undefined : `${delta > 0 ? '+' : ''}${delta} sn önceki 30 güne göre`} />
          <Kpi title="Maliyet · 30 gün" value={usd(kpis.cost_usd)} note={kpis.cost_per_run_usd === null ? undefined : `${usd(kpis.cost_per_run_usd)} / çalışma`} />
          <Kpi title="Faydalı oranı" value={kpis.useful_pct === null ? '—' : `%${kpis.useful_pct}`} note={votes > 0 ? `${votes} geri bildirim` : 'geri bildirim yok'} />
          <Kpi title="Onaylanan aksiyonlar" value={String(kpis.actions_approved)} note={`${kpis.actions_total} öneriden`} />
          {kpis.scored_runs > 0 && <Kpi title="Değerlendirme puanı" value={String(kpis.score_avg)} note={`${kpis.scored_runs} çalışma puanlandı`} />}
        </div>

        <section aria-labelledby="runs-chart" className="rounded-xl border border-line bg-surface p-4">
          <div className="mb-2 flex items-center gap-3 text-[11px] text-muted">
            <h2 id="runs-chart" className="m-0 flex-1 text-[13px] font-medium text-ink">
              Son 30 gün çalışmalar
            </h2>
            <Legend tone="bg-ink-2" label="Başarılı" />
            <Legend tone="bg-warn" label="Yeniden denendi" />
            <Legend tone="bg-bad" label="Hata" />
          </div>
          <div className="h-40">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={days} margin={{ top: 4, right: 0, bottom: 0, left: 0 }}>
                <XAxis dataKey="date" tickFormatter={(d: string) => new Date(d).toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })} interval={9} tick={{ fontSize: 10, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
                <Tooltip cursor={{ fill: 'var(--color-raised)' }} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-line)', fontSize: 12 }} />
                <Bar dataKey="succeeded" name="Başarılı" stackId="r" fill="var(--color-ink-2)" />
                <Bar dataKey="retried" name="Yeniden denendi" stackId="r" fill="var(--color-warn)" />
                <Bar dataKey="failed" name="Hata" stackId="r" fill="var(--color-bad)" radius={[2, 2, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </section>

        <section aria-labelledby="recent-runs" className="overflow-hidden rounded-xl border border-line bg-surface">
          <h2 id="recent-runs" className="m-0 border-b border-line px-4 py-2.5 text-[13px] font-medium">
            Son çalışmalar
          </h2>
          <RunsTable agentId={agent.id} runs={agent.recent_runs} />
        </section>
      </div>

      <aside className="flex flex-col gap-4">
        <section aria-labelledby="config-summary" className="rounded-xl border border-line bg-surface p-4">
          <h2 id="config-summary" className="m-0 mb-2 text-[13px] font-medium">
            Yapılandırma
          </h2>
          <dl className="m-0 grid grid-cols-[84px_minmax(0,1fr)] gap-x-2 gap-y-2 text-xs">
            {(
              [
                ['Tetikleyici', config.trigger],
                ['Teslim', config.delivery],
                ['Okuduğu', config.reads],
                ['Yazdığı', config.writes],
                ['Model', config.model],
                ['Bellek', config.memory],
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
            Geri bildirim · 30 gün
          </h2>
          {votes === 0 ? (
            <p className="m-0 text-xs text-muted">Henüz geri bildirim yok. Çalışma ayrıntısında "Faydalı" / "Faydalı değil" ile verilebilir.</p>
          ) : (
            <div className="flex flex-col gap-2 text-xs">
              <Share label="Faydalı" n={kpis.feedback.useful} total={votes} tone="bg-ok" />
              <Share label="Faydalı değil" n={kpis.feedback.not_useful} total={votes} tone="bg-bad" />
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
          {n} · %{pct}
        </span>
      </span>
      <span className="h-1.5 overflow-hidden rounded-full bg-raised">
        <span className={`block h-full ${tone}`} style={{ width: `${pct}%` }} />
      </span>
    </div>
  )
}

const ORIGIN: Record<AgentRunRow['origin'], string> = { manual: 'Elle', schedule: 'Zamanlama', event: 'Olay', chat: 'Sohbet' }

export function RunsTable({ agentId, runs }: { agentId: number; runs: AgentRunRow[] }) {
  if (runs.length === 0) return <p className="m-0 px-4 py-6 text-center text-[13px] text-muted">Henüz çalışma yok.</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="bg-canvas text-left text-xs text-muted">
            <th className="px-4 py-2 font-medium">Başlangıç</th>
            <th className="px-3 py-2 font-medium">Durum</th>
            <th className="px-3 py-2 font-medium">Kaynak</th>
            <th className="px-3 py-2 text-right font-medium">Süre</th>
            <th className="px-3 py-2 text-right font-medium">Maliyet</th>
            <th className="px-3 py-2 text-right font-medium">Aksiyonlar</th>
            <th className="px-3 py-2 text-right font-medium">Puan</th>
            <th className="px-4 py-2 font-medium">İz</th>
          </tr>
        </thead>
        <tbody>
          {runs.map((run) => {
            const [label, dot] = RUN_STATUS[run.status]
            return (
              <tr key={run.id} className="border-t border-line">
                <td className="px-4 py-2 font-mono text-xs whitespace-nowrap">{stamp(run.started_at)}</td>
                <td className="px-3 py-2 whitespace-nowrap">
                  <span className="inline-flex items-center gap-1.5">
                    <span className={`size-1.5 rounded-full ${dot}`} aria-hidden />
                    {label}
                    {run.attempts > 1 && <span className="text-[11px] text-brand-fg">· yeniden denendi</span>}
                    {run.is_test && <span className="text-[11px] text-muted">· test</span>}
                  </span>
                </td>
                <td className="px-3 py-2 text-xs text-muted">{ORIGIN[run.origin]}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">{seconds(run.duration_seconds)}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">{usd(run.cost_usd)}</td>
                <td className="px-3 py-2 text-right font-mono text-xs">
                  {run.actions_approved}/{run.actions_total}
                </td>
                <td className={`px-3 py-2 text-right font-mono text-xs ${scoreTone(run.score)}`}>{run.score ?? '—'}</td>
                <td className="px-4 py-2">
                  <a href={href({ name: 'agent', agentId, tab: 'runs', runId: run.id })} className="text-xs text-brand-fg">
                    Gör
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
