import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { governanceApi, type GovernanceAlertInfo, type LiveRun } from '../api'
import { Dialog } from '../ui/Dialog'
import { ago } from '../ui/format'

const usd = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `$${n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`)
const duration = (s: number) => (s < 60 ? `${s} sn` : s < 3600 ? `${Math.floor(s / 60)} dk ${s % 60} sn` : `${Math.floor(s / 3600)} sa ${Math.floor((s % 3600) / 60)} dk`)

const LIVE_STATUS: Record<string, [string, string]> = {
  queued: ['Kuyrukta', 'bg-raised text-ink-2'],
  running: ['Çalışıyor', 'bg-info-soft text-info'],
  waiting_input: ['Soru bekliyor', 'bg-warn-soft text-warn'],
  waiting_approval: ['Onay bekliyor', 'bg-warn-soft text-warn'],
  paused: ['Onay bekliyor', 'bg-warn-soft text-warn'],
  created: ['Başlıyor', 'bg-raised text-ink-2'],
  restarted: ['Yeniden deneniyor', 'bg-warn-soft text-warn'],
}

const SEVERITY: Record<GovernanceAlertInfo['severity'], [string, string]> = {
  critical: ['Kritik', 'bg-bad'],
  warning: ['Uyarı', 'bg-warn'],
  info: ['Bilgi', 'bg-info'],
}

