import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { inboxApi, type InboxItem, type InboxItemDetail, type InboxTab, type SnoozeUntil } from '../api'
import { InboxDetailView } from '../inbox/InboxDetail'
import { INBOX_GROUPS, INBOX_TABS, TYPE_LABEL, TYPE_TONE, inboxGroup, initials, primaryLabel, time } from '../inbox/inboxUtils'
import { t } from '../i18n'
import { href, navigate } from '../router'

const SNOOZE: { key: SnoozeUntil; label: string }[] = [
  { key: '1h', label: '1 saat' },
  { key: '4h', label: '4 saat' },
  { key: 'tomorrow', label: 'Yarın sabah' },
]

/** Inbox: liste (#/inbox) ve öğe detayı (#/inbox/7). Kullanıcı sadece kendi öğelerini görür. */
export function InboxPage({ itemId }: { itemId: number | null }) {
  return itemId ? <InboxItemPage key={itemId} id={itemId} /> : <InboxList />
}

function useRefreshInbox() {
  const queryClient = useQueryClient()
  return () => Promise.all([queryClient.invalidateQueries({ queryKey: ['inbox'] }), queryClient.invalidateQueries({ queryKey: ['home'] })])
}

function InboxList() {
  const [tab, setTab] = useState<InboxTab>('all')
  const [selected, setSelected] = useState<number[]>([])
  const [error, setError] = useState<string | null>(null)
  const refresh = useRefreshInbox()
  const list = useQuery({ queryKey: ['inbox', 'list', tab], queryFn: () => inboxApi.list(tab) })
  const items = list.data?.data ?? []
  const chosen = items.filter((i) => selected.includes(i.id))
  const allApprovals = chosen.length > 0 && chosen.every((i) => i.type === 'approval' && i.actionable)

  const run = async (work: () => Promise<unknown>) => {
    setError(null)
    try {
      await work()
      setSelected([])
    } catch (e) {
      setError(e instanceof Error ? e.message : 'İşlem yapılamadı.')
    } finally {
      await refresh()
    }
  }

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-8">
        <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">{t('nav.inbox')}</h1>

        <div role="tablist" aria-label="Inbox sekmeleri" className="flex gap-1 overflow-x-auto border-b border-line">
          {INBOX_TABS.map((t) => (
            <button
              key={t.key}
              role="tab"
              type="button"
              aria-selected={tab === t.key}
              onClick={() => {
                setTab(t.key)
                setSelected([])
              }}
              className={`-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 py-2 text-[13px] ${tab === t.key ? 'border-ink font-medium text-ink' : 'border-transparent text-muted hover:text-ink'}`}
            >
              {t.label}
              {list.data && t.key !== 'done' && list.data.counts[t.key] > 0 && <span className="rounded-full bg-raised px-1.5 font-mono text-[10px] text-ink-2">{list.data.counts[t.key]}</span>}
            </button>
          ))}
        </div>

        {selected.length > 0 && (
          <div role="toolbar" aria-label="Toplu işlemler" className="sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-[13px] shadow-[0_8px_24px_var(--shadow)]">
            <span className="flex-1 text-ink-2">{selected.length} öğe seçildi</span>
            <button type="button" onClick={() => run(() => inboxApi.bulk(selected, 'done'))} className="rounded-md border border-line px-2.5 py-1 hover:bg-raised">
              Tamamlandı
            </button>
            <SnoozeMenu onPick={(until) => run(() => inboxApi.bulk(selected, 'snooze', until))} />
            {allApprovals && (
              <button type="button" onClick={() => run(() => inboxApi.bulk(selected, 'approve'))} className="rounded-md bg-brand px-2.5 py-1 font-medium text-on-brand hover:bg-brand-hover">
                Hepsini onayla
              </button>
            )}
          </div>
        )}

        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}

        {list.isError && <p role="alert" className="m-0 text-sm text-bad">Inbox yüklenemedi.</p>}
        {list.isSuccess && items.length === 0 && (
          <p className="m-0 rounded-lg border border-dashed border-line px-3 py-8 text-center text-[13px] text-muted">{tab === 'done' ? 'Tamamlanan öğe yok.' : 'Her şey yolunda; bekleyen bir şey yok.'}</p>
        )}

        {INBOX_GROUPS.map((group) => {
          const rows = items.filter((i) => inboxGroup(i.created_at) === group)
          if (rows.length === 0) return null
          return (
            <section key={group} aria-label={group} className="flex flex-col gap-1">
              <h2 className="m-0 mb-1 text-xs font-medium text-muted">{group}</h2>
              <ul className="m-0 list-none overflow-hidden rounded-lg border border-line bg-surface p-0">
                {rows.map((item) => (
                  <InboxRow key={item.id} item={item} checked={selected.includes(item.id)} onToggle={() => toggle(item.id)} onSnooze={(until) => run(() => inboxApi.snooze(item.id, until))} />
                ))}
              </ul>
            </section>
          )
        })}
      </div>
    </main>
  )
}

