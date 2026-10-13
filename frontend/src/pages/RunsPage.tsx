import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { runsApi, type RunDetail, type RunStep, type RunSummary } from '../editor/api'
import { executionState, formatDuration, triggerLabel } from '../format'
import { href, navigate } from '../router'
import { clock, isoDuration, when } from '../ui/format'

type Filter = 'all' | 'paused' | 'failed'

const RUNNING = ['CREATED', 'QUEUED', 'SUBMITTED', 'RUNNING', 'RESTARTED', 'RETRYING', 'KILLING']

/** Durum işareti: renk tek başına anlam taşımasın diye simge ve metin birlikte. */
function mark(state: string | null): { glyph: string; bg: string; fg: string } {
  switch (state) {
    case 'SUCCESS':
      return { glyph: '✓', bg: 'var(--success-soft)', fg: 'var(--success)' }
    case 'PAUSED':
      return { glyph: '‖', bg: 'var(--brand-soft)', fg: 'var(--brand-fg)' }
    case 'FAILED':
    case 'KILLED':
      return { glyph: '!', bg: 'var(--danger-soft)', fg: 'var(--danger)' }
    case 'WARNING':
      return { glyph: '!', bg: 'var(--warning-soft)', fg: 'var(--warning)' }
    case null:
      return { glyph: '·', bg: 'var(--surface-raised)', fg: 'var(--text-faint)' }
    default:
      return RUNNING.includes(state) ? { glyph: '…', bg: 'var(--info-soft)', fg: 'var(--info)' } : { glyph: '·', bg: 'var(--surface-raised)', fg: 'var(--text-faint)' }
  }
}

export function RunsPage({ executionId }: { executionId: string | null }) {
  const [filter, setFilter] = useState<Filter>('all')
  const [page, setPage] = useState(1)
  const list = useQuery({ queryKey: ['runs', filter, page], queryFn: () => runsApi.list({ status: filter, page }), refetchInterval: 15_000 })
  const selectedId = executionId ?? list.data?.data[0]?.kestra_execution_id ?? null

  const counts = list.data?.counts
  const tabs: { key: Filter; label: string }[] = [
    { key: 'all', label: 'Tümü' },
    { key: 'paused', label: `Onay bekleyen${counts ? ` · ${counts.paused}` : ''}` },
    { key: 'failed', label: `Hatalı${counts ? ` · ${counts.failed}` : ''}` },
  ]

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:p-8">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Çalışmalar</h1>
          <div className="flex-1" />
          <div role="tablist" aria-label="Filtre" className="flex gap-1 rounded-xl bg-raised p-1">
            {tabs.map((t) => (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={filter === t.key}
                onClick={() => {
                  setFilter(t.key)
                  setPage(1)
                }}
                className={`h-[34px] rounded-[9px] px-3.5 text-[13px] font-bold ${filter === t.key ? 'bg-surface text-ink shadow-sm' : 'text-muted'}`}
              >
                {t.label}
              </button>
            ))}
          </div>
        </div>

        {list.data?.warning && <p className="m-0 rounded-xl bg-warn-soft px-4 py-3 text-sm text-warn">{list.data.warning}</p>}
        {list.error && <p role="alert" className="m-0 rounded-xl bg-bad-soft px-4 py-3 text-sm text-bad">{(list.error as Error).message}</p>}

        <div className="flex flex-wrap items-start gap-5">
          <section aria-label="Çalışma listesi" className="flex min-w-0 flex-[1_1_380px] flex-col gap-2">
            {list.isLoading && <p className="m-0 text-muted">Yükleniyor…</p>}
            {list.data?.data.length === 0 && <p className="m-0 rounded-2xl border border-line bg-surface p-4 text-sm text-muted">Bu filtrede çalışma yok.</p>}
            {list.data?.data.map((run) => (
              <RunRow key={run.kestra_execution_id} run={run} selected={run.kestra_execution_id === selectedId} />
            ))}
            {list.data && list.data.total > page * 20 && (
              <div className="flex gap-2">
                {page > 1 && (
                  <button type="button" onClick={() => setPage(page - 1)} className="h-9 rounded-xl border border-line bg-surface px-3 text-sm font-bold">
                    Önceki
                  </button>
                )}
                <button type="button" onClick={() => setPage(page + 1)} className="h-9 rounded-xl border border-line bg-surface px-3 text-sm font-bold">
                  Daha eski
                </button>
              </div>
            )}
          </section>

          {selectedId && <RunDetailView key={selectedId} id={selectedId} />}
        </div>
      </div>
    </main>
  )
}