/** Yönetişim Genel bakış (admin): bu ay, canlı çalışmalar (10 sn), dikkat gerekenler, kalite. */
export function GovernanceOverviewView() {
  const queryClient = useQueryClient()
  const overview = useQuery({ queryKey: ['governance', 'overview'], queryFn: governanceApi.overview, refetchInterval: 10_000 })
  const [stopping, setStopping] = useState<LiveRun | null>(null)
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState<{ tone: 'ok' | 'bad'; text: string } | null>(null)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['governance', 'overview'] })

  if (overview.isError) return <p role="alert" className="m-8 text-sm text-bad">Genel bakış yüklenemedi.</p>
  if (!overview.data) return null
  const { live, month, alerts, quality } = overview.data
  const updated = new Date(overview.dataUpdatedAt).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })

  const stop = async () => {
    if (!stopping) return
    const run = stopping
    setStopping(null)
    try {
      await governanceApi.stop(run, reason.trim())
      setMessage({ tone: 'ok', text: `${run.name} durduruldu.` })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    }
    setReason('')
    refresh()
  }

  const resolve = async (alert: GovernanceAlertInfo) => {
    try {
      await governanceApi.resolve(alert.id)
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    }
    refresh()
  }

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:px-8">
        <header className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Yönetişim</h1>
          <span className="flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-[11px] text-ok">
            <span className="size-1.5 rounded-full bg-ok" aria-hidden /> Canlı · {updated}
          </span>
          <p className="m-0 w-full text-[13px] text-muted">Her agent ve akış şu an ne yapıyor, ne kadar harcandı ve neye dikkat etmek gerekiyor.</p>
        </header>

        {message && (
          <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
            {message.text}
          </p>
        )}

        <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
          <Stat title={`Harcama · ${month.label}`} value={usd(month.spent_usd)} note={month.budget_usd ? `bütçe ${usd(month.budget_usd)}` : 'bütçe tanımsız'} bar={month.ratio} />
          <Stat title="Geçen aya göre" value={month.change_pct === null ? '—' : `${month.change_pct > 0 ? '+' : ''}%${month.change_pct}`} note={`aynı dönem ${usd(month.previous_same_period_usd)}`} />
          <Stat title="Şu an çalışan" value={String(live.length)} note={`${live.filter((r) => r.status.startsWith('waiting') || r.status === 'paused').length} kişi bekliyor`} />
          <Stat title="Soru · bu ay" value={month.questions.toLocaleString('tr-TR')} note="sohbet soruları" />
          <Stat title="Agent çalışması · bu ay" value={month.agent_runs.toLocaleString('tr-TR')} note="test hariç" />
          <Stat title="Aksiyonlar · bu ay" value={`${month.actions_approved} / ${month.actions_rejected}`} note="onaylanan / reddedilen" />
        </div>

        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
          <section aria-labelledby="live-runs" className="overflow-hidden rounded-xl border border-line bg-surface">
            <h2 id="live-runs" className="m-0 flex items-center gap-2 border-b border-line px-4 py-2.5 text-[13px] font-medium">
              Canlı çalışmalar <span className="font-normal text-muted">· {live.length}</span>
            </h2>
            {live.length === 0 ? (
              <p className="m-0 px-4 py-8 text-center text-[13px] text-muted">Şu an çalışan agent veya akış yok.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                  <thead>
                    <tr className="bg-canvas text-left text-xs text-muted">
                      <th className="px-4 py-2 font-medium">Çalışma</th>
                      <th className="px-3 py-2 font-medium">Durum</th>
                      <th className="px-3 py-2 text-right font-medium">Süre</th>
                      <th className="px-3 py-2 text-right font-medium">Araç</th>
                      <th className="px-3 py-2 text-right font-medium">Maliyet</th>
                      <th className="px-4 py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {live.map((run) => {
                      const [label, tone] = LIVE_STATUS[run.status] ?? [run.status, 'bg-raised text-ink-2']
                      return (
                        <tr key={`${run.kind}-${run.id}`} className="border-t border-line">
                          <td className="px-4 py-2">
                            <span className="flex flex-col">
                              {run.link ? (
                                <a href={run.link} className="font-medium text-ink">
                                  {run.name}
                                </a>
                              ) : (
                                <span className="font-medium">{run.name}</span>
                              )}
                              <span className="text-[11px] text-muted">
                                {run.kind === 'agent' ? 'Agent' : 'Akış'} · {run.started_by}
                                {run.is_test ? ' · test' : ''}
                              </span>
                            </span>
                          </td>
                          <td className="px-3 py-2">
                            <span className={`rounded px-1.5 py-px text-[11px] ${tone}`}>{label}</span>
                          </td>
                          <td className="px-3 py-2 text-right font-mono text-xs">{duration(run.seconds)}</td>
                          <td className="px-3 py-2 text-right font-mono text-xs">{run.tool_calls ?? '—'}</td>
                          <td className="px-3 py-2 text-right font-mono text-xs">{usd(run.cost_usd)}</td>
                          <td className="px-4 py-2 text-right">
                            <button type="button" onClick={() => setStopping(run)} aria-label={`${run.name} çalışmasını durdur`} className="rounded-md border border-line px-2 py-0.5 text-xs text-bad hover:bg-bad-soft">
                              Durdur
                            </button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section aria-labelledby="attention" className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4">
            <h2 id="attention" className="m-0 text-[13px] font-medium">
              Dikkat gerekenler <span className="font-normal text-muted">· {alerts.length}</span>
            </h2>
            {alerts.length === 0 && <p className="m-0 text-xs text-muted">Şu an dikkat gerektiren bir şey yok.</p>}
            <ul className="m-0 flex list-none flex-col gap-2 p-0">
              {alerts.map((alert) => {
                const [label, dot] = SEVERITY[alert.severity]
                return (
                  <li key={alert.id} className={`flex flex-col gap-1 rounded-lg px-3 py-2 text-xs ${alert.severity === 'critical' ? 'bg-bad-soft' : 'bg-canvas'}`}>
                    <span className="flex items-start gap-2">
                      <span className={`mt-1 size-1.5 shrink-0 rounded-full ${dot}`} role="img" aria-label={label} />
                      <span className="flex-1 text-ink">{alert.message}</span>
                    </span>
                    <span className="flex items-center gap-2 pl-3.5 text-muted">
                      <span className="font-mono text-[10px]">{ago(alert.updated_at)}</span>
                      <span className="flex-1" />
                      {alert.link && (
                        <a href={alert.link} className="text-brand-fg">
                          Git
                        </a>
                      )}
                      <button type="button" onClick={() => resolve(alert)} className="text-ink-2 hover:text-ink">
                        Çözüldü
                      </button>
                    </span>
                  </li>
                )
              })}
            </ul>
          </section>
        </div>

        <section aria-labelledby="quality" className="grid gap-4 rounded-xl border border-line bg-surface p-4 md:grid-cols-3">
          <h2 id="quality" className="sr-only">
            Kalite
          </h2>
          <div className="flex flex-col gap-1">
            <span className="text-[11px] text-muted">Değerlendirme seti (son tam çalıştırma{quality.eval_total_questions ? ` · set ${quality.eval_total_questions} soru` : ''})</span>
            {quality.eval ? (
              <>
                <span className="font-serif text-[24px] leading-none">
                  {quality.eval.passed}/{quality.eval.questions}
                </span>
                <span className="text-[11px] text-muted">
                  doğru araç %{Math.round((quality.eval.correct_tool_ratio ?? 0) * 100)} · {quality.eval.run_at ? ago(quality.eval.run_at) : ''}
                </span>
              </>
            ) : (
              <span className="text-[13px] text-muted">Henüz tam çalıştırma yok (php artisan fortis:eval).</span>
            )}
            {quality.eval_partial && (
              <span className="text-[11px] text-muted">
                Sonra kısmi: {quality.eval_partial.passed}/{quality.eval_partial.questions} · {quality.eval_partial.run_at ? ago(quality.eval_partial.run_at) : ''}
              </span>
            )}
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-[11px] text-muted">Agent değerlendirme puanı · 30 gün</span>
            <span className="font-serif text-[24px] leading-none">{quality.agent_score_avg ?? '—'}</span>
            <span className="text-[11px] text-muted">{quality.scored_runs} çalışma puanlandı</span>
          </div>
          <div className="flex flex-col gap-1 text-xs">
            <span className="text-[11px] text-muted">En düşük puanlı agentlar</span>
            {quality.agents.length === 0 && <span className="text-muted">—</span>}
            {quality.agents.map((a) => (
              <a key={a.id} href={`#/agents/${a.id}/evaluation`} className="flex justify-between text-ink-2 no-underline hover:text-ink">
                <span>{a.name}</span>
                <span className="font-mono">{a.score_avg}</span>
              </a>
            ))}
          </div>
        </section>
      </div>

      {stopping && (
        <Dialog title={`${stopping.name} durdurulsun mu?`} onClose={() => setStopping(null)}>
          <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              stop()
            }}
          >
            <p className="m-0 text-sm text-ink-2">
              {stopping.kind === 'agent' ? 'Agent çalışması iptal edilir; bekleyen gönderimleri reddedilir.' : "Akış çalışması Kestra'da durdurulur."} İşlem senin adınla denetim kaydına yazılır.
            </p>
            <label className="flex flex-col gap-1 text-xs text-muted">
              Gerekçe (zorunlu)
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} required minLength={3} maxLength={500} className="rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink" />
            </label>
            <span className="flex justify-end gap-2">
              <button type="button" onClick={() => setStopping(null)} className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised">
                Vazgeç
              </button>
              <button type="submit" disabled={reason.trim().length < 3} className="rounded-lg bg-bad px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
                Çalışmayı durdur
              </button>
            </span>
          </form>
        </Dialog>
      )}
    </main>
  )
}

function Stat({ title, value, note, bar }: { title: string; value: string; note?: string; bar?: number | null }) {
  return (
    <section aria-label={title} className="flex flex-col gap-1 rounded-xl border border-line bg-surface px-3.5 py-3">
      <span className="text-[11px] text-muted">{title}</span>
      <span className="font-serif text-[24px] leading-none text-ink">{value}</span>
      {bar !== undefined && bar !== null && (
        <span className="mt-1 h-1.5 overflow-hidden rounded-full bg-raised" aria-hidden>
          <span className={`block h-full ${bar >= 1 ? 'bg-bad' : bar >= 0.8 ? 'bg-warn' : 'bg-ink-2'}`} style={{ width: `${Math.min(100, Math.round(bar * 100))}%` }} />
        </span>
      )}
      {note && <span className="text-[11px] text-muted">{note}</span>}
    </section>
  )
}
