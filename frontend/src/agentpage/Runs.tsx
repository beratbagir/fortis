import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { agentsApi, type AgentRunDetail } from '../api'
import { AnswerMarkdown } from '../chat/AnswerParts'
import { LiveTrace } from '../chat/LiveTrace'
import { href } from '../router'
import { RUN_STATUSES, runStatus, scoreTone, seconds, stamp, usd } from './format'
import { originLabel, RunsTable } from './Overview'
import { t } from '../i18n'

const ACTION_STATUSES = ['pending', 'approved', 'executed', 'rejected', 'failed', 'preview']
const actionStatus = (status: string) => (ACTION_STATUSES.includes(status) ? t(`agent.action_status.${status}`) : status)

/** Çalışmalar sekmesi: filtreler (durum, kaynak, test) ve seçilen çalışmanın ayrıntısı. */
export function Runs({ agentId, runId }: { agentId: number; runId: number | null }) {
  const [status, setStatus] = useState('')
  const [origin, setOrigin] = useState('')
  const [test, setTest] = useState<'0' | '1' | 'all'>('0')
  const runs = useQuery({ queryKey: ['agent-runs', agentId, status, origin, test], queryFn: () => agentsApi.runs(agentId, { status, origin, test }), refetchInterval: 15_000 })

  return (
    <div className="flex flex-col gap-4">
      {runId !== null && <RunDetail runId={runId} agentId={agentId} />}
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <Select label={t('agent.runs.col.status')} value={status} onChange={setStatus} options={[['', t('agent.runs.all')], ...RUN_STATUSES.map((k) => [k, runStatus(k)[0]] as [string, string])]} />
        <Select
          label={t('agent.runs.col.origin')}
          value={origin}
          onChange={setOrigin}
          options={[['', t('agent.runs.all')], ...(['schedule', 'event', 'manual', 'chat'] as const).map((o) => [o, originLabel(o)] as [string, string])]}
        />
        <Select label={t('agent.runs.test')} value={test} onChange={(v) => setTest(v as '0' | '1' | 'all')} options={[['0', t('agent.runs.excl_test')], ['1', t('agent.runs.only_test')], ['all', t('agent.runs.all')]]} />
      </div>
      <section aria-label={t('agent.runs.list')} className="overflow-hidden rounded-xl border border-line bg-surface">
        <RunsTable agentId={agentId} runs={runs.data ?? []} />
      </section>
    </div>
  )
}

function Select({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: [string, string][] }) {
  return (
    <label className="flex items-center gap-1.5 text-muted">
      {label}
      <select value={value} onChange={(e) => onChange(e.target.value)} className="rounded-md border border-line bg-surface px-1.5 py-1 text-ink">
        {options.map(([v, l]) => (
          <option key={v} value={v}>
            {l}
          </option>
        ))}
      </select>
    </label>
  )
}

