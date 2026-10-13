import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, type Conversation } from '../api'
import { href } from '../router'
import { CHAT_GROUPS, groupOf } from '../chat/chatUtils'
import { SearchIcon } from '../ui/icons'

/** Bütün sohbetler: arama, tarihe göre gruplar, yeniden adlandır ve sil. */
export function ChatsPage() {
  const queryClient = useQueryClient()
  const conversations = useQuery({ queryKey: ['conversations', 'all'], queryFn: api.listConversations })
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<number | null>(null)
  const [title, setTitle] = useState('')

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['conversations'] })
  const term = search.trim().toLocaleLowerCase('tr')
  const list = (conversations.data ?? []).filter((c) => term === '' || (c.title ?? 'Yeni sohbet').toLocaleLowerCase('tr').includes(term))

  const rename = async (c: Conversation) => {
    const value = title.trim()
    setEditing(null)
    if (value === '' || value === c.title) return
    await api.renameConversation(c.id, value)
    refresh()
  }

  const remove = async (c: Conversation) => {
    if (!window.confirm(`"${c.title ?? 'Yeni sohbet'}" silinsin mi?`)) return
    await api.deleteConversation(c.id)
    refresh()
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-3xl flex-col gap-5 px-4 py-8">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 flex-1 font-serif text-[30px] font-normal tracking-tight">Sohbetler</h1>
          <a href={href({ name: 'chat', conversationId: null })} className="flex h-9 items-center rounded-lg bg-brand px-3.5 text-sm font-medium text-on-brand no-underline hover:bg-brand-hover">
            Yeni sohbet
          </a>
        </div>
        <label className="flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-muted">
          <SearchIcon />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Sohbetlerde ara" aria-label="Sohbetlerde ara" className="flex-1 bg-transparent text-sm text-ink outline-none" />
        </label>

        {conversations.isSuccess && list.length === 0 && <p className="m-0 text-sm text-muted">{term ? 'Eşleşen sohbet yok.' : 'Henüz sohbet yok.'}</p>}

        {CHAT_GROUPS.map((group) => {
          const items = list.filter((c) => groupOf(c.updated_at) === group)
          if (items.length === 0) return null
          return (
            <section key={group} aria-label={group} className="flex flex-col gap-1">
              <h2 className="m-0 mb-1 text-xs font-medium text-muted">{group}</h2>
              <ul className="m-0 flex list-none flex-col p-0">
                {items.map((c) => (
                  <li key={c.id} className="group flex items-center gap-2 rounded-lg px-2 hover:bg-raised">
                    {editing === c.id ? (
                      <form
                        className="flex-1 py-1"
                        onSubmit={(e) => {
                          e.preventDefault()
                          rename(c)
                        }}
                      >
                        <input autoFocus aria-label="Yeni başlık" value={title} onChange={(e) => setTitle(e.target.value)} onBlur={() => rename(c)} className="h-8 w-full rounded-md border border-line-strong bg-surface px-2 text-sm" />
                      </form>
                    ) : (
                      <a href={href({ name: 'chat', conversationId: c.id })} className="flex-1 truncate py-2 text-sm text-ink no-underline">
                        {c.title ?? 'Yeni sohbet'}
                      </a>
                    )}
                    <span className="hidden text-xs text-faint sm:inline">{new Date(c.updated_at).toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })}</span>
                    <button
                      type="button"
                      aria-label={`"${c.title ?? 'Yeni sohbet'}" sohbetini yeniden adlandır`}
                      onClick={() => {
                        setTitle(c.title ?? '')
                        setEditing(c.id)
                      }}
                      className="rounded-md px-2 py-1 text-xs text-muted opacity-0 group-focus-within:opacity-100 group-hover:opacity-100 hover:text-ink"
                    >
                      Yeniden adlandır
                    </button>
                    <button type="button" aria-label={`"${c.title ?? 'Yeni sohbet'}" sohbetini sil`} onClick={() => remove(c)} className="rounded-md px-2 py-1 text-xs text-muted opacity-0 group-focus-within:opacity-100 group-hover:opacity-100 hover:text-bad">
                      Sil
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          )
        })}
      </div>
    </main>
  )
}
