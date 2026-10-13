import { useEffect, useState } from 'react'
import { resumeExecution, watchExecution, type Execution } from '../api'
import { executionState, formatDuration, initialValues } from '../format'
import { InputFields } from './InputFields'

interface Props {
  execution: Execution
  onAsk: (text: string) => void
}


const STEP_ICONS: Record<string, string> = { SUCCESS: '✓', FAILED: '✕', RUNNING: '…', PAUSED: '⏸', WARNING: '!', KILLED: '■', SKIPPED: '–' }

const timeFormat = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'medium' })

export function ExecutionCard({ execution: initial, onAsk }: Props) {
  const [execution, setExecution] = useState(initial)
  const [error, setError] = useState<string | null>(null)
  const [showLogs, setShowLogs] = useState(false)
  const [resumeValues, setResumeValues] = useState<Record<string, unknown>>({})
  const [resuming, setResuming] = useState(false)

  const state = executionState(execution.state)
  const paused = execution.state === 'PAUSED' ? execution.paused : null
  const extraInputs = paused?.inputs.filter((i) => i.id !== paused.approval_input) ?? []

  // Bitmemiş çalışmayı canlı izle. Devam ettirildiğinde (finished false kalır) izleme sürer.
  useEffect(() => {
    if (execution.finished) return
    return watchExecution(execution.id, (updated) => { setExecution(updated); setError(null) }, setError)
  }, [execution.id, execution.finished])

  const resume = async (approved: boolean) => {
    setResuming(true)
    setError(null)
    try {
      setExecution(await resumeExecution(execution.id, approved, { ...initialValues(extraInputs), ...resumeValues }))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setResuming(false)
    }
  }

  return (
    <div className="rounded-xl border border-line bg-canvas">
      <div className="flex flex-wrap items-center gap-2 px-3 py-2">
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${state.className}`}>{state.label}</span>
        {!execution.finished && execution.state !== 'PAUSED' && (
          <span className="h-1.5 w-1.5 animate-ping rounded-full bg-info" aria-hidden />
        )}
        <span className="text-xs text-muted">
          {execution.started_at ? timeFormat.format(new Date(execution.started_at)) : 'Başlatılıyor'}
          {execution.duration && ` · ${formatDuration(execution.duration)}`}
        </span>
        <span className="ml-auto font-mono text-[11px] text-faint">{execution.kestra_execution_id}</span>
      </div>

      {execution.task_runs.length > 0 && (
        <ol className="space-y-0.5 border-t border-line px-3 py-2 text-sm">
          {execution.task_runs.map((run, i) => (
            <li key={`${run.task_id}-${i}`} className="flex items-center gap-2">
              <span className={`w-4 text-center ${run.state === 'FAILED' ? 'text-bad' : run.state === 'SUCCESS' ? 'text-ok' : 'text-muted'}`}>
                {STEP_ICONS[run.state] ?? '·'}
              </span>
              <span className="font-mono text-xs">{run.task_id}</span>
              {run.value && <span className="text-xs text-muted">({run.value})</span>}
              {run.attempts > 1 && <span className="text-xs text-warn">{run.attempts} deneme</span>}
              <span className="ml-auto text-xs text-faint">{formatDuration(run.duration)}</span>
            </li>
          ))}
        </ol>
      )}

      {paused && (
        <div className="space-y-2 border-t border-warn/30 bg-warn-soft px-3 py-2 text-sm">
          <div className="font-medium text-warn">Bu çalışma onayınızı bekliyor ({paused.task_id}).</div>
          {extraInputs.length > 0 && <InputFields inputs={extraInputs} values={resumeValues} onChange={setResumeValues} />}
          <div className="flex gap-2">
            <button
              type="button"
              disabled={resuming}
              onClick={() => resume(true)}
              className="rounded-lg bg-ok px-3 py-1.5 text-sm font-medium text-bg hover:opacity-90 disabled:bg-line-strong"
            >
              Devam et
            </button>
            <button
              type="button"
              disabled={resuming}
              onClick={() => resume(false)}
              className="rounded-lg border border-bad/30 px-3 py-1.5 text-sm font-medium text-bad hover:bg-bad-soft disabled:opacity-50"
            >
              Reddet
            </button>
          </div>
          {!paused.approval_input && <div className="text-xs text-warn">Reddet çalışmayı durdurur.</div>}
        </div>
      )}

      {error && <div className="border-t border-bad/30 bg-bad-soft px-3 py-2 text-sm text-bad">{error}</div>}

      <div className="flex items-center gap-3 border-t border-line px-3 py-1.5">
        {execution.logs.length > 0 && (
          <button type="button" onClick={() => setShowLogs((v) => !v)} className="text-xs text-ink-2 hover:text-ink">
            {showLogs ? 'Logları gizle' : `Loglar (${execution.logs.length})`}
          </button>
        )}
        {(execution.state === 'FAILED' || execution.state === 'WARNING') && (
          <button
            type="button"
            onClick={() => onAsk(`"${execution.flow.title}" akışının çalışması (${execution.kestra_execution_id}) neden ${execution.state === 'FAILED' ? 'hata verdi' : 'uyarıyla bitti'}?`)}
            className="ml-auto text-xs font-medium text-bad hover:text-bad"
          >
            Neden {execution.state === 'FAILED' ? 'hata verdi' : 'uyarı verdi'}?
          </button>
        )}
      </div>

      {showLogs && (
        <pre className="max-h-64 overflow-auto border-t border-line bg-inverse px-3 py-2 font-mono text-[11px] leading-relaxed whitespace-pre-wrap text-on-inverse">
          {execution.logs
            .map((log) => `${log.level.padEnd(5)} ${log.task_id ? `[${log.task_id}] ` : ''}${log.message}`)
            .join('\n')}
        </pre>
      )}
    </div>
  )
}
