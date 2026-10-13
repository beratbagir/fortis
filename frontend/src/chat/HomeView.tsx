import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { api, type HomeData } from '../api'
import { href } from '../router'
import { ChartIcon, CheckSquareIcon, DocumentIcon, ScreenIcon, WrenchIcon } from '../ui/icons'
import { Logo } from '../ui/Logo'
import { greeting } from './chatUtils'

const SUGGESTION_ICON = { question: ChartIcon, agent: WrenchIcon, report: DocumentIcon, presentation: ScreenIcon }

const minutesAgo = (iso: string | null) => {
  if (!iso) return ''
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000))
  return minutes < 60 ? `${minutes} dk` : `${Math.round(minutes / 60)} sa`
}

const TONE: Record<HomeData['activity'][number]['tone'], string> = { ok: 'bg-ok', bad: 'bg-bad', warn: 'bg-warn', running: 'bg-info' }

/** Home: karşılama, soru kutusu, bağlantı çipleri, örnek sorular, bekleyenler ve bugünkü etkinlik. Sadece gerçek veri. */
export function HomeView({ composer, onSend, busy, error }: { composer: ReactNode; onSend: (text: string) => void; busy: boolean; error?: string | null }) {
  const home = useQuery({ queryKey: ['home'], queryFn: api.home, refetchInterval: 60_000 })
  const connectors = useQuery({ queryKey: ['connectors'], queryFn: api.connectors, staleTime: 60_000 })
  const data = home.data

  return (
    <main className="flex min-w-0 flex-1 justify-center overflow-y-auto px-4 py-10 md:py-16">
      <div className="flex w-full max-w-[640px] flex-col gap-5">
        <h1 className="m-0 flex items-center justify-center gap-3 text-center font-serif text-[30px] font-normal tracking-tight md:text-[34px]">
          <Logo size={26} />
          {greeting()}
          {data ? `, ${data.greeting_name}` : ''}
        </h1>

        {composer}
        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}

        <ul aria-label="Bağlı sistemler" className="m-0 flex list-none gap-1.5 overflow-x-auto p-0 md:flex-wrap md:justify-center">
          {(connectors.data ?? []).map((c) => (
            <li key={c.key} className="shrink-0">
              <a
                href={`#/connectors/${c.key}`}
                title={c.status === 'not_configured' ? 'Kurulum bekliyor' : c.status === 'disabled' ? 'Yönetici şirket genelinde kapattı' : (c.detail ?? 'Bağlı')}
                aria-label={`${c.label}: ${c.status === 'connected' ? 'bağlı' : c.status === 'attention' ? 'dikkat' : c.status === 'disabled' ? 'kapalı' : 'kurulum bekliyor'}`}
                data-status={c.status}
                className={`flex items-center gap-1.5 rounded-md border border-line px-2 py-1 text-xs text-ink-2 no-underline hover:bg-raised ${c.status === 'not_configured' || c.status === 'disabled' ? 'opacity-50' : ''}`}
              >
                <span className="rounded bg-raised px-1 font-mono text-[10px] text-muted">{c.abbr}</span>
                {c.label}
                {c.status === 'attention' && <span className="size-1.5 rounded-full bg-warn" aria-hidden />}
              </a>
            </li>
          ))}
        </ul>

        {data && data.suggestions.length > 0 && (
          <div className="grid gap-2 sm:grid-cols-2">
            {data.suggestions.map((s) => {
              const Icon = SUGGESTION_ICON[s.type] ?? ChartIcon
              return (
                <button key={s.text} type="button" disabled={busy} onClick={() => onSend(s.text)} className="flex items-start gap-2.5 rounded-lg border border-line bg-surface px-3 py-2.5 text-left text-[13px] text-ink-2 hover:border-line-strong hover:bg-canvas disabled:opacity-60">
                  <Icon className="mt-0.5 shrink-0 text-muted" />
                  {s.text}
                </button>
              )
            })}
          </div>
        )}

        <div className="mt-2 grid gap-5 md:grid-cols-2">
          <section aria-labelledby="waiting-title" className="flex flex-col gap-2">
            <div className="flex items-center text-xs">
              <h2 id="waiting-title" className="m-0 flex-1 text-xs font-medium text-ink-2">
                Seni bekleyenler{data ? ` · ${data.waiting_total}` : ''}
              </h2>
              <a href={href({ name: 'inbox', itemId: null })} className="text-muted no-underline hover:text-ink">
                Tümünü gör
              </a>
            </div>
            {data && data.waiting.length === 0 && <p className="m-0 rounded-lg border border-dashed border-line px-3 py-4 text-center text-[13px] text-muted">Bekleyen işin yok</p>}
            {data?.waiting.map((w) => (
              <a key={w.id} href={href({ name: 'inbox', itemId: w.id })} className="flex gap-2.5 rounded-lg border border-line bg-surface px-3 py-2.5 text-ink no-underline hover:bg-canvas">
                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded bg-brand-soft text-brand-fg">
                  <CheckSquareIcon width={12} height={12} />
                </span>
                <span className="flex min-w-0 flex-col gap-0.5">
                  <span className="text-[13px] font-medium">
                    {w.title} <span className="font-normal text-faint">· {minutesAgo(w.since)}</span>
                  </span>
                  <span className="text-xs text-muted">{w.detail}</span>
                </span>
              </a>
            ))}
          </section>

          <section aria-labelledby="activity-title" className="flex flex-col gap-2">
            <div className="flex items-center text-xs">
              <h2 id="activity-title" className="m-0 flex-1 text-xs font-medium text-ink-2">
                Bugünkü etkinlik
              </h2>
              <a href="#/agents" className="text-muted no-underline hover:text-ink">
                Kayıt
              </a>
            </div>
            {data && data.activity.length === 0 && <p className="m-0 rounded-lg border border-dashed border-line px-3 py-4 text-center text-[13px] text-muted">Bugün henüz çalışan yok</p>}
            {data && data.activity.length > 0 && (
              <ul className="m-0 list-none overflow-hidden rounded-lg border border-line bg-surface p-0">
                {data.activity.map((a) => (
                  <li key={a.id} className="border-b border-line last:border-b-0">
                    <a href={href({ name: 'runs', executionId: a.id })} className="flex items-center gap-2.5 px-3 py-2 text-ink no-underline hover:bg-canvas">
                      <span className={`size-2 shrink-0 rounded-full ${TONE[a.tone]}`} aria-hidden />
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-[13px] font-medium">{a.title}</span>
                        <span className="truncate text-xs text-muted">{a.detail}</span>
                      </span>
                      <span className="font-mono text-xs text-muted">{a.time}</span>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      </div>
    </main>
  )
}
