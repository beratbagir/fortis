import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { connectorsApi, type ConnectorCard, type ConnectorDetail } from '../api'
import { STATUS_BADGE } from '../agentpage/format'
import { useAuth } from '../auth/useAuth'
import { CATEGORY_TITLE, CONNECTOR_STATUS, SCOPE_LABEL, setupText } from '../connectors/format'
import { href } from '../router'
import { Dialog } from '../ui/Dialog'
import { ago } from '../ui/format'
import { SearchIcon } from '../ui/icons'

type Tab = 'all' | 'data' | 'channel' | 'mcp'
const TABS: { key: Tab; label: string }[] = [
  { key: 'all', label: 'Tümü' },
  { key: 'data', label: 'Veri kaynakları' },
  { key: 'channel', label: 'Kanallar' },
  { key: 'mcp', label: 'MCP' },
]

/** Bağlantılar (#/connectors, #/connectors/:key): Fortis hangi sistemlere bağlı, sağlıklı mı, ne yapabiliyor, kim kullanıyor. */
export function ConnectorsPage({ connectorKey }: { connectorKey: string | null }) {
  return connectorKey ? <ConnectorDetailPage connectorKey={connectorKey} /> : <ConnectorList />
}

function ConnectorList() {
  const list = useQuery({ queryKey: ['connectors', 'list'], queryFn: connectorsApi.summary, refetchInterval: 60_000 })
  const [tab, setTab] = useState<Tab>('all')
  const [search, setSearch] = useState('')
  const term = search.trim().toLocaleLowerCase('tr')
  const items = (list.data?.data ?? []).filter((c) => term === '' || `${c.label} ${c.description ?? ''}`.toLocaleLowerCase('tr').includes(term))
  const summary = list.data?.summary

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-5xl flex-col gap-5 px-4 py-8 md:px-8">
        <header className="flex flex-col gap-1.5">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Bağlantılar</h1>
          <p className="m-0 max-w-[620px] text-[13px] text-muted">Fortis, Freebird'ün mevcut sistemlerinin üstünde bir katmandır: her bağlantı izinle sınırlı, varsayılan olarak salt okunur ve denetim kaydına yazılır.</p>
          {summary && (
            <p className="m-0 text-xs text-muted">
              <b className="font-mono text-ink">{summary.connected}</b> bağlı · <b className="font-mono text-ink">{summary.attention}</b> dikkat · <b className="font-mono text-ink">{summary.needs_setup}</b> kurulum gerekli
              {summary.disabled > 0 && (
                <>
                  {' '}
                  · <b className="font-mono text-bad">{summary.disabled}</b> kapalı
                </>
              )}
            </p>
          )}
        </header>

        <div className="flex flex-wrap items-center gap-3">
          <div role="tablist" aria-label="Bağlantı türü" className="flex gap-1 rounded-lg bg-raised p-0.5">
            {TABS.map((t) => (
              <button key={t.key} role="tab" type="button" aria-selected={tab === t.key} onClick={() => setTab(t.key)} className={`rounded-md px-2.5 py-1 text-[13px] ${tab === t.key ? 'bg-surface font-medium text-ink shadow-[0_1px_2px_var(--shadow)]' : 'text-muted hover:text-ink'}`}>
                {t.label}
              </button>
            ))}
          </div>
          {tab !== 'mcp' && (
            <label className="flex min-w-48 flex-1 items-center gap-2 rounded-lg border border-line bg-surface px-3 py-1.5 text-muted md:max-w-xs">
              <SearchIcon />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Bağlantı ara…" aria-label="Bağlantı ara" className="flex-1 bg-transparent text-sm text-ink outline-none" />
            </label>
          )}
        </div>

        {list.isError && <p role="alert" className="m-0 text-sm text-bad">Bağlantılar yüklenemedi.</p>}

        {tab === 'mcp' ? (
          <p className="m-0 rounded-xl border border-dashed border-line px-4 py-10 text-center text-[13px] text-muted">Fortis'i Claude Desktop, Teams gibi araçlara bağlama (MCP) A6'da gelecek.</p>
        ) : (
          (['data', 'channel', 'setup'] as const)
            .filter((category) => tab === 'all' || category === tab || (tab === 'data' && category === 'setup'))
            .map((category) => {
              const cards = items.filter((c) => c.category === category)
              if (cards.length === 0) return null
              const [title, note] = CATEGORY_TITLE[category]
              return (
                <section key={category} aria-label={title} className="flex flex-col gap-2">
                  <h2 className="m-0 text-[13px] font-medium">
                    {title} <span className="font-normal text-muted">· {category === 'setup' ? note : `${cards.filter((c) => c.status === 'connected').length} bağlı`}</span>
                  </h2>
                  <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                    {cards.map((c) => (
                      <ConnectorCardView key={c.key} connector={c} />
                    ))}
                  </div>
                </section>
              )
            })
        )}
      </div>
    </main>
  )
}

