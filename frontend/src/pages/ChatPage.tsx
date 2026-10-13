import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api, architectPrompt, buildApi, isArtifactCard, isFlowCard, isPageCard, isReportCard, sendMessage, type ArtifactCard, type Conversation, type FlowCard, type Message, type MessageExtras, type TraceStep } from '../api'
import { liveText } from '../chat/answerText'
import { AnswerView } from '../chat/AnswerView'
import { HomeView } from '../chat/HomeView'
import { LiveTrace } from '../chat/LiveTrace'
import { ModelPicker, SourcePicker } from '../chat/Pickers'
import { QuestionCard } from '../chat/QuestionCard'
import { ArtifactCardView, ArtifactPanel } from '../components/ArtifactPanel'
import { Composer } from '../components/Composer'
import { FlowCardView } from '../components/FlowCardView'
import { PageCardView, PagePanel } from '../components/PageCard'
import { MessageBubble } from '../components/MessageBubble'
import { ReportCardView } from '../components/ReportCardView'
import { useAuth } from '../auth/useAuth'
import { navigate } from '../router'
import { ChevronIcon } from '../ui/icons'

interface Streaming {
  text: string
  /** Canlı iz adımları (step olayları). */
  steps: TraceStep[]
}

const IDLE_STREAM: Streaming = { text: '', steps: [] }

/** Sunucu kaydedene kadar ekranda gösterilen geçici kullanıcı mesajı (negatif id). */
function pendingMessage(conversationId: number, text: string): Message {
  return { id: -Date.now(), conversation_id: conversationId, role: 'user', text, stop_reason: null, flow_cards: [], created_at: new Date().toISOString() }
}

/** Sohbet ayarları; sohbet oluşmadan (Home) seçilenler ilk mesajla birlikte kaydedilir. */
interface Settings {
  model: string
  disabled: string[]
}

const DEFAULT_SETTINGS: Settings = { model: 'auto', disabled: [] }

const upsertStep = (steps: TraceStep[], step: TraceStep) => (steps.some((s) => s.id === step.id) ? steps.map((s) => (s.id === step.id ? step : s)) : [...steps, step])