function RunRow({ run, selected }: { run: RunSummary; selected: boolean }) {
  const m = mark(run.state)
  const state = executionState(run.state)

  return (
    <a
      href={href({ name: 'runs', executionId: run.kestra_execution_id })}
      aria-current={selected ? 'true' : undefined}
      className={`flex items-center gap-3 rounded-2xl bg-surface px-4 py-3.5 text-ink no-underline ${selected ? 'border-2 border-brand' : 'border border-line hover:border-line-strong'}`}
    >
      <span className="flex size-9 shrink-0 items-center justify-center rounded-[11px] text-sm font-extrabold" style={{ background: m.bg, color: m.fg }} aria-hidden>
        {m.glyph}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-px">
        <span className="truncate text-sm font-extrabold">
          {run.flow.title}
          {run.is_test && <span className="ml-2 rounded-full bg-raised px-2 py-0.5 text-[11px] text-ink-2">Test</span>}
        </span>
        <span className="text-xs font-semibold text-faint">
          {when(run.start)} · {formatDuration(run.duration) || '—'} · {triggerLabel(run.trigger)}
        </span>
      </span>
      <span className="shrink-0 rounded-full px-2.5 py-1 text-xs font-extrabold" style={{ background: m.bg, color: m.fg }}>
        {state.label}
      </span>
    </a>
  )
}

function stepDetail(step: RunStep): string {
  if (step.state === null) return 'Henüz çalışmadı'
  const parts = [step.label ?? step.kind ?? 'Adım']
  if (step.output_summary) parts.push(step.output_summary)
  if (step.runs > 1) parts.push(`${step.runs} kez çalıştı`)
  if (step.state === 'PAUSED') parts.push('onay bekleniyor')
  if (step.duration) parts.push(formatDuration(step.duration))
  return parts.join(' · ')
}

function timeoutText(paused: NonNullable<RunDetail['paused']>): string | null {
  if (!paused.timeout) return null
  const duration = isoDuration(paused.timeout)
  const outcome = { CANCEL: 'otomatik reddedilir', FAIL: 'hata ile biter', RESUME: 'otomatik devam eder', WARN: 'uyarıyla devam eder' }[paused.on_timeout ?? 'CANCEL'] ?? 'zaman aşımına uğrar'
  return `Yanıt verilmezse ${duration} sonra ${outcome}.`
}

