import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { architectPrompt, buildApi, type AgentRunInfo, type AgentStatus, type ArchitectAgent } from '../api'
import { ArchitectChat } from '../architect/ArchitectChat'
import { PlanView } from '../architect/PlanView'
import { AnswerMarkdown } from '../chat/AnswerParts'
import { href } from '../router'

const STATUS_LABEL: Record<AgentStatus, string> = { draft: 'taslak', pilot: 'pilot', live: 'canlı', paused: 'duraklatıldı', template: 'şablon' }
const RUN_LABEL: Record<AgentRunInfo['status'], string> = {
  queued: 'Kuyrukta',
  running: 'Çalışıyor',
  waiting_approval: 'Bitti · gönderimler önizlendi',
  waiting_input: 'Soru bekliyor',
  succeeded: 'Başarılı',
  failed: 'Hata',
  cancelled: 'İptal',
}
const finished = (run: AgentRunInfo) => !['queued', 'running'].includes(run.status)

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }) : '')

/** Architect (#/build/agents/:id): solda sohbet, sağda plan; son veriyle test, pilota al, canlıya al. */
export function ArchitectPage({ agentId }: { agentId: number }) {
  const queryClient = useQueryClient()
  const agent = useQuery({ queryKey: ['architect', agentId], queryFn: () => buildApi.architect(agentId) })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [testRunId, setTestRunId] = useState<number | null>(null)
  // Oluştur ekranından veya sohbetten gelen ilk mesaj (bir kez).
  const [firstMessage] = useState(() => architectPrompt.take(agentId))

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['architect', agentId] })

  const act = async (work: () => Promise<unknown>) => {
    setBusy(true)
    setError(null)
    try {
      await work()
      await refresh()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'İşlem yapılamadı.')
    } finally {
      setBusy(false)
    }
  }

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
  const testInput = a.triggers.find((t) => t.type !== 'manual' && t.input_template)?.input_template ?? 'Görevini şimdi yap.'

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-4 py-2.5">
        <a href={href({ name: 'build' })} aria-label="Oluştur'a dön" className="text-muted no-underline hover:text-ink">
          ←
        </a>
        <h1 className="m-0 font-serif text-[18px] font-normal">Architect</h1>
        <span className="min-w-0 truncate text-[13px] text-muted">
          {a.name} · <span data-status={a.status}>{STATUS_LABEL[a.status]}</span>
        </span>
        <span className="flex-1" />
        {a.has_draft && a.updated_at && <span className="text-[11px] text-faint">Otomatik kaydedildi {time(a.updated_at)}</span>}
        <button
          type="button"
          disabled={busy || !a.has_draft}
          onClick={() =>
            act(async () => {
              const run = await buildApi.run(a.id, { test: true, input: testInput })
              setTestRunId(run.id)
            })
          }
          className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised disabled:opacity-50"
        >
          Son veriyle test et
        </button>
        <StatusButton agent={a} busy={busy} onStatus={(status) => act(() => buildApi.status(a.id, status))} />
      </header>

      {error && (
        <p role="alert" className="m-0 border-b border-line bg-bad-soft px-4 py-2 text-sm text-bad">
          {error}
        </p>
      )}

      <div className="flex min-h-0 flex-1 flex-col md:flex-row">
        <aside className="flex min-h-[320px] flex-col border-line md:w-[360px] md:shrink-0 md:border-r">
          <ArchitectChat conversationId={a.conversation_id} onChanged={refresh} firstMessage={firstMessage} />
        </aside>
        <section aria-label="Plan" className="min-h-0 flex-1 overflow-y-auto bg-canvas bg-[radial-gradient(var(--color-line)_1px,transparent_1px)] [background-size:16px_16px] px-4 py-8">
          <PlanView agent={a} />
          <TestResult key={testRunId ?? a.last_test_run?.id ?? 0} initial={a.last_test_run} runId={testRunId} onFinished={refresh} />
        </section>
      </div>
    </div>
  )
}

function StatusButton({ agent, busy, onStatus }: { agent: ArchitectAgent; busy: boolean; onStatus: (status: 'pilot' | 'live' | 'paused') => void }) {
  const primary = 'rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50'

  if (agent.status === 'draft' || agent.status === 'paused') {
    return (
      <button type="button" disabled={busy || !agent.has_draft} onClick={() => onStatus('pilot')} className={primary}>
        Pilota al
      </button>
    )
  }
  if (agent.status === 'pilot') {
    return (
      <button type="button" disabled={busy || !agent.can_go_live} title={agent.can_go_live ? undefined : 'Önce en az bir başarılı pilot çalışması gerekiyor.'} onClick={() => onStatus('live')} className={primary}>
        Canlıya al
      </button>
    )
  }

  return (
    <button type="button" disabled={busy} onClick={() => onStatus('paused')} className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised disabled:opacity-50">
      Duraklat
    </button>
  )
}

/** Son test çalışması: biter bitmez sonuç, önizlenen gönderimler ve maliyet. Yazan aksiyonlar testte hiç çalışmaz. */
function TestResult({ initial, runId, onFinished }: { initial: AgentRunInfo | null; runId: number | null; onFinished: () => void }) {
  const id = runId ?? initial?.id ?? null
  const run = useQuery({
    queryKey: ['agent-run', id],
    queryFn: () => buildApi.getRun(id!),
    enabled: id !== null,
    initialData: runId === null && initial ? initial : undefined,
    refetchInterval: (q) => (q.state.data && finished(q.state.data) ? false : 2000),
  })
  const data = run.data
  const done = data ? finished(data) : false

  useEffect(() => {
    if (done && runId !== null) onFinished()
  }, [done]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!data) return null

  return (
    <section aria-label="Test sonucu" className="mx-auto mt-6 flex w-full max-w-[560px] flex-col gap-2 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex items-center gap-2">
        <span className="font-medium">Son veriyle test</span>
        <span className={`rounded px-1.5 py-px text-[10px] ${data.status === 'failed' ? 'bg-bad-soft text-bad' : done ? 'bg-ok-soft text-ok' : 'bg-raised text-ink-2'}`}>{RUN_LABEL[data.status]}</span>
        <span className="flex-1" />
        {done && <span className="font-mono text-[11px] text-muted">${data.cost_usd.toFixed(3)}</span>}
      </div>
      {!done && <p className="m-0 text-xs text-muted">Çalışma sürüyor… (yerelde kuyruk işçisi gerekir: php artisan queue:work --queue=agents)</p>}
      {data.error && <p className="m-0 text-xs text-bad">{data.error}</p>}
      {data.output && (
        <div className="prose-chat max-h-72 overflow-y-auto text-[13px]">
          <AnswerMarkdown text={data.output} />
        </div>
      )}
      {data.actions.length > 0 && (
        <div className="flex flex-col gap-1 border-t border-line pt-2">
          <span className="text-xs font-medium text-ink-2">Bu çalışma şunları gönderecekti (test, gönderilmedi)</span>
          <ul className="m-0 flex list-none flex-col gap-1 p-0 text-xs text-muted">
            {data.actions.map((action) => (
              <li key={action.id}>
                {action.type === 'post_teams' ? `Teams #${action.payload.channel ?? ''}` : `E-posta → ${(action.payload.to ?? []).join(', ')}`}
                {action.payload.subject ? ` · ${action.payload.subject}` : ''}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
