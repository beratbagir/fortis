import { useInfiniteQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { auditApi, type AuditActor, type AuditFilters, type AuditLogRow, type AuditResult } from '../api'
import { usd } from './money'

const RESULTS: Record<AuditResult, { label: string; tone: string }> = {
  allowed: { label: 'Başarılı', tone: 'text-ok' },
  blocked: { label: 'Reddedildi', tone: 'text-warn' },
  awaiting_approval: { label: 'Onay bekliyor', tone: 'text-info' },
  failed: { label: 'Hata', tone: 'text-bad' },
}

const ACTORS: Record<AuditActor, string> = { user: 'Kullanıcı', agent: 'Agent', system: 'Sistem' }

const stamp = (iso: string) => new Date(iso).toLocaleString('tr-TR', { timeZone: 'Europe/Istanbul', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })

/** Olay türü seçenekleri: tek tek olaylar ve ön ekleri (agent.*, inbox.* …). */
const actionOptions = (actions: string[]) => {
  const prefixes = [...new Set(actions.filter((a) => a.includes('.')).map((a) => `${a.split('.')[0]}.*`))]
  return [...prefixes, ...actions].sort()
}

/**
 * Yönetişim > Denetim kaydı (admin): filtre, imleçle sayfalama, ayrıntı (gizli değerler temizlenmiş), CSV (en çok
 * 50.000 satır; dışa aktarma da kayda geçer). Kayıt değiştirilemez; bu ekranda düzenleme veya silme yok.
 */
export function GovernanceAuditView() {
  const [filters, setFilters] = useState<AuditFilters>({})
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const list = useInfiniteQuery({
    queryKey: ['governance', 'audit', filters],
    queryFn: ({ pageParam }) => auditApi.list(filters, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.next_cursor,
    placeholderData: (prev) => prev,
  })
  const rows = list.data?.pages.flatMap((p) => p.data) ?? []
  const options = list.data?.pages[0]?.options
  const set = (patch: AuditFilters) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-4 px-4 py-6 md:px-8">
        <header className="flex flex-wrap items-end gap-3">
          <div className="mr-auto flex flex-col gap-1">
            <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Denetim kaydı</h1>
            <p className="m-0 text-[13px] text-muted">Kim, ne zaman, ne yaptı. Kayıtlar değiştirilemez ve silinemez.</p>
          </div>
          <a href={auditApi.csvUrl(filters)} download className="rounded-lg border border-line px-3 py-1.5 text-[13px] text-ink no-underline hover:bg-raised">
            CSV indir
          </a>
        </header>

        <form
          aria-label="Denetim filtreleri"
          className="flex flex-wrap items-end gap-2 text-xs"
          onSubmit={(e) => {
            e.preventDefault()
            set({ q: search.trim() })
          }}
        >
          <Field label="Başlangıç">
            <input aria-label="Başlangıç" type="date" value={filters.from ?? ''} max={filters.to} onChange={(e) => set({ from: e.target.value })} className={INPUT} />
          </Field>
          <Field label="Bitiş">
            <input aria-label="Bitiş" type="date" value={filters.to ?? ''} min={filters.from} onChange={(e) => set({ to: e.target.value })} className={INPUT} />
          </Field>
          <Select label="Aktör" value={filters.actor} onChange={(v) => set({ actor: v as AuditActor | '' })} options={Object.entries(ACTORS)} />
          <Select label="Kullanıcı" value={filters.user_id} onChange={(v) => set({ user_id: v })} options={(options?.users ?? []).map((u) => [String(u.id), u.name])} />
          <Select label="Olay" value={filters.action} onChange={(v) => set({ action: v })} options={actionOptions(options?.actions ?? []).map((a) => [a, a])} />
          <Select label="Sonuç" value={filters.result} onChange={(v) => set({ result: v as AuditResult | '' })} options={Object.entries(RESULTS).map(([k, r]) => [k, r.label])} />
          <Field label="Ara">
            <span className="flex gap-1">
              <input aria-label="Ara" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="olay, aktör, ayrıntı" className={`${INPUT} w-48`} />
              <button type="submit" className="rounded-md border border-line px-2.5 text-[13px] hover:bg-raised">
                Ara
              </button>
            </span>
          </Field>
        </form>

        {list.isError && (
          <p role="alert" className="m-0 text-sm text-bad">
            Denetim kaydı yüklenemedi.
          </p>
        )}

        <section aria-label="Kayıtlar" className="overflow-hidden rounded-xl border border-line bg-surface">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[12px]">
              <thead className="bg-canvas">
                <tr className="text-left text-[11px] text-muted">
                  {['Zaman', 'Aktör', 'Olay', 'Hedef', 'Sonuç', ''].map((h) => (
                    <th key={h} className="px-3 py-2 font-medium">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <Row key={r.id} row={r} open={open === r.id} onToggle={() => setOpen(open === r.id ? null : r.id)} />
                ))}
                {list.isSuccess && rows.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-3 py-6 text-center text-muted">
                      Bu filtreyle kayıt yok.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
        {list.hasNextPage && (
          <button type="button" disabled={list.isFetchingNextPage} onClick={() => list.fetchNextPage()} className="self-center rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised disabled:opacity-50">
            {list.isFetchingNextPage ? 'Yükleniyor…' : 'Daha eski kayıtlar'}
          </button>
        )}
      </div>
    </main>
  )
}

function Row({ row, open, onToggle }: { row: AuditLogRow; open: boolean; onToggle: () => void }) {
  const result = RESULTS[row.result] ?? { label: row.result, tone: 'text-muted' }

  return (
    <>
      <tr className="border-t border-line align-top">
        <td className="px-3 py-1.5 font-mono whitespace-nowrap">{stamp(row.at)}</td>
        <td className="px-3 py-1.5">
          {row.actor ?? '—'}
          {row.actor_type !== 'system' && <span className="text-muted"> · {ACTORS[row.actor_type]}</span>}
        </td>
        <td className="px-3 py-1.5 font-mono">{row.action}</td>
        <td className="px-3 py-1.5 text-muted">{[row.subject, row.system].filter(Boolean).join(' · ') || '—'}</td>
        <td className={`px-3 py-1.5 ${result.tone}`}>
          {result.label}
          {row.cost_usd ? <span className="text-muted"> · {usd(row.cost_usd, 4)}</span> : null}
        </td>
        <td className="px-3 py-1.5 text-right">
          {row.meta && (
            <button type="button" aria-expanded={open} aria-label={`Kayıt ${row.id} ayrıntısı`} onClick={onToggle} className="rounded-md border border-line px-2 py-0.5 text-[11px] hover:bg-raised">
              {open ? 'Gizle' : 'Ayrıntı'}
            </button>
          )}
        </td>
      </tr>
      {open && row.meta && (
        <tr className="bg-canvas">
          <td colSpan={6} className="px-3 py-2">
            <pre className="m-0 max-h-72 overflow-auto font-mono text-[11px] whitespace-pre-wrap text-ink-2">{JSON.stringify(row.meta, null, 2)}</pre>
          </td>
        </tr>
      )}
    </>
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
