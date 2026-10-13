import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { agentsApi, type AgentDetail } from '../api'
import { ArchitectChat } from '../architect/ArchitectChat'
import { stamp } from './format'

/**
 * Sohbet sekmesi: agent'la talimatı ve araçlarıyla doğrudan sohbet. Her sohbet bir çalışmadır (kaynak: sohbet);
 * yazan aksiyonlar yine onaya gider. Pilot/canlı değilse sahibi test olarak konuşur (gönderimler önizlenir).
 */
export function Chat({ agent }: { agent: AgentDetail }) {
  const queryClient = useQueryClient()
  const chats = useQuery({ queryKey: ['agent-chats', agent.id], queryFn: () => agentsApi.chats(agent.id) })
  const [active, setActive] = useState<{ conversationId: number; first: string | null } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const current = active ?? (chats.data?.[0] ? { conversationId: chats.data[0].conversation_id, first: null } : null)

  const start = async (first: string | null = null) => {
    setError(null)
    try {
      const run = await agentsApi.newChat(agent.id)
      setActive({ conversationId: run.conversation_id!, first })
      queryClient.invalidateQueries({ queryKey: ['agent-chats', agent.id] })
    } catch (e) {
      setError((e as Error).message)
    }
  }

  if (!agent.can_chat) {
    return <p className="m-0 rounded-xl border border-dashed border-line px-4 py-8 text-center text-[13px] text-muted">Bu agent pilot veya canlı olunca sohbet edilebilir.</p>
  }

  return (
    <div className="grid min-h-[520px] gap-4 md:grid-cols-[220px_minmax(0,1fr)]">
      <nav aria-label="Sohbetler" className="flex flex-col gap-1">
        <button type="button" onClick={() => start()} className="mb-1 rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg">
          Yeni sohbet
        </button>
        {(chats.data ?? []).map((c) => (
          <button
            key={c.id}
            type="button"
            aria-current={current?.conversationId === c.conversation_id ? 'true' : undefined}
            onClick={() => setActive({ conversationId: c.conversation_id, first: null })}
            className={`flex flex-col rounded-lg px-2.5 py-1.5 text-left ${current?.conversationId === c.conversation_id ? 'bg-raised' : 'hover:bg-raised'}`}
          >
            <span className="truncate text-[13px] text-ink">{c.title}</span>
            <span className="font-mono text-[10px] text-muted">
              {stamp(c.started_at)}
              {c.is_test ? ' · test' : ''}
            </span>
          </button>
        ))}
        {error && (
          <p role="alert" className="m-0 text-xs text-bad">
            {error}
          </p>
        )}
      </nav>
      <section className="flex min-h-0 flex-col rounded-xl border border-line bg-surface">
        {current ? (
          <ArchitectChat
            key={current.conversationId}
            conversationId={current.conversationId}
            firstMessage={current.first}
            onChanged={() => queryClient.invalidateQueries({ queryKey: ['agent-chats', agent.id] })}
            label={`${agent.name} ile sohbet`}
            placeholder={`${agent.name}'e sor…`}
            emptyText={agent.description ?? 'Agent\'a bir şey sor.'}
            suggestions={agent.suggested_prompts}
          />
        ) : (
          <div className="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
            <p className="m-0 text-[13px] text-muted">{agent.description}</p>
            {agent.suggested_prompts.map((p) => (
              <button key={p} type="button" onClick={() => start(p)} className="rounded-lg border border-line px-3 py-2 text-[13px] text-ink-2 hover:bg-raised">
                {p}
              </button>
            ))}
            <button type="button" onClick={() => start()} className="text-xs text-brand-fg">
              Sohbet başlat
            </button>
          </div>
        )}
      </section>
    </div>
  )
}