export function ChatPage({ conversationId }: { conversationId: number | null }) {
  const queryClient = useQueryClient()
  const [conversation, setConversation] = useState<Conversation | null>(null)
  const [messages, setMessages] = useState<Message[]>([])
  const [settings, setSettings] = useState<Settings>(DEFAULT_SETTINGS)
  const [streaming, setStreaming] = useState<Streaming | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [draft, setDraft] = useState<{ id: number; text: string }>()
  const bottom = useRef<HTMLDivElement>(null)
  const abort = useRef<AbortController | null>(null)
  const sending = useRef(false)
  const activeId = conversation?.id ?? null

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [messages, streaming])

  const openConversation = useCallback(async (id: number) => {
    abort.current?.abort()
    setStreaming(null)
    setError(null)
    try {
      const loaded = await api.getConversation(id)
      setConversation(loaded)
      setMessages(loaded.messages ?? [])
      setSettings({ model: loaded.auto === false && loaded.model ? loaded.model : 'auto', disabled: loaded.disabled_sources ?? [] })
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // Adres değişince (#/chat/12) sohbet açılır; #/chat yeni sohbettir (Home).
  useEffect(() => {
    if (conversationId !== null && conversationId !== activeId) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- adres çubuğu dış kaynak
      openConversation(conversationId)
    }
    if (conversationId === null && activeId !== null && !sending.current) {
      abort.current?.abort()
      setConversation(null)
      setMessages([])
      setStreaming(null)
      setError(null)
      setSettings(DEFAULT_SETTINGS)
    }
  }, [conversationId]) // eslint-disable-line react-hooks/exhaustive-deps

  const saveSettings = async (next: Settings) => {
    setSettings(next)
    if (activeId === null) return
    try {
      const updated = await api.updateConversation(activeId, { model: next.model, disabled_sources: next.disabled })
      setConversation((c) => (c ? { ...c, ...updated, messages: undefined } : c))
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const send = async (text: string, extras: MessageExtras = {}) => {
    // Yanıt sürerken (karttaki butonlar dahil) yeni mesaj gönderilmez.
    if (sending.current) return
    sending.current = true
    setError(null)

    let id = activeId
    if (id === null) {
      try {
        const created = await api.createConversation()
        const changed = settings.model !== 'auto' || settings.disabled.length > 0
        const ready = changed ? await api.updateConversation(created.id, { model: settings.model, disabled_sources: settings.disabled }) : created
        id = created.id
        setConversation(ready)
        window.history.replaceState(null, '', `#/chat/${created.id}`)
      } catch (e) {
        setError((e as Error).message)
        sending.current = false
        return
      }
    }

    const pending = pendingMessage(id, text)
    if (extras.answer || messages.some((m) => m.status === 'awaiting_input')) {
      // Soru cevaplandı (kartla veya yazarak): izi devam eden cevapta gösterilir.
      setMessages((list) => list.map((m) => (m.status === 'awaiting_input' ? { ...m, status: null, ask: null, ask_answered: true } : m)))
    }
    if (!extras.answer) setMessages((list) => [...list, pending])
    setStreaming(IDLE_STREAM)

    const controller = new AbortController()
    abort.current = controller

    try {
      await sendMessage(
        id,
        text,
        (event) => {
          switch (event.event) {
            case 'user_message':
              setMessages((list) => (list.some((m) => m.id === pending.id) ? list.map((m) => (m.id === pending.id ? event.data : m)) : [...list, event.data]))
              break
            case 'delta':
              setStreaming((s) => ({ ...(s ?? IDLE_STREAM), text: (s?.text ?? '') + event.data.text }))
              break
            case 'step':
              setStreaming((s) => ({ ...(s ?? IDLE_STREAM), steps: upsertStep(s?.steps ?? [], event.data) }))
              break
            case 'assistant_message':
              // Araç döngüsünde her tur ayrı kaydedilir; akan metin kaydedilen mesaja devredilir. İz, cevap bitene kadar sürer.
              setMessages((list) => (list.some((m) => m.id === event.data.id) ? list.map((m) => (m.id === event.data.id ? event.data : m)) : [...list, event.data]))
              setStreaming((s) => ({ ...(s ?? IDLE_STREAM), text: '' }))
              break
            case 'error':
              setError(event.data.message)
              break
            case 'done':
              setConversation((c) => (c ? { ...c, title: event.data.conversation.title } : c))
              queryClient.invalidateQueries({ queryKey: ['conversations'] })
              break
          }
        },
        controller.signal,
        extras,
      )
    } catch (e) {
      if ((e as Error).name !== 'AbortError') setError((e as Error).message)
      // Sunucu mesajı kaydetmediyse (ör. 409) geçici mesaj ekrandan kalkar; kaydettiyse zaten değiştirilmiştir.
      setMessages((list) => list.filter((m) => m.id !== pending.id))
    } finally {
      setStreaming(null)
      sending.current = false
    }
  }

  const updateCard = (updated: FlowCard) =>
    setMessages((list) => list.map((m) => ({ ...m, flow_cards: m.flow_cards.map((c) => (isFlowCard(c) && c.flow_version_id === updated.flow_version_id ? updated : c)) })))

  const editFlow = (card: FlowCard) => setDraft({ id: Date.now(), text: `"${card.title}" akışında şunu değiştirmek istiyorum: ` })

  const [artifact, setArtifact] = useState<{ id: string; version: number | null; page?: boolean } | null>(null)
  // Yanıt akarken yeni üretilen artifact kendiliğinden açılır; eski sohbet açılırken açılmaz.
  const latestArtifact = messages.flatMap((m) => m.flow_cards.filter(isArtifactCard)).at(-1)
  const [shownArtifact, setShownArtifact] = useState<string | null>(null)
  const latestKey = latestArtifact ? `${latestArtifact.artifact_id}:${latestArtifact.version}` : null
  if (streaming !== null && latestKey !== null && latestKey !== shownArtifact) {
    setShownArtifact(latestKey)
    // Sayfa: üretilen sürümle açılır (panel sürüm numarasıyla yükler).
    setArtifact({ id: latestArtifact!.artifact_id, version: latestArtifact!.kind === 'page' ? latestArtifact!.version : null, page: latestArtifact!.kind === 'page' })
  }

  const busy = streaming !== null
  const lastAssistant = [...messages].reverse().find((m) => m.role === 'assistant')
  const ask = !busy && lastAssistant?.status === 'awaiting_input' ? lastAssistant.ask : null
  const lastQuestion = [...messages].reverse().find((m) => m.role === 'user' && m.text !== '' && !m.text.startsWith('Cevaplarım:'))?.text

  const { user } = useAuth()
  const canBuild = user?.abilities.build ?? false

  /** Sohbetten agent'a: sohbetin soruları ve sorguları Architect'e ilk mesaj olarak gider. */
  const toAgent = async (actionTitle?: string) => {
    if (activeId === null) return
    try {
      const agent = await buildApi.fromConversation(activeId, actionTitle)
      if (agent.seed) architectPrompt.set(agent.id, agent.seed)
      navigate({ name: 'architect', agentId: agent.id })
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const toolbar = {
    leading: <SourcePicker disabled={settings.disabled} onChange={(disabled) => saveSettings({ ...settings, disabled })} />,
    trailing: <ModelPicker value={settings.model} onChange={(model) => saveSettings({ ...settings, model })} />,
  }

  if (activeId === null && conversationId === null) {
    return <HomeView onSend={send} busy={busy} error={error} composer={<Composer disabled={busy} onSend={send} draft={draft} variant="home" {...toolbar} />} />
  }

  const visible = messages.filter((m) => m.text !== '' || m.flow_cards.length > 0 || m.answer)
  const lastAnswerId = [...messages].reverse().find((m) => m.answer && m.status !== 'awaiting_input')?.id

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col md:flex-row">
      <main className="flex min-h-0 min-w-0 flex-1 flex-col">
        <TopBar conversation={conversation} onRenamed={(title) => setConversation((c) => (c ? { ...c, title } : c))} onSaveAsAgent={canBuild && !busy ? () => toAgent() : undefined} />
        {conversation?.context_flow && (
          <div className="border-b border-line bg-canvas px-4 py-2 text-center text-sm text-ink-2">
            Bu sohbet <span className="font-medium text-ink">{conversation.context_flow.title}</span> akışını değiştirmek için açıldı{' '}
            <span className="font-mono text-xs">
              ({conversation.context_flow.namespace}.{conversation.context_flow.flow_id})
            </span>
            . Ne değiştirmek istediğinizi yazın.
          </div>
        )}
        <div className="flex-1 overflow-y-auto">
          <div className="mx-auto flex max-w-3xl flex-col gap-5 px-4 py-6">
            {visible.map((message) => (
              <div key={message.id} className="flex flex-col gap-4">
                {message.role === 'user' && message.text !== '' && <MessageBubble role="user" text={message.text} />}
                {message.role === 'assistant' && message.answer && !message.ask_answered && (
                  <AnswerView message={message} onSend={send} onAutomate={canBuild ? (title) => toAgent(title) : undefined} onRegenerate={lastQuestion && message.id === lastAnswerId ? () => send(lastQuestion) : undefined} busy={busy} />
                )}
                {message.role === 'assistant' && (!message.answer || message.ask_answered) && message.text !== '' && <MessageBubble role="assistant" text={liveText(message.text)} />}
                {message.flow_cards.filter(isPageCard).map((card) => (
                  <PageCardView
                    key={`${card.artifact_id}:${card.version}`}
                    card={card}
                    open={artifact?.id === card.artifact_id && artifact.version === card.version}
                    onOpen={() => setArtifact({ id: card.artifact_id, version: card.version, page: true })}
                  />
                ))}
                {message.flow_cards.filter((c): c is ArtifactCard => isArtifactCard(c) && !isPageCard(c)).map((card) => (
                  <ArtifactCardView key={`${card.artifact_id}:${card.version}`} card={card} open={artifact?.id === card.artifact_id} onOpen={() => setArtifact({ id: card.artifact_id, version: card.version })} />
                ))}
                {message.flow_cards.filter(isReportCard).map((card) => (
                  <ReportCardView key={card.id} card={card} conversationId={activeId} />
                ))}
                {message.flow_cards.filter(isFlowCard).map((card, i) => (
                  <FlowCardView key={i} card={card} conversationId={activeId} onEdit={editFlow} onUpdated={updateCard} onAsk={send} />
                ))}
              </div>
            ))}

            {streaming && <LiveTrace steps={streaming.steps} running />}
            {streaming && streaming.text !== '' && <MessageBubble role="assistant" text={liveText(streaming.text)} streaming />}

            {error && <div className="rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{error}</div>}

            <div ref={bottom} />
          </div>
        </div>

        {ask && (
          <div className="mx-auto w-full max-w-3xl px-4">
            <QuestionCard key={lastAssistant!.id} questions={ask.questions} disabled={busy} onSubmit={(answer) => send('', { answer })} />
          </div>
        )}
        <Composer disabled={busy} onSend={send} draft={draft} placeholder="Devam eden bir soru sor…" {...toolbar} />
      </main>
      {artifact && (
        artifact.page ? (
          <PagePanel key={`${artifact.id}:${artifact.version}`} id={artifact.id} version={artifact.version ?? 1} onSend={send} onRevise={(text) => setDraft({ id: Date.now(), text })} onClose={() => setArtifact(null)} />
        ) : (
          <ArtifactPanel key={artifact.id} id={artifact.id} version={artifact.version} onVersion={(version) => setArtifact({ id: artifact.id, version })} onClose={() => setArtifact(null)} />
        )
      )}
    </div>
  )
}

/** Sohbet başlığı ve menüsü (yeniden adlandır, sil), builder/admin için "Agent olarak kaydet". "Paylaş" A7'de. */
function TopBar({ conversation, onRenamed, onSaveAsAgent }: { conversation: Conversation | null; onRenamed: (title: string) => void; onSaveAsAgent?: () => void }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [renaming, setRenaming] = useState(false)
  const [title, setTitle] = useState('')

  if (!conversation) return <div className="h-12 shrink-0 border-b border-line" />

  const rename = async () => {
    const value = title.trim()
    setRenaming(false)
    if (value === '' || value === conversation.title) return
    await api.renameConversation(conversation.id, value)
    onRenamed(value)
    queryClient.invalidateQueries({ queryKey: ['conversations'] })
  }

  const remove = async () => {
    setOpen(false)
    if (!window.confirm('Bu sohbet silinsin mi?')) return
    await api.deleteConversation(conversation.id)
    queryClient.invalidateQueries({ queryKey: ['conversations'] })
    navigate({ name: 'chat', conversationId: null })
  }

  return (
    <header className="flex h-12 shrink-0 items-center gap-2 border-b border-line px-4">
      {renaming ? (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            rename()
          }}
          className="flex-1"
        >
          <input autoFocus aria-label="Sohbet başlığı" value={title} onChange={(e) => setTitle(e.target.value)} onBlur={rename} className="h-8 w-full max-w-md rounded-lg border border-line-strong bg-surface px-2 text-sm" />
        </form>
      ) : (
        <div className="relative min-w-0">
          <button type="button" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((o) => !o)} className="flex max-w-full items-center gap-1.5 rounded-md px-1.5 py-1 text-sm text-ink hover:bg-raised">
            <span className="truncate">{conversation.title ?? 'Yeni sohbet'}</span>
            <ChevronIcon open={open} width={14} height={14} />
          </button>
          {open && (
            <div role="menu" className="absolute top-full left-0 z-20 mt-1 flex w-44 flex-col rounded-lg border border-line bg-surface p-1 text-[13px] shadow-[0_8px_24px_var(--shadow)]">
              <button
                role="menuitem"
                type="button"
                onClick={() => {
                  setOpen(false)
                  setTitle(conversation.title ?? '')
                  setRenaming(true)
                }}
                className="rounded-md px-2.5 py-1.5 text-left hover:bg-raised"
              >
                Yeniden adlandır
              </button>
              <button role="menuitem" type="button" onClick={remove} className="rounded-md px-2.5 py-1.5 text-left text-bad hover:bg-raised">
                Sil
              </button>
            </div>
          )}
        </div>
      )}
      <span className="flex-1" />
      {onSaveAsAgent && (
        <button type="button" onClick={onSaveAsAgent} className="rounded-lg border border-line px-2.5 py-1 text-[13px] text-ink-2 hover:bg-raised">
          Agent olarak kaydet
        </button>
      )}
    </header>
  )
}