function RunDetail({ runId, agentId }: { runId: number; agentId: number }) {
  const queryClient = useQueryClient()
  const run = useQuery({ queryKey: ['agent-run', runId], queryFn: () => agentsApi.run(runId), refetchInterval: (q) => (q.state.data && ['queued', 'running'].includes(q.state.data.status) ? 3000 : false) })
  const [comment, setComment] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (run.isError) return <p role="alert" className="m-0 text-sm text-bad">{t('agent.runs.not_found')}</p>
  if (!run.data) return null
  const r: AgentRunDetail = run.data
  const [label, dot] = runStatus(r.status)

  const vote = async (rating: 'useful' | 'not_useful') => {
    setError(null)
    try {
      queryClient.setQueryData(['agent-run', runId], await agentsApi.feedback(runId, rating, comment.trim() || undefined))
      queryClient.invalidateQueries({ queryKey: ['agent', agentId] })
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <article aria-label={t('agent.runs.run_n', { id: r.id })} className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
      <header className="flex flex-wrap items-center gap-2 text-xs text-muted">
        <span className="text-[13px] font-medium text-ink">{t('agent.runs.run_n', { id: r.id })}</span>
        <span className="inline-flex items-center gap-1.5 text-ink-2">
          <span className={`size-1.5 rounded-full ${dot}`} aria-hidden />
          {label}
        </span>
        {r.is_test && <span>test</span>}
        {r.attempts > 1 && <span className="text-brand-fg">{t('agent.runs.attempt', { n: r.attempts })}</span>}
        <span className="flex-1" />
        <span className="font-mono">{stamp(r.started_at)}</span>
        <span className="font-mono">{seconds(r.duration_seconds)}</span>
        <span className="font-mono">{usd(r.cost_usd)}</span>
        <a href={href({ name: 'agent', agentId, tab: 'runs', runId: null })} aria-label={t('agent.runs.close')} className="text-muted no-underline hover:text-ink">
          ✕
        </a>
      </header>
      {r.input && (
        <p className="m-0 rounded-lg bg-canvas px-3 py-2 text-xs whitespace-pre-wrap text-ink-2">
          <span className="text-muted">{t('agent.runs.task')} </span>
          {r.input}
        </p>
      )}
      {r.trace.length > 0 && <LiveTrace steps={r.trace} running={false} durationMs={(r.duration_seconds ?? 0) * 1000} />}
      {r.error && <p className="m-0 text-sm text-bad">{r.error}</p>}
      {r.output && (
        <div className="prose-chat text-sm">
          <AnswerMarkdown text={r.output} />
        </div>
      )}
      {r.actions.length > 0 && (
        <section aria-label={t('agent.runs.col.actions')} className="flex flex-col gap-1 border-t border-line pt-2 text-xs">
          {r.actions.map((a) => (
            <span key={a.id} className="text-ink-2">
              {a.type === 'post_teams' ? `Teams #${a.payload.channel ?? ''}` : `${t('agent.runs.email')} → ${(a.payload.to ?? []).join(', ')}`}
              {a.payload.subject ? ` · ${a.payload.subject}` : ''} <span className="text-muted">· {actionStatus(a.status)}</span>
            </span>
          ))}
        </section>
      )}
      <ScorePanel run={r} onScored={(updated) => queryClient.setQueryData(['agent-run', runId], updated)} />
      <footer className="flex flex-wrap items-center gap-2 border-t border-line pt-3 text-xs">
        <span className="text-muted">{t('agent.runs.was_useful')}</span>
        <button type="button" aria-pressed={r.feedback.mine === 'useful'} onClick={() => vote('useful')} className={`rounded-md border px-2 py-1 ${r.feedback.mine === 'useful' ? 'border-ok bg-ok-soft text-ok' : 'border-line hover:bg-raised'}`}>
          {t('agent.overview.useful')}
        </button>
        <button type="button" aria-pressed={r.feedback.mine === 'not_useful'} onClick={() => vote('not_useful')} className={`rounded-md border px-2 py-1 ${r.feedback.mine === 'not_useful' ? 'border-bad bg-bad-soft text-bad' : 'border-line hover:bg-raised'}`}>
          {t('agent.overview.not_useful')}
        </button>
        <input value={comment} onChange={(e) => setComment(e.target.value)} placeholder={t('agent.runs.comment')} aria-label={t('agent.runs.comment_label')} className="min-w-40 flex-1 rounded-md border border-line bg-surface px-2 py-1 text-ink" />
        <span className="font-mono text-muted">
          {t('agent.runs.votes', { useful: r.feedback.useful, not: r.feedback.not_useful })}
        </span>
        {r.conversation_id && (
          <a href={href({ name: 'chat', conversationId: r.conversation_id })} className="text-brand-fg">
            {t('agent.runs.ask_agent')}
          </a>
        )}
        {error && <span role="alert" className="text-bad">{error}</span>}
      </footer>
    </article>
  )
}

/** Değerlendirme puanı: kural başına geçti/kaldı ve gerekçe; sahibi/admin şimdi (yeniden) puanlatabilir. */
function ScorePanel({ run, onScored }: { run: AgentRunDetail; onScored: (run: AgentRunDetail) => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  if (!run.output) return null

  const rescore = async () => {
    setBusy(true)
    setError(null)
    try {
      onScored(await agentsApi.score(run.id))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-label={t('agent.runs.evaluation')} className="flex flex-col gap-1.5 border-t border-line pt-2 text-xs">
      <div className="flex items-center gap-2">
        <span className="font-medium text-ink-2">{t('agent.runs.evaluation')}</span>
        {run.score && <span className={`font-mono text-[13px] ${scoreTone(run.score.score)}`}>{run.score.score}/100</span>}
        <span className="flex-1" />
        <button type="button" disabled={busy} onClick={rescore} className="rounded-md border border-line px-2 py-0.5 text-[11px] text-ink-2 hover:bg-raised disabled:opacity-60">
          {run.score ? t('agent.runs.rescore') : t('agent.runs.score')}
        </button>
      </div>
      {!run.score && !error && <span className="text-muted">{t('agent.runs.not_scored')}</span>}
      {run.score && (
        <ul className="m-0 flex list-none flex-col gap-1 p-0">
          {run.score.details.map((d) => (
            <li key={d.rule} className="flex gap-2">
              <span className={d.passed ? 'text-ok' : 'text-bad'} aria-label={d.passed ? t('agent.runs.passed') : t('agent.runs.failed_rule')}>
                {d.passed ? '✓' : '✗'}
              </span>
              <span className="text-ink-2">
                {d.rule} <span className="text-muted">— {d.reason}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
      {error && <span role="alert" className="text-bad">{error}</span>}
    </section>
  )
}