function InboxRow({ item, checked, onToggle, onSnooze }: { item: InboxItem; checked: boolean; onToggle: () => void; onSnooze: (until: SnoozeUntil) => void }) {
  const link = href({ name: 'inbox', itemId: item.id })

  return (
    <li className={`flex items-start gap-3 border-b border-line px-3 py-3 last:border-b-0 ${checked ? 'bg-brand-tint' : 'hover:bg-canvas'}`}>
      <input type="checkbox" aria-label={`${item.title} seç`} checked={checked} onChange={onToggle} className="mt-1.5 accent-brand" />
      <span className={`mt-2 size-1.5 shrink-0 rounded-full ${item.read ? '' : 'bg-brand'}`} role={item.read ? undefined : 'img'} aria-label={item.read ? undefined : 'Okunmadı'} />
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-raised font-mono text-[11px] font-medium text-ink-2" aria-hidden>
        {initials(item)}
      </span>
      <a href={link} className="flex min-w-0 flex-1 flex-col gap-0.5 text-ink no-underline">
        <span className="flex flex-wrap items-center gap-2">
          <span className={`truncate text-[13px] ${item.read ? 'font-normal' : 'font-medium'}`}>{item.title}</span>
          <span className={`rounded px-1.5 py-px text-[10px] font-medium ${TYPE_TONE[item.type]}`}>{TYPE_LABEL[item.type]}</span>
        </span>
        {item.body && <span className="line-clamp-2 text-xs text-muted">{item.body}</span>}
        {item.source_label && <span className="text-[11px] text-faint">{item.source_label}</span>}
      </a>
      <span className="flex shrink-0 flex-col items-end gap-1.5">
        <span className="font-mono text-[11px] text-muted">{time(item.created_at)}</span>
        <span className="flex items-center gap-1">
          {item.status !== 'done' && <SnoozeMenu compact onPick={onSnooze} />}
          <a href={link} className={`rounded-md px-2 py-0.5 text-xs no-underline ${item.actionable ? 'bg-brand font-medium text-on-brand hover:bg-brand-hover' : 'border border-line text-ink-2 hover:bg-raised'}`}>
            {primaryLabel(item)}
          </a>
        </span>
      </span>
    </li>
  )
}

function SnoozeMenu({ onPick, compact = false }: { onPick: (until: SnoozeUntil) => void; compact?: boolean }) {
  const [open, setOpen] = useState(false)

  return (
    <span className="relative">
      <button type="button" aria-expanded={open} onClick={() => setOpen((o) => !o)} className={`rounded-md border border-line text-ink-2 hover:bg-raised ${compact ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1'}`}>
        Ertele
      </button>
      {open && (
        <span role="menu" className="absolute right-0 z-20 mt-1 flex w-36 flex-col rounded-lg border border-line bg-surface p-1 shadow-[0_8px_24px_var(--shadow)]">
          {SNOOZE.map((s) => (
            <button
              key={s.key}
              role="menuitem"
              type="button"
              onClick={() => {
                setOpen(false)
                onPick(s.key)
              }}
              className="rounded-md px-2 py-1.5 text-left text-xs text-ink-2 hover:bg-raised"
            >
              {s.label}
            </button>
          ))}
        </span>
      )}
    </span>
  )
}

function InboxItemPage({ id }: { id: number }) {
  const queryClient = useQueryClient()
  const refresh = useRefreshInbox()
  const item = useQuery({ queryKey: ['inbox', 'item', id], queryFn: () => inboxApi.get(id) })

  const mutation = useMutation({
    mutationFn: (work: () => Promise<InboxItemDetail | InboxItem>) => work(),
    onSuccess: (data) => {
      if ('detail' in data) queryClient.setQueryData(['inbox', 'item', id], data)
      refresh()
    },
  })

  const act = (work: () => Promise<InboxItemDetail | InboxItem>) => mutation.mutateAsync(work).catch(() => undefined)

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-6">
        <a href={href({ name: 'inbox', itemId: null })} className="text-xs text-muted no-underline hover:text-ink">
          ← {t('nav.inbox')}
        </a>
        {item.isError && (
          <p role="alert" className="m-0 text-sm text-bad">
            Öğe bulunamadı.
          </p>
        )}
        {item.data && (
          <InboxDetailView
            item={item.data}
            busy={mutation.isPending}
            error={mutation.error instanceof Error ? mutation.error.message : null}
            onApprove={() => act(() => inboxApi.approve(id))}
            onReject={(reason) => act(() => inboxApi.reject(id, reason))}
            onAnswer={(answer) => act(() => inboxApi.answer(id, answer))}
            onDraft={(patch) => act(() => inboxApi.draft(id, patch))}
            onDone={() => act(() => inboxApi.done(id)).then(() => navigate({ name: 'inbox', itemId: null }))}
          />
        )}
      </div>
    </main>
  )
}
