import { t } from '../i18n'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { api, isProposalCard, sendMessage, type Message, type MessageExtras, type ProposalCard, type ToolName } from '../api'
import { MessageBubble, ThinkingIndicator } from '../components/MessageBubble'
import { toolLabel } from '../toolLabels'
import { money, tokens } from '../ui/format'
import { CloseIcon, SendIcon } from '../ui/icons'
import { MicButton } from '../voice/MicButton'
import { useVoiceDraft } from '../voice/useVoiceDraft'
import { VoiceHint } from '../voice/VoiceHint'
import { editorApi } from './api'
import { diffGraphs, indexGraph } from './diff'

interface Props {
  conversationId: number
  /** Önizlenen öneri (tuvalde fark olarak). */
  previewId: number | null
  onPreview: (proposalId: number | null) => void
  onApply: (proposalId: number) => Promise<void>
  onReject: (proposalId: number) => Promise<void>
  /** "AI'a tarif et" gibi dışarıdan gelen mesaj. */
  outbox: { id: number; text: string } | null
  /** Panel görünür mü. Panel kapalıyken de bağlı kalır (bas-konuş her sekmede çalışsın). */
  active: boolean
  /** Sesli komut başlayınca paneli açar. */
  onActivate: () => void
  /** Tuvalde seçili blok; mesajla birlikte Claude'a gider ("bunu 180 dakika yap"). */
  selection: { id: string; title: string } | null
}

