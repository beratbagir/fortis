import { useState } from 'react'
import { startExecution, UploadError, uploadFlowVersion, type Execution, type FlowCard } from '../api'
import { ExecutionCard } from './ExecutionCard'
import { initialValues } from '../format'
import { InputFields } from './InputFields'

interface Props {
  card: FlowCard
  conversationId: number | null
  onEdit: (card: FlowCard) => void
  onUpdated: (card: FlowCard) => void
  onAsk: (text: string) => void
}

const dateFormat = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })

export function FlowCardView({ card, conversationId, onEdit, onUpdated, onAsk }: Props) {
  const [showYaml, setShowYaml] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [errors, setErrors] = useState<string[]>([])
  const [showRunForm, setShowRunForm] = useState(false)
  const [runValues, setRunValues] = useState<Record<string, unknown>>({})
  const [starting, setStarting] = useState(false)
  const [copied, setCopied] = useState(false)
  const [started, setStarted] = useState<Execution[]>([])
  const { status, run } = card
  const executions = [...started, ...card.executions.filter((e) => !started.some((s) => s.id === e.id))]

  const startRun = async () => {
    if (run.inputs.length > 0 && !showRunForm) {
      setShowRunForm(true)
      return
    }
    setStarting(true)
    setErrors([])
    try {
      const execution = await startExecution(card.flow_record_id, { ...initialValues(run.inputs), ...runValues }, conversationId)
      setStarted((list) => [execution, ...list])
      setShowRunForm(false)
    } catch (e) {
      setErrors([(e as Error).message])
    } finally {
      setStarting(false)
    }
  }

  const copyWebhook = async () => {
    if (!run.webhook_url) return
    await navigator.clipboard.writeText(run.webhook_url)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  const upload = async () => {
    setUploading(true)
    setErrors([])
    try {
      onUpdated(await uploadFlowVersion(card.flow_version_id))
    } catch (e) {
      if (e instanceof UploadError) {
        setErrors(e.errors)
        if (e.card) onUpdated(e.card)
      } else {
        setErrors([(e as Error).message])
      }
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="flex justify-start">
      <div className="w-full max-w-[85%] overflow-hidden rounded-2xl border border-line-strong bg-surface shadow-sm">
        <div className="border-b border-line bg-canvas px-4 py-3">
          <div className="flex items-center justify-between gap-2">
            <div className="text-xs font-medium tracking-wide text-muted uppercase">
              Akış önerisi · sürüm {card.version}
            </div>
            {status.uploaded && (
              <span className="rounded-full bg-ok-soft px-2 py-0.5 text-xs font-medium text-ok">Yüklendi</span>
            )}
          </div>
          <div className="mt-0.5 text-base font-semibold">{card.title}</div>
          <div className="mt-1 font-mono text-xs text-muted">
            {card.namespace}.{card.flow_id}
          </div>
        </div>

        <div className="space-y-4 px-4 py-3 text-sm">
          <ul className="list-disc space-y-1 pl-5">
            {card.summary.map((line, i) => (
              <li key={i}>{line}</li>
            ))}
          </ul>

          <div>
            <div className="text-xs font-medium text-muted">Tetikleyici</div>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {card.triggers.map((trigger, i) => (
                <span key={i} className="rounded-md bg-raised px-2 py-0.5">
                  {trigger}
                </span>
              ))}
            </div>
          </div>

          {card.warned_steps.length > 0 && (
            <div>
              <div className="text-xs font-medium text-muted">Dikkat edilmesi gereken adımlar</div>
              <ul className="mt-1 space-y-1">
                {card.warned_steps.map((step, i) => (
                  <li key={i} className="rounded-md border border-warn/30 bg-warn-soft px-2.5 py-1.5 text-warn">
                    <span className="font-medium">{step.label}</span>
                    {step.details && <span className="ml-1 font-mono text-xs break-all">{step.details}</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {card.missing_secrets.length > 0 && (
            <div className="rounded-md border border-bad/30 bg-bad-soft px-2.5 py-2 text-bad">
              <div className="font-medium">Tanımlı olmayan bilgiler</div>
              <div className="mt-0.5">
                Bu akışın çalışması için yöneticinin şu bilgileri Kestra'ya eklemesi gerekiyor:{' '}
                <span className="font-mono text-xs">{card.missing_secrets.join(', ')}</span>
              </div>
            </div>
          )}

          {status.uploaded && status.uploaded_at && (
            <div className="rounded-md border border-ok/30 bg-ok-soft px-2.5 py-2 text-ok">
              Kestra'ya yüklendi: {dateFormat.format(new Date(status.uploaded_at))}
              {status.kestra_revision !== null && <> · revision {status.kestra_revision}</>}
            </div>
          )}

          {run.webhook_url && (
            <div className="rounded-md border border-line bg-canvas px-2.5 py-2">
              <div className="text-xs font-medium text-muted">Webhook adresi</div>
              <div className="mt-1 flex items-center gap-2">
                <code className="flex-1 font-mono text-xs break-all">{run.webhook_url}</code>
                <button type="button" onClick={copyWebhook} className="shrink-0 text-xs text-ink-2 hover:text-ink">
                  {copied ? 'Kopyalandı' : 'Kopyala'}
                </button>
              </div>
              <div className="mt-1 text-xs text-muted">Bu adrese POST isteği gelince akış çalışır.</div>
            </div>
          )}

          {showRunForm && (
            <div className="rounded-md border border-line px-2.5 py-2">
              <div className="mb-2 text-xs font-medium text-muted">Çalıştırma girdileri</div>
              <InputFields inputs={run.inputs} values={runValues} onChange={setRunValues} />
            </div>
          )}

          {errors.length > 0 && (
            <div className="rounded-md border border-bad/30 bg-bad-soft px-2.5 py-2 text-bad">
              <div className="font-medium">{status.uploaded ? 'İşlem başarısız' : 'Yüklenemedi'}</div>
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            </div>
          )}

          {showYaml && (
            <pre className="max-h-96 overflow-auto rounded-lg bg-inverse p-3 font-mono text-xs leading-relaxed text-on-inverse">
              {card.yaml}
            </pre>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2 border-t border-line px-4 py-3">
          {!status.uploaded && (
            <button
              type="button"
              onClick={upload}
              disabled={!status.can_upload || uploading}
              title={status.reason ?? undefined}
              className="rounded-lg bg-inverse px-3 py-1.5 text-sm font-medium text-bg hover:bg-ink-2 disabled:cursor-not-allowed disabled:bg-line-strong"
            >
              {uploading ? 'Yükleniyor…' : 'Yükle'}
            </button>
          )}
          {run.can_run && (
            <button
              type="button"
              onClick={startRun}
              disabled={starting}
              className="rounded-lg bg-inverse px-3 py-1.5 text-sm font-medium text-bg hover:bg-ink-2 disabled:bg-line-strong"
            >
              {starting ? 'Başlatılıyor…' : showRunForm ? 'Çalıştır' : 'Şimdi çalıştır'}
            </button>
          )}
          <button
            type="button"
            onClick={() => onEdit(card)}
            className="rounded-lg border border-line-strong px-3 py-1.5 text-sm font-medium hover:bg-canvas"
          >
            Değiştir
          </button>
          <button
            type="button"
            onClick={() => setShowYaml((v) => !v)}
            className="ml-auto text-sm text-ink-2 hover:text-ink"
          >
            {showYaml ? "YAML'ı gizle" : "YAML'ı göster"}
          </button>
          {!status.uploaded && status.reason && <div className="w-full text-xs text-muted">{status.reason}</div>}
          {status.uploaded && !run.can_run && run.reason && <div className="w-full text-xs text-muted">{run.reason}</div>}
        </div>

        {executions.length > 0 && (
          <div className="space-y-2 border-t border-line px-4 py-3">
            <div className="text-xs font-medium text-muted">Çalışmalar</div>
            {executions.map((execution) => (
              <ExecutionCard key={execution.id} execution={execution} onAsk={onAsk} />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
