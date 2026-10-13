import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { usageApi, type UsageSummary } from '../editor/api'
import { href } from '../router'
import { money, tokens } from '../ui/format'

// Grafik renkleri tema token'larında (--chart-1 mavi, --chart-2 turuncu); renk körlüğü ayrımı ve yüzey kontrastı her iki temada korunur.
const CHAT = 'var(--chart-1)'
const FLOW_STEP = 'var(--chart-2)'

const monthLabel = new Intl.DateTimeFormat('tr-TR', { month: 'long', year: 'numeric' })

function lastMonths(count: number): { value: string; label: string }[] {
  const now = new Date()
  return Array.from({ length: count }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    const label = monthLabel.format(d)
    return { value, label: label.charAt(0).toLocaleUpperCase('tr') + label.slice(1) }
  })
}

export function UsagePage() {
  const months = lastMonths(6)
  const [month, setMonth] = useState(months[0].value)
  const summary = useQuery({ queryKey: ['usage', 'summary', month], queryFn: () => usageApi.summary(month) })
  const daily = useQuery({ queryKey: ['usage', 'daily', month], queryFn: () => usageApi.daily(month) })
  const byModel = useQuery({ queryKey: ['usage', 'by-model', month], queryFn: () => usageApi.byModel(month) })
  const byFlow = useQuery({ queryKey: ['usage', 'by-flow', month], queryFn: () => usageApi.byFlow(month) })
  const s = summary.data

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:px-8 md:py-7">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Kullanım ve maliyet</h1>
          <div className="flex-1" />
          <label className="flex items-center gap-2 text-[13px] text-muted">
            Dönem
            <select value={month} onChange={(e) => setMonth(e.target.value)} className="h-[38px] rounded-xl border border-line-strong bg-surface px-2.5 text-sm text-ink">
              {months.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          <a href={usageApi.exportUrl(month)} download className="flex h-[38px] items-center rounded-xl border border-line bg-surface px-3.5 text-sm text-ink no-underline">
            CSV indir
          </a>
        </div>

        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
          <Kpi label="Toplam maliyet" value={s ? money(s.cost_usd) : '—'} sub={s ? changeText(s) : ''} />
          <Kpi label="Token" value={s ? tokens(s.input_tokens + s.output_tokens) : '—'} sub={s ? `${tokens(s.input_tokens)} girdi · ${tokens(s.output_tokens)} çıktı` : ''} />
          <Kpi label="AI çağrısı" value={s ? s.calls.toLocaleString('tr-TR') : '—'} sub={s ? `Ortalama ${money(s.avg_cost_per_call)} / çağrı` : ''} />
          <Kpi
            label="Akış çalışması"
            value={s ? (s.flow_runs === null ? '—' : s.flow_runs.toLocaleString('tr-TR')) : '—'}
            sub={s ? (s.flow_runs === null ? "Kestra'ya ulaşılamadı" : `${s.flow_step_calls} AI adımı çağrısı`) : ''}
          />
        </div>

        {s?.budget && <BudgetCard budget={s.budget} />}

        <div className="flex flex-wrap gap-5">
          <section className="flex min-w-0 flex-[2_1_520px] flex-col gap-3 rounded-[18px] border border-line bg-surface p-4">
            <div className="flex flex-wrap items-center gap-3.5">
              <h2 className="m-0 text-[15px] font-bold">Günlük maliyet</h2>
              <div className="flex-1" />
              <Legend color={CHAT} label="Sohbet (akış oluşturma ve düzenleme)" />
              <Legend color={FLOW_STEP} label="Akış içi AI adımları" />
            </div>
            <div className="h-[220px]" role="img" aria-label="Günlük maliyet grafiği; ayrıntılar aşağıdaki tabloda">
              {daily.data && (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={daily.data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap={2}>
                    <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
                    <XAxis
                      dataKey="date"
                      tickFormatter={(d: string) => `${Number(d.slice(8))}`}
                      ticks={[daily.data[0]?.date, daily.data[14]?.date, daily.data.at(-1)?.date].filter(Boolean) as string[]}
                      tick={{ fontSize: 12, fill: 'var(--text-muted)' }}
                      axisLine={{ stroke: 'var(--border)' }}
                      tickLine={false}
                    />
                    <YAxis
                      tickCount={5}
                      allowDecimals
                      tickFormatter={(v: number) => (v === 0 ? '$0' : v < 1 ? `$${v.toFixed(2)}` : `$${Math.round(v)}`)}
                      tick={{ fontSize: 12, fill: 'var(--text-muted)' }}
                      axisLine={false}
                      tickLine={false}
                      width={44}
                    />
                    <Tooltip
                      cursor={{ fill: 'var(--bg-subtle)' }}
                      formatter={(value, name) => [money(Number(value)), name === 'chat_cost' ? 'Sohbet' : 'Akış içi AI']}
                      labelFormatter={(d) => new Date(String(d)).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' })}
                      contentStyle={{ borderRadius: 12, border: '1px solid var(--border)', background: 'var(--surface)', color: 'var(--text)', fontSize: 13 }}
                    />
                    <Bar dataKey="chat_cost" stackId="cost" fill={CHAT} stroke="var(--surface)" strokeWidth={1} />
                    <Bar dataKey="flow_step_cost" stackId="cost" fill={FLOW_STEP} stroke="var(--surface)" strokeWidth={1} radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
            {daily.data && (
              <details className="text-xs text-muted">
                <summary className="cursor-pointer">Tablo olarak göster</summary>
                <table className="mt-2 w-full border-collapse text-xs">
                  <thead>
                    <tr className="text-left">
                      <th className="py-1">Gün</th>
                      <th className="py-1 text-right">Sohbet</th>
                      <th className="py-1 text-right">Akış içi AI</th>
                    </tr>
                  </thead>
                  <tbody>
                    {daily.data
                      .filter((d) => d.chat_cost + d.flow_step_cost > 0)
                      .map((d) => (
                        <tr key={d.date} className="border-t border-line">
                          <td className="py-1">{d.date}</td>
                          <td className="py-1 text-right font-mono">{money(d.chat_cost)}</td>
                          <td className="py-1 text-right font-mono">{money(d.flow_step_cost)}</td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </details>
            )}
          </section>

          <section className="flex min-w-0 flex-[1_1_280px] flex-col gap-3.5 rounded-[18px] border border-line bg-surface p-4">
            <h2 className="m-0 text-[15px] font-bold">Modele göre</h2>
            {byModel.data?.length === 0 && <p className="m-0 text-sm text-muted">Bu dönemde AI çağrısı yok.</p>}
            {byModel.data?.map((m) => {
              const total = byModel.data.reduce((sum, x) => sum + x.cost_usd, 0)
              const pct = total > 0 ? (m.cost_usd / total) * 100 : 0
              return (
                <div key={m.model} className="flex flex-col gap-1.5">
                  <div className="flex justify-between text-sm">
                    <span>{m.label}</span>
                    <span className="font-mono">{money(m.cost_usd)}</span>
                  </div>
                  <div className="h-2 rounded-full bg-raised" role="meter" aria-label={`${m.label} payı`} aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full rounded-full" style={{ width: `${pct}%`, background: CHAT }} />
                  </div>
                  <span className="text-xs text-muted">
                    %{Math.round(pct)} · {tokens(m.input_tokens + m.output_tokens)} token · {m.calls.toLocaleString('tr-TR')} çağrı
                  </span>
                </div>
              )
            })}
            <p className="m-0 text-xs leading-relaxed text-muted">Kestra ile çalışan akışlar AI kullanmadığı sürece token harcamaz. Maliyetin çoğu akış oluşturma ve düzenleme sohbetlerinden gelir.</p>
          </section>
        </div>

        <div className="overflow-x-auto rounded-[18px] border border-line bg-surface">
          <table className="w-full min-w-[820px] border-collapse text-sm">
            <caption className="sr-only">Akış ve sohbet bazında AI kullanımı</caption>
            <thead>
              <tr className="text-left text-xs tracking-wider text-muted uppercase">
                <th scope="col" className="border-b border-line px-4 py-3 font-bold">
                  Akış / sohbet
                </th>
                <th scope="col" className="border-b border-line px-4 py-3 font-bold">
                  Model
                </th>
                {['Çağrı', 'Girdi token', 'Çıktı token', 'Maliyet'].map((h) => (
                  <th key={h} scope="col" className="border-b border-line px-4 py-3 text-right font-bold">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {byFlow.data?.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-4 text-muted">
                    Bu dönemde kayıt yok.
                  </td>
                </tr>
              )}
              {byFlow.data?.map((row) => (
                <tr key={`${row.type}:${row.flow_id ?? row.conversation_id ?? 'other'}`}>
                  <td className="border-b border-line px-4 py-3 font-bold">
                    {row.type === 'flow' && row.flow_id && row.flow_status !== 'deleted' ? (
                      <a href={href({ name: 'editor', flowId: row.flow_id })} className="text-ink">
                        {row.name}
                      </a>
                    ) : row.type === 'conversation' && row.conversation_id ? (
                      <a href={href({ name: 'chat', conversationId: row.conversation_id })} className="text-ink">
                        {row.name}
                      </a>
                    ) : (
                      row.name
                    )}
                    {row.flow_step_calls > 0 && <span className="ml-2 text-xs font-semibold text-muted">({row.flow_step_calls} AI adımı)</span>}
                  </td>
                  <td className="border-b border-line px-4 py-3 text-ink-2">{row.models.map((m) => m.replace('Claude ', '')).join(', ') || '—'}</td>
                  <td className="border-b border-line px-4 py-3 text-right font-mono">{row.calls.toLocaleString('tr-TR')}</td>
                  <td className="border-b border-line px-4 py-3 text-right font-mono">{tokens(row.input_tokens)}</td>
                  <td className="border-b border-line px-4 py-3 text-right font-mono">{tokens(row.output_tokens)}</td>
                  <td className="border-b border-line px-4 py-3 text-right font-mono">{money(row.cost_usd)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="m-0 text-[13px] text-muted">
          Tutarlar her AI çağrısı anında, o anki fiyatla hesaplanıp kaydedilir (milyon token başına Sonnet 5.5 $2 girdi / $10 çıktı, Opus 5.5 $4 / $20; önbellek okuma $0.20). Bu, Anthropic faturası değil Fortis'in kendi
          hesabıdır.
        </p>
      </div>
    </main>
  )
}

function changeText(s: UsageSummary): string {
  if (s.change_pct === null) return s.previous_cost_usd === 0 ? 'Geçen ay kullanım yok' : ''
  return `Geçen aya göre ${s.change_pct >= 0 ? '+' : '−'}%${Math.abs(s.change_pct).toLocaleString('tr-TR')}`
}

function Kpi({ label, value, sub }: { label: string; value: string; sub: string }) {
  return (
    <div className="flex flex-col gap-1 rounded-[18px] border border-line bg-surface p-4">
      <span className="text-[13px] text-muted">{label}</span>
      <span className="font-mono text-[28px] font-bold">{value}</span>
      <span className="text-xs text-muted">{sub}</span>
    </div>
  )
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <span className="flex items-center gap-1.5 text-xs text-ink-2">
      <span className="size-2.5 rounded-sm" style={{ background: color }} aria-hidden />
      {label}
    </span>
  )
}

function BudgetCard({ budget }: { budget: NonNullable<UsageSummary['budget']> }) {
  const pct = budget.ratio === null ? 0 : Math.round(budget.ratio * 100)

  return (
    <div className="flex flex-col gap-2.5 rounded-[18px] border border-line bg-surface p-4">
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="text-[15px] font-bold">Şirket aylık bütçesi</span>
        <span className="text-[13px] text-muted">{budget.limit === null ? `${money(budget.spent)} · limit yok` : `${money(budget.spent)} / ${money(budget.limit, 0)} · %${pct}`}</span>
      </div>
      <div role="meter" aria-label="Bütçe kullanımı" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} className="h-2.5 overflow-hidden rounded-full bg-raised">
        <div className={`h-full rounded-full ${pct >= 100 ? 'bg-bad' : pct >= 80 ? 'bg-warn' : 'bg-brand'}`} style={{ width: `${Math.min(100, pct)}%` }} />
      </div>
      <span className="text-xs text-muted">Bütçeleri ve AI politikalarını yöneticiler Yönetişim ekranından belirler.</span>
    </div>
  )
}
