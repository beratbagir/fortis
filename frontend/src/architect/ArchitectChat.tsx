import { useEffect, useRef, useState } from 'react'
import { api, sendMessage, type Message, type MessageExtras, type TraceStep } from '../api'
import { LiveTrace } from '../chat/LiveTrace'
import { QuestionCard } from '../chat/QuestionCard'
import { MessageBubble } from '../components/MessageBubble'
import { ArrowUpIcon } from '../ui/icons'

const upsertStep = (steps: TraceStep[], step: TraceStep) => (steps.some((s) => s.id === step.id) ? steps.map((s) => (s.id === step.id ? step : s)) : [...steps, step])

interface Props {
  conversationId: number
  /** Claude bir tur bitirince (taslak kaydedilmiş olabilir) agent yeniden yüklenir. */
  onChanged: () => void
  /** Sohbet boşsa açılınca kendiliğinden gönderilecek ilk mesaj. */
  firstMessage?: string | null
  label?: string
  placeholder?: string
  emptyText?: string
  /** Sohbet boşken gösterilen örnek sorular (tıklanınca gönderilir). */
  suggestions?: string[]
}

/**
 * Architect sohbeti (solda): kullanıcı ne istediğini anlatır, Claude `propose_agent` ile taslağı kaydeder. Oluştur
 * ekranından gelen ilk mesaj (veya sohbetten agent'a özeti) sayfa açılınca kendiliğinden gönderilir.
 */
export function ArchitectChat({
  conversationId,
  onChanged,
  firstMessage = null,
  label = 'Architect sohbeti',
  placeholder = 'Bir değişiklik tarif et…',
  emptyText = "Agent'ın ne yapmasını istediğini anlat. Architect adımları, bağlanacağı sistemleri ve onayları hazırlar; sağda planı görürsün.",
  suggestions = [],
}: Props) {
  const [messages, setMessages] = useState<Message[] | null>(null)
  const [streaming, setStreaming] = useState<{ text: string; steps: TraceStep[] } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [text, setText] = useState('')
  const sending = useRef(false)
  const bottom = useRef<HTMLDivElement>(null)

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [messages, streaming])

  const send = async (content: string, extras: MessageExtras = {}) => {
    if (sending.current || (content.trim() === '' && !extras.answer)) return
    sending.current = true
    setError(null)
    const pending: Message = { id: -Date.now(), conversation_id: conversationId, role: 'user', text: content, stop_reason: null, flow_cards: [], created_at: new Date().toISOString() }
    setMessages((list) => [...(list ?? []).map((m) => (m.status === 'awaiting_input' ? { ...m, status: null, ask: null } : m)), ...(extras.answer ? [] : [pending])])
    setStreaming({ text: '', steps: [] })

    try {
      await sendMessage(
        conversationId,
        content,
        (event) => {
          if (event.event === 'user_message') setMessages((list) => [...(list ?? []).filter((m) => m.id !== pending.id && m.id !== event.data.id), event.data])
          if (event.event === 'delta') setStreaming((s) => ({ text: (s?.text ?? '') + event.data.text, steps: s?.steps ?? [] }))
          if (event.event === 'step') setStreaming((s) => ({ text: s?.text ?? '', steps: upsertStep(s?.steps ?? [], event.data) }))
          if (event.event === 'assistant_message') {
            setMessages((list) => [...(list ?? []).filter((m) => m.id !== event.data.id), event.data])
            setStreaming((s) => ({ text: '', steps: s?.steps ?? [] }))
            onChanged()
          }
          if (event.event === 'error') setError(event.data.message)
        },
        undefined,
        extras,
      )
    } catch (e) {
      setError((e as Error).message)
      setMessages((list) => (list ?? []).filter((m) => m.id !== pending.id))
    } finally {
      setStreaming(null)
      sending.current = false
      onChanged()
    }
  }

  // Sohbet yüklenir; boşsa Oluştur ekranından gelen ilk mesaj gönderilir.
  useEffect(() => {
    let cancelled = false
    api
      .getConversation(conversationId)
      .then((loaded) => {
        if (cancelled) return
        const list = loaded.messages ?? []
        setMessages(list)
        if (firstMessage && list.length === 0) send(firstMessage)
      })
      .catch((e) => !cancelled && setError((e as Error).message))
    return () => {
      cancelled = true
    }
  }, [conversationId]) // eslint-disable-line react-hooks/exhaustive-deps

  const busy = streaming !== null
  const list = (messages ?? []).filter((m) => m.text !== '' || m.answer)
  const last = [...(messages ?? [])].reverse().find((m) => m.role === 'assistant')
  const ask = !busy && last?.status === 'awaiting_input' ? last.ask : null

  const submit = () => {
    const content = text
    if (content.trim() === '' || busy) return
    setText('')
    send(content)
  }

  return (
    <section aria-label={label} className="flex min-h-0 flex-1 flex-col">
      <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-3 py-4" aria-live="polite">
        {messages !== null && list.length === 0 && !busy && (
          <div className="flex flex-col gap-2">
            <p className="m-0 rounded-xl bg-canvas px-3 py-2.5 text-[13px] text-muted">{emptyText}</p>
            {suggestions.map((s) => (
              <button key={s} type="button" onClick={() => send(s)} className="rounded-lg border border-line px-3 py-2 text-left text-[13px] text-ink-2 hover:bg-raised">
                {s}
              </button>
            ))}
          </div>
        )}
        {list.map((message) => (
          <div key={message.id} className="flex flex-col gap-2">
            {message.role === 'assistant' && message.answer && message.answer.trace.length > 0 && <LiveTrace steps={message.answer.trace} running={false} durationMs={message.answer.duration_ms} />}
            {message.text !== '' && <MessageBubble role={message.role} text={message.text} />}
          </div>
        ))}
        {streaming && <LiveTrace steps={streaming.steps} running />}
        {streaming && streaming.text !== '' && <MessageBubble role="assistant" text={streaming.text} streaming />}
        {ask && <QuestionCard key={last!.id} questions={ask.questions} disabled={busy} onSubmit={(answer) => send('', { answer })} />}
        {error && (
          <p role="alert" className="m-0 rounded-xl bg-bad-soft px-3 py-2 text-[13px] text-bad">
            {error}
          </p>
        )}
        <div ref={bottom} />
      </div>
      <form
        className="m-3 mt-0 flex items-center gap-2 rounded-xl border border-line bg-surface px-2.5 py-1.5"
        onSubmit={(e) => {
          e.preventDefault()
          submit()
        }}
      >
        <label className="flex flex-1">
          <span className="sr-only">{placeholder}</span>
          <input value={text} onChange={(e) => setText(e.target.value)} placeholder={placeholder} className="h-8 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
        </label>
        <button type="submit" aria-label="Gönder" disabled={busy} className="flex size-7 items-center justify-center rounded-md bg-brand text-on-brand disabled:opacity-50">
          <ArrowUpIcon />
        </button>
      </form>
    </section>
  )
}
