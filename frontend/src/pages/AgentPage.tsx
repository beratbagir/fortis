import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useRef, useState } from 'react'
import { agentsApi } from '../api'
import { Chat } from '../agentpage/Chat'
import { Config } from '../agentpage/Config'
import { Evaluation } from '../agentpage/Evaluation'
import { Overview } from '../agentpage/Overview'
import { Runs } from '../agentpage/Runs'
import { Versions } from '../agentpage/Versions'
import { useAuth } from '../auth/useAuth'
import { href, navigate, type AgentTab } from '../router'
import { STATUS_BADGE } from '../agentpage/format'

const TABS: { key: AgentTab; label: string }[] = [
  { key: 'overview', label: 'Genel bakış' },
  { key: 'chat', label: 'Sohbet' },
  { key: 'runs', label: 'Çalışmalar' },
  { key: 'config', label: 'Yapılandırma' },
  { key: 'evaluation', label: 'Değerlendirme' },
  { key: 'versions', label: 'Sürümler' },
]

/** Agent sayfası (#/agents/:id): başlık (durum, sürüm, Duraklat / Architect'te düzenle / Şimdi çalıştır) ve sekmeler. */
export function AgentPage({ agentId, tab, runId }: { agentId: number; tab: AgentTab; runId: number | null }) {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const agent = useQuery({ queryKey: ['agent', agentId], queryFn: () => agentsApi.get(agentId), refetchInterval: 30_000 })
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'ok' | 'bad'; text: string } | null>(null)
  const dirty = useRef(false)
  const onDirty = useCallback((value: boolean) => {
    dirty.current = value
  }, [])

  if (agent.isError) {
    return (
      <main className="flex flex-1 items-center justify-center p-8">
        <p role="alert" className="m-0 text-sm text-muted">
          Agent bulunamadı.
        </p>
      </main>
    )
  }
  if (!agent.data) return null
  const a = agent.data
  const [statusLabel, statusTone] = STATUS_BADGE[a.status]
  const canBuild = user?.abilities.build ?? false

  const act = async (work: () => Promise<unknown>, done: string) => {
    setBusy(true)
    setMessage(null)
    try {
      await work()
      setMessage({ tone: 'ok', text: done })
      await queryClient.invalidateQueries({ queryKey: ['agent', agentId] })
      queryClient.invalidateQueries({ queryKey: ['agents', 'registry'] })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    } finally {
      setBusy(false)
    }
  }

  const go = (next: AgentTab) => {
    if (next === tab) return
    if (dirty.current && !window.confirm('Kaydedilmemiş değişiklikler kaybolacak. Devam edilsin mi?')) return
    dirty.current = false
    navigate({ name: 'agent', agentId, tab: next, runId: null })
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <header className="flex flex-col gap-3 px-4 pt-5 md:px-8">
        <nav aria-label="Kırıntı" className="text-xs text-muted">
          <a href={href({ name: 'agents' })} className="text-muted no-underline hover:text-ink">
            Agent Kaydı
          </a>{' '}
          / <span className="text-ink-2">{a.name}</span>
        </nav>
        <div className="flex flex-wrap items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-ink font-mono text-[12px] text-bg" aria-hidden>
            {a.icon}
          </span>
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            <h1 className="m-0 flex flex-wrap items-center gap-2 font-serif text-[26px] font-normal tracking-tight">
              {a.name}
              <span className={`rounded px-1.5 py-px font-sans text-[11px] font-medium ${statusTone}`}>{statusLabel}</span>
              <span className="font-mono text-xs text-muted">v{a.version}</span>
            </h1>
            {a.description && <p className="m-0 max-w-[640px] text-[13px] text-ink-2">{a.description}</p>}
            <p className="m-0 text-[11px] text-muted">{[a.department, a.owner ? `Sahibi: ${a.owner.name}` : null, a.trigger].filter(Boolean).join(' · ')}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            {a.can_manage && canBuild && (a.status === 'pilot' || a.status === 'live') && (
              <button type="button" disabled={busy} onClick={() => act(() => agentsApi.status(a.id, 'paused'), 'Agent duraklatıldı; zamanlamalar devre dışı.')} className={SECONDARY}>
                Duraklat
              </button>
            )}
            {a.can_manage && canBuild && a.status === 'paused' && (
              <button type="button" disabled={busy} onClick={() => act(() => agentsApi.status(a.id, 'pilot'), 'Agent pilota döndü.')} className={SECONDARY}>
                Devam ettir
              </button>
            )}
            {a.can_manage && canBuild && (
              <a href={href({ name: 'architect', agentId: a.id })} className={`${SECONDARY} no-underline`}>
                Architect'te düzenle
              </a>
            )}
            {(a.status === 'pilot' || a.status === 'live') && (
              <button
                type="button"
                disabled={busy}
                onClick={() => act(() => agentsApi.runNow(a.id), 'Çalışma kuyruğa alındı; Çalışmalar sekmesinde görünür.')}
                className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-60"
              >
                Şimdi çalıştır
              </button>
            )}
          </div>
        </div>
        {message && (
          <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
            {message.text}
          </p>
        )}
        <div role="tablist" aria-label="Agent sekmeleri" className="flex gap-1 overflow-x-auto border-b border-line">
          {TABS.map((t) => (
            <button
              key={t.key}
              role="tab"
              type="button"
              aria-selected={tab === t.key}
              onClick={() => go(t.key)}
              className={`-mb-px shrink-0 border-b-2 px-2.5 py-2 text-[13px] ${tab === t.key ? 'border-ink font-medium text-ink' : 'border-transparent text-muted hover:text-ink'}`}
            >
              {t.label}
            </button>
          ))}
        </div>
      </header>

      <div className="px-4 py-5 md:px-8">
        {tab === 'overview' && <Overview agent={a} />}
        {tab === 'chat' && <Chat agent={a} />}
        {tab === 'runs' && <Runs agentId={a.id} runId={runId} />}
        {tab === 'config' && <Config agent={a} onDirty={onDirty} />}
        {tab === 'evaluation' && <Evaluation agentId={a.id} />}
        {tab === 'versions' && <Versions agent={a} />}
      </div>
    </main>
  )
}

const SECONDARY = 'rounded-lg border border-line px-3 py-1.5 text-[13px] text-ink hover:bg-raised disabled:opacity-60'