function RunDetailView({ id }: { id: string }) {
  const queryClient = useQueryClient()
  const detail = useQuery({
    queryKey: ['run', id],
    queryFn: () => runsApi.get(id),
    refetchInterval: (query) => (query.state.data && !query.state.data.finished ? 3_000 : false),
  })
  const [panel, setPanel] = useState<'logs' | 'outputs' | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [explanation, setExplanation] = useState<{ explanation: string; sent: { task: string | null; type: string; message: string }[] } | null>(null)
  const run = detail.data

  // Detayda durum değişince listedeki rozet de güncellensin.
  const state = run?.state
  useEffect(() => {
    if (state) queryClient.invalidateQueries({ queryKey: ['runs'] })
  }, [state, queryClient])

  const act = async (name: string, action: () => Promise<unknown>) => {
    setBusy(name)
    setError(null)
    try {
      const result = await action()
      if (result && typeof result === 'object' && 'kestra_execution_id' in result) queryClient.setQueryData(['run', id], result)
      queryClient.invalidateQueries({ queryKey: ['runs'] })
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(null)
    }
  }

  if (detail.error) return <section className="flex-[1_1_480px] rounded-[20px] border border-line bg-surface p-6 text-bad">{(detail.error as Error).message}</section>
  if (!run) return <section className="flex-[1_1_480px] rounded-[20px] border border-line bg-surface p-6 text-muted">Yükleniyor…</section>

  const label = executionState(run.state)
  const m = mark(run.state)
  const failed = run.state === 'FAILED' || run.state === 'WARNING'

  return (
    <section aria-label="Çalışma ayrıntısı" className="flex min-w-0 flex-[1_1_480px] flex-col gap-5 rounded-[20px] border border-line bg-surface p-6 shadow-[0_6px_24px_var(--shadow)]">
      <div className="flex flex-wrap items-center gap-2.5">
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-bold text-faint">
            {when(run.started_at)} · {formatDuration(run.duration) || '—'}
            {run.is_test && ' · Test (kuru çalıştırma)'}
          </span>
          <h2 className="m-0 text-xl font-extrabold">{run.flow.title}</h2>
        </div>
        <div className="flex-1" />
        <span className="rounded-full px-3 py-1.5 text-xs font-extrabold" style={{ background: m.bg, color: m.fg }} aria-live="polite">
          {label.label}
        </span>
      </div>

      <ol className="m-0 flex list-none flex-col p-0">
        {run.steps.map((step, i) => {
          const sm = step.state === 'PAUSED' ? { glyph: '‖', bg: 'var(--brand)', fg: 'var(--on-brand)' } : mark(step.state)
          return (
            <li key={`${step.task_id}-${i}`} className="flex gap-3.5" style={{ paddingLeft: step.depth * 18 }}>
              <div className="flex flex-col items-center">
                <span className="flex size-[26px] items-center justify-center rounded-full text-xs font-extrabold" style={{ background: sm.bg, color: sm.fg }} aria-hidden>
                  {sm.glyph}
                </span>
                {i < run.steps.length - 1 && <span className="min-h-[18px] w-0.5 flex-1 bg-line" />}
              </div>
              <div className="flex flex-1 flex-col gap-0.5 pt-0.5 pb-4">
                <span className="text-sm font-extrabold">
                  {step.title ?? step.task_id}
                  <span className="sr-only"> — {executionState(step.state).label}</span>
                  {step.section !== 'tasks' && <span className="ml-2 text-xs font-semibold text-faint">({step.section === 'errors' ? 'hata dalı' : 'son adım'})</span>}
                </span>
                <span className="text-[13px] font-medium text-muted">{stepDetail(step)}</span>
              </div>
              <span className="pt-1 font-mono text-xs text-faint">{step.started_at ? clock(step.started_at) : '—'}</span>
            </li>
          )
        })}
      </ol>

      {run.state === 'PAUSED' && run.paused && (
        <div className="flex flex-col gap-3 rounded-2xl bg-brand-tint p-4.5">
          <span className="text-[15px] font-extrabold text-brand-fg">Devam etmek için onayın gerekiyor</span>
          <span className="text-sm leading-relaxed font-medium text-ink-2">{run.paused.question ?? `“${run.paused.task_id}” adımı onay bekliyor.`}</span>
          <div className="flex gap-2">
            <button type="button" disabled={busy !== null} onClick={() => act('resume', () => runsApi.resume(id, true))} className="h-[42px] rounded-xl bg-brand px-4.5 text-sm font-bold text-on-brand disabled:opacity-60">
              {busy === 'resume' ? 'Gönderiliyor…' : 'Devam et'}
            </button>
            <button type="button" disabled={busy !== null} onClick={() => act('reject', () => runsApi.resume(id, false))} className="h-[42px] rounded-xl border border-line-strong bg-surface px-4.5 text-sm font-bold text-brand-fg disabled:opacity-60">
              Reddet
            </button>
          </div>
          {timeoutText(run.paused) && <span className="text-xs font-semibold text-muted">{timeoutText(run.paused)}</span>}
        </div>
      )}

      {error && <p role="alert" className="m-0 rounded-xl bg-bad-soft px-3 py-2 text-[13px] text-bad">{error}</p>}

      {explanation && (
        <div className="flex flex-col gap-2 rounded-2xl border border-line bg-canvas p-4 text-sm">
          <strong>AI açıklaması</strong>
          <div className="prose-chat">
            <Markdown remarkPlugins={[remarkGfm]}>{explanation.explanation}</Markdown>
          </div>
          <details className="text-xs text-muted">
            <summary className="cursor-pointer">AI'a ne gönderildi?</summary>
            Sadece görev adı, hata türü ve Fortis'in yazdığı kısa açıklama. Ham hata metni, görev çıktıları ve loglar gönderilmedi.
            <ul className="m-0 mt-1 pl-4">
              {explanation.sent.map((s) => (
                <li key={s.task ?? s.type}>
                  <span className="font-mono">{s.task ?? '(akış)'}</span> · {s.type}: {s.message}
                </li>
              ))}
            </ul>
          </details>
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        <button type="button" aria-expanded={panel === 'logs'} onClick={() => setPanel(panel === 'logs' ? null : 'logs')} className="h-[38px] rounded-xl border border-line bg-surface px-3.5 text-[13px] font-bold">
          Logları gör
        </button>
        <button type="button" aria-expanded={panel === 'outputs'} onClick={() => setPanel(panel === 'outputs' ? null : 'outputs')} className="h-[38px] rounded-xl border border-line bg-surface px-3.5 text-[13px] font-bold">
          Çıktıları gör
        </button>
        {failed && (
          <button type="button" disabled={busy !== null} onClick={() => act('explain', async () => setExplanation(await runsApi.explain(id)))} className="h-[38px] rounded-xl border border-line bg-surface px-3.5 text-[13px] font-bold disabled:opacity-60">
            {busy === 'explain' ? 'Açıklanıyor…' : "AI'a hatayı açıklat"}
          </button>
        )}
        {!run.finished && run.state !== 'PAUSED' && (
          <button type="button" disabled={busy !== null} onClick={() => act('kill', () => runsApi.kill(id))} className="h-[38px] rounded-xl border border-line bg-surface px-3.5 text-[13px] font-bold text-bad">
            Durdur
          </button>
        )}
        {run.flow_id && (
          <button type="button" onClick={() => navigate({ name: 'editor', flowId: run.flow_id })} className="h-[38px] rounded-xl border border-line bg-surface px-3.5 text-[13px] font-bold">
            Akışı aç
          </button>
        )}
      </div>

      {panel === 'logs' && <Logs id={id} />}
      {panel === 'outputs' && (
        <div className="rounded-xl border border-line text-[13px]">
          <p className="m-0 border-b border-line bg-canvas px-3 py-2 text-xs text-muted">Görev çıktıları Fortis'te saklanmaz; sadece özetleri (kayıt sayısı, HTTP kodu) gösterilir. Ayrıntı için loglara bakın.</p>
          <ul className="m-0 list-none p-0">
            {run.steps.filter((s) => s.state !== null).map((s, i) => (
              <li key={i} className="flex justify-between gap-3 border-b border-line px-3 py-2 last:border-b-0">
                <span className="font-mono">{s.task_id}</span>
                <span className="text-muted">{s.output_summary ?? (s.runs > 1 ? `${s.runs} çalışma` : '—')}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

function Logs({ id }: { id: string }) {
  const [page, setPage] = useState(1)
  const logs = useQuery({ queryKey: ['run-logs', id, page], queryFn: () => runsApi.logs(id, page) })
  const levels: Record<string, string> = { ERROR: 'text-[var(--tone-red-fg)]', WARN: 'text-[var(--tone-amber-fg)]' }

  return (
    <div className="flex flex-col gap-2">
      <div className="max-h-80 overflow-auto rounded-xl bg-inverse p-3 font-mono text-xs leading-relaxed text-on-inverse">
        {logs.data?.data.length === 0 && <span>Log yok.</span>}
        {logs.data?.data.map((log, i) => (
          <div key={i}>
            <span className="opacity-70">{clock(log.timestamp)} </span>
            <span className={levels[log.level] ?? ''}>{log.level}</span>{' '}
            {log.task_id && <span className="opacity-70">[{log.task_id}] </span>}
            {log.message}
          </div>
        ))}
      </div>
      {logs.data && logs.data.total > logs.data.per_page && (
        <div className="flex items-center gap-2 text-xs">
          <button type="button" disabled={page === 1} onClick={() => setPage(page - 1)} className="h-8 rounded-lg border border-line px-2 font-bold disabled:opacity-50">
            Önceki
          </button>
          <span>
            {page} / {Math.ceil(logs.data.total / logs.data.per_page)}
          </span>
          <button type="button" disabled={page * logs.data.per_page >= logs.data.total} onClick={() => setPage(page + 1)} className="h-8 rounded-lg border border-line px-2 font-bold disabled:opacity-50">
            Sonraki
          </button>
        </div>
      )}
    </div>
  )
}