/** Sağ paneldeki "AI ile düzenle": akış bağlamında sohbet. Öneriler kaydedilmez; tuvalde fark olarak görünür. */
export function ChatPanel({ conversationId, previewId, onPreview, onApply, onReject, outbox, active, onActivate, selection }: Props) {
  const queryClient = useQueryClient()
  const conversation = useQuery({ queryKey: ['conversation', conversationId], queryFn: () => api.getConversation(conversationId) })
  const [pending, setPending] = useState<Message | null>(null)
  const [stream, setStream] = useState<{ text: string; tool: ToolName | null } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [text, setText] = useState('')
  const bottom = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const sending = useRef(false)
  // ✕ ile kaldırılan seçim; başka blok seçilince çip yeniden görünür.
  const [dismissedId, setDismissedId] = useState<string | null>(null)
  const chip = selection && selection.id !== dismissedId ? selection : null

  const messages = (conversation.data?.messages ?? []).filter((m) => m.text !== '' || m.flow_cards.length > 0)
  const usage = conversation.data?.usage

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: 'end' })
  }, [messages.length, stream?.text, pending])

  // En son bekleyen öneri tuvalde otomatik önizlenir.
  const latestPending = [...messages].reverse().flatMap((m) => m.flow_cards.filter(isProposalCard)).find((c) => c.status === 'pending')
  useEffect(() => {
    if (active && latestPending && latestPending.proposal_id !== previewId) onPreview(latestPending.proposal_id)
  }, [latestPending?.proposal_id, active]) // eslint-disable-line react-hooks/exhaustive-deps

  const send = async (content: string, extras: MessageExtras = {}) => {
    if (sending.current || content.trim() === '') return
    sending.current = true
    setError(null)
    setPending({ id: -1, conversation_id: conversationId, role: 'user', text: content, stop_reason: null, flow_cards: [], created_at: new Date().toISOString() })
    setStream({ text: '', tool: null })

    try {
      await sendMessage(conversationId, content, (event) => {
        if (event.event === 'delta') setStream((s) => ({ text: (s?.text ?? '') + event.data.text, tool: null }))
        if (event.event === 'tool') setStream((s) => ({ text: s?.text ?? '', tool: event.data.status === 'running' ? event.data.name : null }))
        if (event.event === 'assistant_message' || event.event === 'user_message') {
          queryClient.setQueryData(['conversation', conversationId], (old: typeof conversation.data) =>
            old ? { ...old, messages: [...(old.messages ?? []).filter((m) => m.id !== event.data.id), event.data] } : old,
          )
          if (event.event === 'user_message') setPending(null)
          if (event.event === 'assistant_message') setStream({ text: '', tool: null })
        }
        if (event.event === 'error') setError(event.data.message)
      }, undefined, extras)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setPending(null)
      setStream(null)
      sending.current = false
      await conversation.refetch()
    }
  }

  const lastOutbox = useRef<number | null>(null)
  useEffect(() => {
    if (outbox && outbox.id !== lastOutbox.current) {
      lastOutbox.current = outbox.id
      send(outbox.text)
    }
  }, [outbox]) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = () => {
    const content = text
    if (content.trim() === '' || stream !== null) return
    const meta = voice.consume(content)
    setText('')
    send(content, { ...(chip ? { context: { selected_task_id: chip.id } } : {}), ...(meta ? { voice: meta } : {}) })
  }

  const voice = useVoiceDraft({ text, setText, input, submit })

  return (
    <section aria-label={t('editor.chat.title')} className={`min-h-0 flex-1 flex-col gap-3.5 px-4 pt-2 pb-4 ${active ? 'flex' : 'hidden'}`}>
      <div className="flex min-h-0 flex-1 flex-col gap-3.5 overflow-y-auto" aria-live="polite">
        {messages.length === 0 && !pending && (
          <p className="m-0 rounded-xl bg-canvas px-3 py-2.5 text-[13px] text-muted">
            {t('editor.chat.empty')}
          </p>
        )}
        {[...messages, ...(pending ? [pending] : [])].map((message) => (
          <div key={message.id} className="flex flex-col gap-2.5">
            {message.text !== '' && <MessageBubble role={message.role} text={message.text} />}
            {message.flow_cards.filter(isProposalCard).map((card) => (
              <ProposalView
                key={card.proposal_id}
                card={card}
                previewing={previewId === card.proposal_id}
                onPreview={() => onPreview(previewId === card.proposal_id ? null : card.proposal_id)}
                onApply={async () => {
                  await onApply(card.proposal_id)
                  conversation.refetch()
                }}
                onReject={async () => {
                  await onReject(card.proposal_id)
                  conversation.refetch()
                }}
              />
            ))}
          </div>
        ))}
        {stream && stream.text !== '' && <MessageBubble role="assistant" text={stream.text} streaming />}
        {stream && stream.text === '' && <ThinkingIndicator label={toolLabel(stream.tool)} />}
        {error && (
          <p role="alert" className="m-0 rounded-xl border border-bad/30 bg-bad-soft px-3 py-2 text-[13px] text-bad">
            {error}
          </p>
        )}
        <div ref={bottom} />
      </div>

      <form
        className="flex flex-col gap-2 rounded-2xl border border-line bg-surface p-2.5 shadow-[0_4px_14px_var(--shadow)]"
        onSubmit={(e) => {
          e.preventDefault()
          submit()
        }}
      >
        {chip && (
          <span className="flex max-w-full items-center gap-1 self-start rounded-lg bg-brand-soft py-1 pr-1 pl-2 text-xs font-semibold text-brand-fg">
            <span className="truncate">{t('editor.chat.selected', { title: chip.title })}</span>
            <button type="button" aria-label={t('editor.chat.remove_selected')} onClick={() => setDismissedId(chip.id)} className="flex size-5 items-center justify-center rounded hover:bg-surface/60">
              <CloseIcon width={12} height={12} />
            </button>
          </span>
        )}
        <VoiceHint fromVoice={voice.fromVoice} countdown={voice.countdown} />
        <label className="flex">
          <span className="sr-only">{t('editor.chat.message')}</span>
          <input ref={input} value={text} onChange={(e) => setText(e.target.value)} placeholder={t('editor.chat.placeholder')} className="h-9 flex-1 border-0 px-1.5 text-sm outline-none" />
        </label>
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs font-semibold text-faint">
            {t('editor.chat.usage', { tokens: tokens(usage?.tokens ?? 0), cost: money(usage?.cost_usd ?? 0) })}
          </span>
          <MicButton onText={voice.receive} pushToTalk onStart={onActivate} className="ml-auto" />
          <button type="submit" aria-label={t('editor.chat.send')} disabled={stream !== null} className="flex size-[34px] items-center justify-center rounded-[10px] bg-brand text-on-brand disabled:opacity-50">
            <SendIcon />
          </button>
        </div>
      </form>
    </section>
  )
}

