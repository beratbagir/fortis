import { useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { api, type AuditEntry, type Message } from '../api'
import { Dialog } from '../ui/Dialog'
import { CopyIcon, RetryIcon, ThumbDownIcon, ThumbUpIcon } from '../ui/icons'
import { ActionCards, AnswerMarkdown, InfoLine, SourcesDialog, TableCard } from './AnswerParts'
import { CitationContext, queryText } from './chatUtils'
import { extractActions, numberCitations, splitBlocks } from './answerText'
import { LiveTrace } from './LiveTrace'

interface Props {
  message: Message
  /** Önerilen aksiyon veya yeniden üretme: sohbete mesaj gönderir. */
  onSend: (text: string) => void
  /** "Düzenli hale getir": builder/admin için Architect'te agent kurar (yoksa mesaj olarak gönderilir). */
  onAutomate?: (title: string) => void
  onRegenerate?: () => void
  busy: boolean
}

/** Asistan cevabı: daralmış iz, sonuç, tablo kartları, numaralı kaynaklar, aksiyonlar, bilgi satırı, geri bildirim. */
export function AnswerView({ message, onSend, onAutomate, onRegenerate, busy }: Props) {
  const answer = message.answer ?? null
  const waiting = message.status === 'awaiting_input'
  const [sources, setSources] = useState(false)
  const [audit, setAudit] = useState(false)

  const view = useMemo(() => {
    const { text, actions } = extractActions(message.text)
    const { text: cited, numbers } = numberCitations(text, answer?.queries ?? [])
    return { blocks: splitBlocks(cited), actions, numbers }
  }, [message.text, answer])

  const queries = useMemo(() => new Map((answer?.queries ?? []).map((q) => [q.query_id, q])), [answer])

  return (
    <article className="flex flex-col gap-3" aria-label="Fortis'in cevabı">
      {answer && answer.trace.length > 0 && <LiveTrace steps={answer.trace} running={false} durationMs={answer.duration_ms} defaultOpen={waiting} onAudit={answer.queries.length > 0 && !waiting ? () => setAudit(true) : undefined} />}

      <CitationContext.Provider value={{ queries, numbers: view.numbers }}>
        {view.blocks.map((block, i) =>
          block.type === 'table' ? (
            <TableCard key={i} block={block} />
          ) : (
            <div key={i} className={`prose-chat ${i === 0 ? 'answer-lead' : ''}`}>
              <AnswerMarkdown text={block.text} />
            </div>
          ),
        )}
      </CitationContext.Provider>

      <ActionCards actions={view.actions} onSend={onSend} onAutomate={onAutomate} disabled={busy} />
      {answer && !waiting && <InfoLine answer={answer} onSources={() => setSources(true)} />}
      {message.text !== '' && !waiting && <Feedback message={message} onRegenerate={onRegenerate} busy={busy} />}

      {sources && answer && <SourcesDialog queries={answer.queries} numbers={view.numbers} onClose={() => setSources(false)} />}
      {audit && <AuditDialog messageId={message.id} onClose={() => setAudit(false)} />}
    </article>
  )
}

function Feedback({ message, onRegenerate, busy }: { message: Message; onRegenerate?: () => void; busy: boolean }) {
  const [rating, setRating] = useState(message.feedback ?? null)
  const [commenting, setCommenting] = useState(false)
  const [comment, setComment] = useState('')
  const [copied, setCopied] = useState(false)

  const rate = async (value: 'up' | 'down') => {
    const next = rating === value ? null : value
    setRating(next)
    setCommenting(next === 'down')
    await api.feedback(message.id, next).catch(() => setRating(rating))
  }

  const copy = async () => {
    await navigator.clipboard?.writeText(extractActions(message.text).text.replace(/\s?\[\^q:[A-Za-z0-9_]+\]/g, ''))
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  const button = 'rounded-md p-1.5 text-muted hover:bg-raised hover:text-ink aria-pressed:text-brand-fg'

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center gap-0.5">
        <button type="button" aria-label={copied ? 'Kopyalandı' : 'Cevabı kopyala'} onClick={copy} className={button}>
          <CopyIcon />
        </button>
        <button type="button" aria-label="Faydalı" aria-pressed={rating === 'up'} onClick={() => rate('up')} className={button}>
          <ThumbUpIcon />
        </button>
        <button type="button" aria-label="Faydalı değil" aria-pressed={rating === 'down'} onClick={() => rate('down')} className={button}>
          <ThumbDownIcon />
        </button>
        {onRegenerate && (
          <button type="button" aria-label="Yeniden üret" disabled={busy} onClick={onRegenerate} className={button}>
            <RetryIcon />
          </button>
        )}
      </div>
      {commenting && (
        <form
          className="flex max-w-md gap-2"
          onSubmit={async (e) => {
            e.preventDefault()
            await api.feedback(message.id, 'down', comment).catch(() => undefined)
            setCommenting(false)
          }}
        >
          <input
            aria-label="Neyi daha iyi yapabilirdik?"
            placeholder="Neyi daha iyi yapabilirdik? (isteğe bağlı)"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            className="h-8 flex-1 rounded-lg border border-line bg-surface px-2.5 text-[13px] text-ink"
          />
          <button type="submit" className="h-8 rounded-lg border border-line px-3 text-[13px] text-ink-2 hover:bg-raised">
            Gönder
          </button>
        </form>
      )}
    </div>
  )
}

function AuditDialog({ messageId, onClose }: { messageId: number; onClose: () => void }) {
  const query = useQuery({ queryKey: ['audit', messageId], queryFn: () => api.audit(messageId) })
  const entries: AuditEntry[] | null = query.data ?? null
  const error = query.error ? (query.error as Error).message : null

  return (
    <Dialog title="Denetim kaydı" onClose={onClose}>
      {error && <p className="m-0 text-sm text-bad">{error}</p>}
      {!entries && !error && <p className="m-0 text-sm text-muted">Yükleniyor…</p>}
      {entries && entries.length === 0 && <p className="m-0 text-sm text-muted">Bu cevap için kayıt yok.</p>}
      {entries && entries.length > 0 && (
        <ul className="m-0 flex list-none flex-col gap-3 p-0 text-sm">
          {entries.map((e, i) => (
            <li key={i} className="flex flex-col gap-0.5 border-b border-line pb-3 last:border-b-0">
              <span className="font-medium">
                {e.system} · {e.purpose}
              </span>
              <span className="text-xs text-muted">
                {new Date(e.at).toLocaleString('tr-TR')} · {e.actor ?? 'Sistem'} · {e.result === 'allowed' ? 'İzin verildi' : e.result} · {e.rows ?? 0} satır
                {e.masked_fields.length > 0 ? ` · Gizlenen alanlar: ${e.masked_fields.join(', ')}` : ''}
              </span>
              {e.query && <span className="truncate font-mono text-[11px] text-faint">{queryText(e.query)}</span>}
            </li>
          ))}
        </ul>
      )}
      <p className="mt-4 mb-0 text-xs text-muted">Kayıtlar değiştirilemez; dönen veri saklanmaz.</p>
    </Dialog>
  )
}
