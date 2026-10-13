import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts'
import { governanceUsageApi, type UsageFilters, type UsageType } from '../api'
import { Budgets } from './Budgets'
import { credits, usd } from './money'

const TYPE_COLOR: Record<UsageType, string> = {
  chat: 'var(--color-ink-2)',
  agent: 'var(--color-brand)',
  eval: 'var(--color-info)',
  evaluation: 'var(--color-ok)',
  title: 'var(--color-faint)',
  flow: 'var(--color-warn)',
}

const today = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Istanbul' })
const monthStart = () => `${today().slice(0, 8)}01`
const stamp = (iso: string) =>
  new Date(iso).toLocaleString('tr-TR', {
    timeZone: 'Europe/Istanbul',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })

/** Yönetişim > Kullanım (admin): filtreler, günlük harcama (tür), departman/model kırılımı, en çok harcayanlar, satırlar, CSV, bütçeler. */
export function GovernanceUsageView() {
  const [filters, setFilters] = useState<UsageFilters>({
    from: monthStart(),
    to: today(),
  })
  const report = useQuery({
    queryKey: ['governance', 'usage', filters],
    queryFn: () => governanceUsageApi.report(filters),
    placeholderData: (prev) => prev,
  })
  const data = report.data
  const credit = data?.credit_usd_value ?? null
  const set = (patch: UsageFilters) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:px-8">
        <header className="flex flex-wrap items-end gap-3">
          <h1 className="m-0 mr-auto font-serif text-[30px] font-normal tracking-tight">Kullanım ve maliyet</h1>
          <a href={governanceUsageApi.csvUrl(filters)} className="rounded-lg border border-line px-3 py-1.5 text-[13px] text-ink no-underline hover:bg-raised" download>
            CSV indir
          </a>
        </header>

        <form aria-label="Filtreler" className="flex flex-wrap items-end gap-2 text-xs" onSubmit={(e) => e.preventDefault()}>
          <Field label="Başlangıç">
            <input aria-label="Başlangıç" type="date" value={filters.from} max={filters.to} onChange={(e) => set({ from: e.target.value })} className={INPUT} />
          </Field>
          <Field label="Bitiş">
            <input aria-label="Bitiş" type="date" value={filters.to} min={filters.from} onChange={(e) => set({ to: e.target.value })} className={INPUT} />
          </Field>
          <Select label="Departman" value={filters.department_id} onChange={(v) => set({ department_id: v })} options={(data?.options.departments ?? []).map((d) => [String(d.id), d.name])} />
          <Select label="Kullanıcı" value={filters.user_id} onChange={(v) => set({ user_id: v })} options={(data?.options.users ?? []).map((u) => [String(u.id), u.name])} />
          <Select label="Model" value={filters.model} onChange={(v) => set({ model: v })} options={(data?.options.models ?? []).map((m) => [m.id, m.label])} />
          <Select label="Tür" value={filters.type} onChange={(v) => set({ type: v })} options={(data?.options.types ?? []).map((t) => [t.id, t.label])} />
        </form>

        {report.isError && (
          <p role="alert" className="m-0 text-sm text-bad">
            Kullanım yüklenemedi.
          </p>
        )}
        {data && (
          <>
            <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
              <Stat title="Toplam maliyet" value={usd(data.totals.cost_usd)} note={credit ? `≈ ${credits(data.totals.cost_usd, credit)} kredi` : `${data.range.from} – ${data.range.to}`} />
              <Stat title="AI çağrısı" value={data.totals.calls.toLocaleString('tr-TR')} note={data.totals.calls ? `çağrı başına ${usd(data.totals.cost_usd / data.totals.calls)}` : undefined} />
              <Stat title="Giriş token" value={data.totals.input_tokens.toLocaleString('tr-TR')} />
              <Stat title="Çıkış token" value={data.totals.output_tokens.toLocaleString('tr-TR')} />
            </div>

            <section aria-labelledby="daily-usage" className="rounded-xl border border-line bg-surface p-4">
              <div className="mb-2 flex flex-wrap items-center gap-3 text-[11px] text-muted">
                <h2 id="daily-usage" className="m-0 mr-auto text-[13px] font-medium text-ink">
                  Günlük harcama (türe göre)
                </h2>
                {data.by_type.map((t) => (
                  <span key={t.type} className="flex items-center gap-1">
                    <span className="size-2 rounded-sm" style={{ background: TYPE_COLOR[t.type] }} aria-hidden />
                    {t.label} {usd(t.cost_usd)}
                  </span>
                ))}
              </div>
              <div className="h-44">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.daily}>
                    <XAxis dataKey="date" tickFormatter={(d: string) => d.slice(8)} tick={{ fontSize: 10, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
                    <Tooltip
                      cursor={{ fill: 'var(--color-raised)' }}
                      contentStyle={{
                        background: 'var(--color-surface)',
                        border: '1px solid var(--color-line)',
                        fontSize: 12,
                      }}
                      formatter={(v) => usd(Number(v))}
                    />
                    {(Object.keys(TYPE_COLOR) as UsageType[]).map((type) => (
                      <Bar key={type} dataKey={type} name={data.options.types.find((t) => t.id === type)?.label ?? type} stackId="t" fill={TYPE_COLOR[type]} />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
              <Breakdown
                title="Departmana göre"
                rows={data.by_department.map((d) => ({
                  key: String(d.id),
                  label: d.name,
                  cost: d.cost_usd,
                  note: `${d.calls} çağrı`,
                }))}
                total={data.totals.cost_usd}
                credit={credit}
              />
              <Breakdown
                title="Modele göre"
                rows={data.by_model.map((m) => ({
                  key: m.model,
                  label: m.label,
                  cost: m.cost_usd,
                  note: `${m.calls} çağrı`,
                }))}
                total={data.totals.cost_usd}
                credit={credit}
              />
              <Breakdown
                title="En çok harcayan 10 agent"
                rows={data.top_agents.map((a) => ({
                  key: String(a.id),
                  label: a.name,
                  cost: a.cost_usd,
                  note: `${a.runs} çalışma`,
                  href: `#/agents/${a.id}`,
                }))}
                total={data.totals.cost_usd}
                credit={credit}
              />
              <Breakdown
                title="En çok harcayan 10 kullanıcı"
                rows={data.top_users.map((u) => ({
                  key: String(u.id),
                  label: u.name,
                  cost: u.cost_usd,
                  note: [u.department, `${u.calls} çağrı`].filter(Boolean).join(' · '),
                }))}
                total={data.totals.cost_usd}
                credit={credit}
              />
            </div>

            <section aria-labelledby="usage-rows" className="overflow-hidden rounded-xl border border-line bg-surface">
              <h2 id="usage-rows" className="m-0 border-b border-line px-4 py-2.5 text-[13px] font-medium">
                AI çağrıları{' '}
                <span className="font-normal text-muted">
                  · son {data.rows.length} / {data.rows_total.toLocaleString('tr-TR')} (tamamı CSV'de)
                </span>
              </h2>
              <div className="max-h-[420px] overflow-auto">
                <table className="w-full border-collapse text-[12px]">
                  <thead className="sticky top-0 bg-canvas">
                    <tr className="text-left text-[11px] text-muted">
                      {['Zaman', 'Kullanıcı', 'Departman', 'Tür', 'Model', 'Giriş', 'Çıkış', 'Maliyet'].map((h, i) => (
                        <th key={h} className={`px-3 py-2 font-medium ${i >= 5 ? 'text-right' : ''}`}>
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {data.rows.map((r) => (
                      <tr key={r.id} className="border-t border-line">
                        <td className="px-3 py-1.5 font-mono whitespace-nowrap">{stamp(r.at)}</td>
                        <td className="px-3 py-1.5">{r.user ?? '—'}</td>
                        <td className="px-3 py-1.5 text-muted">{r.department ?? '—'}</td>
                        <td className="px-3 py-1.5">{data.options.types.find((t) => t.id === r.type)?.label ?? r.type}</td>
                        <td className="px-3 py-1.5 font-mono text-[11px] text-muted">{r.model}</td>
                        <td className="px-3 py-1.5 text-right font-mono">{r.input_tokens.toLocaleString('tr-TR')}</td>
                        <td className="px-3 py-1.5 text-right font-mono">{r.output_tokens.toLocaleString('tr-TR')}</td>
                        <td className="px-3 py-1.5 text-right font-mono">
                          {usd(r.cost_usd, 4)}
                          {credit ? <span className="text-muted"> · {credits(r.cost_usd, credit)}</span> : null}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        )}

        <Budgets />
      </div>
    </main>
  )
}

const INPUT = 'rounded-md border border-line bg-surface px-2 py-1 text-[13px] text-ink'

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-muted">
      {label}
      {children}
    </label>
  )
}

function Select({ label, value, onChange, options }: { label: string; value: string | undefined; onChange: (v: string) => void; options: [string, string][] }) {
  return (
    <Field label={label}>
      <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={INPUT}>
        <option value="">Hepsi</option>
        {options.map(([v, l]) => (
          <option key={v} value={v}>
            {l}
          </option>
        ))}
      </select>
    </Field>
  )
}

function Stat({ title, value, note }: { title: string; value: string; note?: string }) {
  return (
    <section aria-label={title} className="flex flex-col gap-1 rounded-xl border border-line bg-surface px-3.5 py-3">
      <span className="text-[11px] text-muted">{title}</span>
      <span className="font-serif text-[24px] leading-none text-ink">{value}</span>
      {note && <span className="text-[11px] text-muted">{note}</span>}
    </section>
  )
}

function Breakdown({
  title,
  rows,
  total,
  credit,
}: {
  title: string
  rows: {
    key: string
    label: string
    cost: number
    note?: string
    href?: string
  }[]
  total: number
  credit: number | null
}) {
  return (
    <section aria-label={title} className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4 text-xs">
      <h2 className="m-0 text-[13px] font-medium">{title}</h2>
      {rows.length === 0 && <p className="m-0 text-muted">Bu filtrede veri yok.</p>}
      {rows.map((r) => (
        <div key={r.key} className="flex flex-col gap-1">
          <span className="flex items-baseline gap-2">
            {r.href ? (
              <a href={r.href} className="min-w-0 flex-1 truncate text-ink">
                {r.label}
              </a>
            ) : (
              <span className="min-w-0 flex-1 truncate text-ink">{r.label}</span>
            )}
            <span className="text-[11px] text-muted">{r.note}</span>
            <span className="font-mono">
              {usd(r.cost)}
              {credit ? <span className="text-muted"> · {credits(r.cost, credit)} kr</span> : null}
            </span>
          </span>
          <span className="h-1 overflow-hidden rounded-full bg-raised" aria-hidden>
            <span
              className="block h-full bg-ink-2"
              style={{
                width: `${total > 0 ? Math.round((100 * r.cost) / total) : 0}%`,
              }}
            />
          </span>
        </div>
      ))}
    </section>
  )
}