export function ConnectorCardView({ connector: c }: { connector: ConnectorCard }) {
  const [label, tone] = CONNECTOR_STATUS[c.status]

  return (
    <a href={href({ name: 'connectors', key: c.key })} aria-label={`${c.label}: ${label}`} className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-3 text-ink no-underline hover:border-line-strong">
      <span className="flex items-start gap-2.5">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-raised font-mono text-[11px] text-ink-2" aria-hidden>
          {c.abbr}
        </span>
        <span className="flex min-w-0 flex-1 flex-col">
          <span className="text-[14px] font-medium">{c.label}</span>
          <span className="line-clamp-2 text-[11px] text-muted">{c.description}</span>
        </span>
        <span className={`shrink-0 rounded px-1.5 py-px text-[10px] font-medium ${tone}`}>{label}</span>
      </span>
      <span className="flex flex-wrap items-center gap-1">
        {c.scopes.map((s) => (
          <span key={s} className="rounded border border-line px-1.5 font-mono text-[10px] text-ink-2">
            {SCOPE_LABEL[s]}
          </span>
        ))}
        <span className="flex-1" />
        {c.category !== 'setup' && (
          <span className="font-mono text-[10px] text-faint">
            {c.last_checked_at ? ago(c.last_checked_at) : c.status === 'not_configured' ? '' : 'kontrol yok'}
            {c.uses_this_week > 0 ? ` · ${c.uses_this_week} kullanım/hafta` : ''}
          </span>
        )}
      </span>
    </a>
  )
}