const STATUS_TEXT = { applied: 'editor.proposal.applied', rejected: 'editor.proposal.rejected' } as const

function ProposalView({ card, previewing, onPreview, onApply, onReject }: { card: ProposalCard; previewing: boolean; onPreview: () => void; onApply: () => Promise<void>; onReject: () => Promise<void> }) {
  const proposal = useQuery({ queryKey: ['proposal', card.proposal_id], queryFn: () => editorApi.proposal(card.proposal_id) })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const changes = proposal.data?.base_graph
    ? (() => {
        const marks = diffGraphs(proposal.data.base_graph, proposal.data.graph)
        const before = indexGraph(proposal.data.base_graph)
        const after = indexGraph(proposal.data.graph)
        return [...marks].map(([id, mark]) => ({ id, mark, node: after.get(id) ?? before.get(id) }))
      })()
    : []

  const run = async (action: () => Promise<void>) => {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const label = { added: [t('editor.proposal.added'), 'bg-ok-soft', 'text-ok'], changed: [t('editor.proposal.changed'), 'bg-warn-soft', 'text-warn'], removed: [t('editor.proposal.removed'), 'bg-bad-soft', 'text-bad'] } as const

  return (
    <div className="flex gap-2.5">
      <div className="flex size-[30px] shrink-0 items-center justify-center rounded-[10px] bg-brand-soft text-xs font-extrabold text-brand-fg" aria-hidden>
        AI
      </div>
      <div className="flex min-w-0 flex-1 flex-col gap-2.5 text-sm">
        <span className="font-semibold">{card.title}</span>
        {changes.length > 0 && (
          <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
            {changes.map(({ id, mark, node }) => (
              <li key={id} className={`flex items-center gap-2 rounded-[10px] px-2.5 py-2 text-[13px] font-semibold ${label[mark][1]}`}>
                <span className={`font-extrabold ${label[mark][2]}`}>{label[mark][0]}</span>
                <span className="truncate">{node?.title ?? id}</span>
                <span className="truncate text-xs font-medium text-muted">{node?.label}</span>
              </li>
            ))}
          </ul>
        )}
        {proposal.data && proposal.data.missing_secrets.length > 0 && (
          <span className="text-xs text-warn">{t('editor.proposal.missing_secrets', { names: proposal.data.missing_secrets.join(', ') })}</span>
        )}
        {card.status === 'pending' ? (
          <div className="flex flex-wrap gap-2">
            <button type="button" disabled={busy} onClick={() => run(onApply)} className="h-[38px] rounded-[10px] bg-ink px-4 text-[13px] font-bold text-bg disabled:opacity-60">
              {t('editor.proposal.apply')}
            </button>
            <button type="button" disabled={busy} onClick={() => run(onReject)} className="h-[38px] rounded-[10px] border border-line bg-surface px-4 text-[13px] font-bold">
              {t('editor.proposal.reject')}
            </button>
            <button type="button" aria-pressed={previewing} onClick={onPreview} className="h-[38px] rounded-[10px] px-2 text-[13px] font-bold text-brand-fg underline-offset-2 hover:underline">
              {previewing ? t('editor.proposal.hide_diff') : t('editor.proposal.show_diff')}
            </button>
          </div>
        ) : (
          <span className="text-xs font-bold text-muted">{card.status ? t(STATUS_TEXT[card.status]) : ''}</span>
        )}
        {error && <span role="alert" className="text-xs text-bad">{error}</span>}
      </div>
    </div>
  )
}