function ConnectorDetailPage({ connectorKey }: { connectorKey: string }) {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.abilities.admin ?? false
  const detail = useQuery({ queryKey: ['connectors', 'detail', connectorKey], queryFn: () => connectorsApi.get(connectorKey) })
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'ok' | 'bad'; text: string } | null>(null)
  const [disabling, setDisabling] = useState(false)
  const [reason, setReason] = useState('')

  if (detail.isError) return <p role="alert" className="m-8 text-sm text-muted">Bağlantı bulunamadı.</p>
  if (!detail.data) return null
  const c: ConnectorDetail = detail.data
  const [label, tone] = CONNECTOR_STATUS[c.status]

  const act = async (work: () => Promise<ConnectorDetail>, done: string) => {
    setBusy(true)
    setMessage(null)
    try {
      queryClient.setQueryData(['connectors', 'detail', connectorKey], await work())
      queryClient.invalidateQueries({ queryKey: ['connectors'] })
      setMessage({ tone: 'ok', text: done })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-6 md:px-8">
        <a href={href({ name: 'connectors', key: null })} className="text-xs text-muted no-underline hover:text-ink">
          ← Bağlantılar
        </a>
        <header className="flex flex-wrap items-start gap-3">
          <span className="flex size-10 items-center justify-center rounded-lg bg-raised font-mono text-[12px] text-ink-2" aria-hidden>
            {c.abbr}
          </span>
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            <h1 className="m-0 flex flex-wrap items-center gap-2 font-serif text-[26px] font-normal">
              {c.label}
              <span className={`rounded px-1.5 py-px font-sans text-[11px] font-medium ${tone}`}>{label}</span>
            </h1>
            <p className="m-0 text-[13px] text-ink-2">{c.description}</p>
            {c.detail && <p className="m-0 text-xs text-muted">{c.detail}</p>}
            {c.disabled && (
              <p className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-xs text-bad">
                Şirket genelinde kapalı: {c.disabled.reason} {c.disabled.by ? `· ${c.disabled.by}` : ''} {c.disabled.at ? `· ${ago(c.disabled.at)}` : ''}. Sohbetlerde ve agentlarda bu kaynağın araçları kullanılmıyor.
              </p>
            )}
          </div>
          {isAdmin && c.category !== 'setup' && (
            <div className="flex flex-wrap gap-2">
              {c.checkable && (
                <button type="button" disabled={busy} onClick={() => act(() => connectorsApi.check(c.key), 'Kontrol edildi.')} className={SECONDARY}>
                  Şimdi kontrol et
                </button>
              )}
              {c.enabled ? (
                <button type="button" disabled={busy} onClick={() => setDisabling(true)} className="rounded-lg border border-bad px-3 py-1.5 text-[13px] text-bad hover:bg-bad-soft disabled:opacity-60">
                  Kapat
                </button>
              ) : (
                <button type="button" disabled={busy} onClick={() => act(() => connectorsApi.enable(c.key), `${c.label} açıldı.`)} className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-60">
                  Aç
                </button>
              )}
            </div>
          )}
        </header>
        {message && (
          <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
            {message.text}
          </p>
        )}

        <div className="grid gap-4 lg:grid-cols-2">
          {c.category !== 'setup' && (
            <Section title="Durum">
              <div aria-label="Son 24 saat kontroller" className="flex h-6 items-end gap-px">
                {c.checks.length === 0 && <span className="text-xs text-muted">Son 24 saatte kontrol yok.</span>}
                {c.checks.map((check, i) => (
                  <span key={i} title={`${new Date(check.at).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })} · ${check.ok ? 'başarılı' : 'başarısız'}`} className={`h-full min-w-[3px] flex-1 rounded-sm ${check.ok ? 'bg-ok' : 'bg-bad'}`} />
                ))}
              </div>
              <dl className="m-0 grid grid-cols-[120px_minmax(0,1fr)] gap-y-1 text-xs">
                <dt className="text-muted">Son kontrol</dt>
                <dd className="m-0 font-mono">{c.last_checked_at ? ago(c.last_checked_at) : '—'}</dd>
                <dt className="text-muted">Ortalama yanıt</dt>
                <dd className="m-0 font-mono">{c.avg_duration_ms === null ? '—' : `${c.avg_duration_ms} ms`}</dd>
                <dt className="text-muted">Son hata</dt>
                <dd className="m-0 break-words">{c.last_error ? `${c.last_error.message} (${ago(c.last_error.at)})` : 'Yok'}</dd>
              </dl>
            </Section>
          )}

          <Section title="Ne yapabilir">
            {c.scopes.length > 0 && (
              <span className="flex gap-1">
                {c.scopes.map((s) => (
                  <span key={s} className="rounded border border-line px-1.5 font-mono text-[10px] text-ink-2">
                    {SCOPE_LABEL[s]}
                  </span>
                ))}
              </span>
            )}
            <ul className="m-0 flex list-none flex-col gap-1 p-0 text-xs">
              {c.tools.length === 0 && <li className="text-muted">Henüz araç yok.</li>}
              {c.tools.map((t) => (
                <li key={t.name}>
                  <span className="font-mono text-ink">{t.name}</span> <span className="text-muted">— {t.description || t.label}</span>
                </li>
              ))}
            </ul>
          </Section>

          {c.category !== 'setup' && (
            <Section title="Kim kullanıyor">
              <p className="m-0 text-xs text-muted">Son 7 günde sohbetlerde {c.chat_queries_7d} sorgu.</p>
              <ul className="m-0 flex list-none flex-col gap-1 p-0 text-xs">
                {c.used_by.length === 0 && <li className="text-muted">Bu kaynağı kullanan agent yok.</li>}
                {c.used_by.map((a) => (
                  <li key={a.id}>
                    <a href={href({ name: 'agent', agentId: a.id, tab: 'overview', runId: null })} className="text-ink">
                      {a.name}
                    </a>{' '}
                    <span className="text-muted">
                      · {a.owner ?? '—'} · {STATUS_BADGE[a.status][0]}
                    </span>
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {(c.protection.masked_groups.length > 0 || c.protection.rules.length > 0) && (
            <Section title="Veri koruması">
              {c.protection.masked_groups.length > 0 && (
                <>
                  <span className="text-xs text-muted">Claude'a giderken maskelenen alanlar</span>
                  <ul className="m-0 flex list-none flex-wrap gap-1 p-0">
                    {c.protection.masked_groups.map((g) => (
                      <li key={g} className="rounded border border-line px-1.5 py-px text-[11px] text-ink-2">
                        {g}
                      </li>
                    ))}
                  </ul>
                </>
              )}
              <ul className="m-0 list-disc pl-4 text-xs text-ink-2">
                {c.protection.rules.map((r) => (
                  <li key={r}>{r}</li>
                ))}
              </ul>
            </Section>
          )}

          {c.env && c.env.length > 0 && (
            <Section title="Yapılandırma (.env)">
              <p className="m-0 text-xs text-muted">Sadece değişken adları; değerler hiçbir zaman gösterilmez. Kimlik bilgileri arayüzden girilmez.</p>
              <ul className="m-0 grid list-none gap-1 p-0 font-mono text-[11px] sm:grid-cols-2">
                {c.env.map((e) => (
                  <li key={e.name} className={e.defined ? 'text-ink-2' : 'text-faint'}>
                    {e.defined ? '✓' : '✗'} {e.name}
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {c.needs.length > 0 && (
            <Section title="Ne gerekiyor?">
              <ul className="m-0 list-disc pl-4 text-[13px] text-ink-2">
                {c.needs.map((n) => (
                  <li key={n}>{n}</li>
                ))}
              </ul>
              <CopyButton text={setupText(c)} />
            </Section>
          )}
        </div>
      </div>

      {disabling && (
        <Dialog title={`${c.label} kapatılsın mı?`} onClose={() => setDisabling(false)}>
          <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              setDisabling(false)
              act(() => connectorsApi.disable(c.key, reason.trim()), `${c.label} şirket genelinde kapatıldı.`)
            }}
          >
            <p className="m-0 text-sm text-ink-2">
              Kapatınca sohbetlerde ve agentlarda {c.label} araçları kullanılamaz. <b>{c.affected_agents} agent</b> etkilenecek; sahiplerine Inbox uyarısı gider.
            </p>
            <label className="flex flex-col gap-1 text-xs text-muted">
              Gerekçe (zorunlu, denetim kaydına yazılır)
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} minLength={3} maxLength={500} required className="rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink" />
            </label>
            <span className="flex justify-end gap-2">
              <button type="button" onClick={() => setDisabling(false)} className={SECONDARY}>
                Vazgeç
              </button>
              <button type="submit" disabled={reason.trim().length < 3} className="rounded-lg bg-bad px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
                Bağlantıyı kapat
              </button>
            </span>
          </form>
        </Dialog>
      )}
    </main>
  )
}

const SECONDARY = 'rounded-lg border border-line px-3 py-1.5 text-[13px] text-ink hover:bg-raised disabled:opacity-60'

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section aria-label={title} className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4">
      <h2 className="m-0 text-[13px] font-medium">{title}</h2>
      {children}
    </section>
  )
}

function CopyButton({ text }: { text: string }) {
  const [copied, setCopied] = useState(false)

  return (
    <button
      type="button"
      onClick={async () => {
        await navigator.clipboard?.writeText(text)
        setCopied(true)
        setTimeout(() => setCopied(false), 1500)
      }}
      className="self-start rounded-lg border border-line px-2.5 py-1 text-xs text-ink-2 hover:bg-raised"
    >
      {copied ? 'Kopyalandı' : "IT'ye iletmek için kopyala"}
    </button>
  )
}
