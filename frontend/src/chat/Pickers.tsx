import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type ConnectorStatus } from '../api'
import { usageApi } from '../editor/api'
import { DATA_SOURCES } from './chatUtils'
import { ChevronIcon, LockIcon, SearchIcon, SlidersIcon, SparkIcon } from '../ui/icons'

/** Dışarı tıklayınca veya Esc ile kapanan açılır panel. Buton ekranın alt yarısındaysa yukarı açılır. */
function Popover({ open, onClose, children, label, align = 'right' }: { open: boolean; onClose: () => void; children: ReactNode; label: string; align?: 'left' | 'right' }) {
  const ref = useRef<HTMLDivElement>(null)
  const [up, setUp] = useState(false)
  const [measured, setMeasured] = useState(false)

  if (!open && measured) setMeasured(false)
  const anchor = (el: HTMLDivElement | null) => {
    ref.current = el
    if (el && !measured) {
      const button = el.parentElement?.getBoundingClientRect()
      setUp(button !== undefined && button.top > window.innerHeight / 2)
      setMeasured(true)
    }
  }

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => ref.current && !ref.current.parentElement?.contains(e.target as Node) && onClose()
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      window.removeEventListener('keydown', onKey)
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div ref={anchor} role="dialog" aria-label={label} className={`absolute z-30 rounded-xl border border-line bg-surface shadow-[0_12px_32px_var(--shadow)] ${up ? 'bottom-full mb-1.5' : 'top-full mt-1.5'} ${align === 'right' ? 'right-0' : 'left-0'}`}>
      {children}
    </div>
  )
}

/** "Auto" ya da izinli modellerden biri. Sadece config'teki (izinli) modeller gösterilir. */
export function ModelPicker({ value, onChange }: { value: string; onChange: (model: string) => void }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const settings = useQuery({ queryKey: ['ai-settings'], queryFn: usageApi.settings, staleTime: 60_000 })
  const models = settings.data?.models ?? []
  const filtered = models.filter((m) => `${m.label} ${m.strength ?? ''}`.toLocaleLowerCase('tr').includes(search.toLocaleLowerCase('tr')))
  const current = value === 'auto' ? 'Auto' : (models.find((m) => m.id === value)?.label.replace('Claude ', '') ?? value)

  const choose = (model: string) => {
    onChange(model)
    setOpen(false)
  }

  return (
    <div className="relative">
      <button type="button" aria-haspopup="dialog" aria-expanded={open} aria-label={`Model: ${current}`} onClick={() => setOpen((o) => !o)} className="flex h-7 items-center gap-1 rounded-md px-2 text-xs text-ink-2 hover:bg-raised">
        {current}
        <ChevronIcon open={open} width={12} height={12} />
      </button>
      <Popover open={open} onClose={() => setOpen(false)} label="Model seçimi">
        <div className="flex w-[min(420px,calc(100vw-48px))] flex-col">
          <label className="m-2 flex items-center gap-2 rounded-lg bg-canvas px-2.5 py-1.5 text-muted">
            <SearchIcon width={14} height={14} />
            <input autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Model ara…" aria-label="Model ara" className="flex-1 bg-transparent text-[13px] text-ink outline-none" />
          </label>
          <button
            type="button"
            aria-pressed={value === 'auto'}
            onClick={() => choose('auto')}
            className={`mx-2 flex items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] ${value === 'auto' ? 'bg-brand-soft' : 'hover:bg-raised'}`}
          >
            <SparkIcon className="text-brand-fg" />
            <span className="font-medium text-ink">Auto</span>
            <span className="flex-1 truncate text-xs text-muted">Her adım için uygun modeli seçer</span>
            {value === 'auto' && <span className="font-mono text-[11px] text-brand-fg">Seçili</span>}
          </button>
          <div className="mt-2 grid grid-cols-[140px_1fr] border-t border-line">
            <div className="border-r border-line p-2">
              <div className="flex items-center gap-2 rounded-lg bg-raised px-2 py-1.5 text-[13px] text-ink">
                <span className="flex size-5 items-center justify-center rounded bg-canvas font-mono text-[10px]">A</span>
                <span className="flex-1">Anthropic</span>
                <span className="font-mono text-[11px] text-muted">{models.length}</span>
              </div>
            </div>
            <ul className="m-0 flex list-none flex-col gap-0.5 p-2">
              <li className="px-2 pb-1 text-[11px] text-muted">Anthropic</li>
              {filtered.map((m) => (
                <li key={m.id}>
                  <button type="button" aria-pressed={value === m.id} onClick={() => choose(m.id)} className={`flex w-full flex-col rounded-lg px-2 py-1.5 text-left ${value === m.id ? 'bg-raised' : 'hover:bg-raised'}`}>
                    <span className="text-[13px] text-ink">{m.label}</span>
                    {m.strength && <span className="text-[11px] text-muted">{m.strength}</span>}
                  </button>
                </li>
              ))}
              {filtered.length === 0 && <li className="px-2 py-1.5 text-xs text-muted">Eşleşen model yok.</li>}
            </ul>
          </div>
          {settings.data?.policy_note && (
            <p className="m-0 flex items-center gap-1.5 border-t border-line bg-canvas px-3 py-2 text-[11px] text-muted">
              <LockIcon />
              {settings.data.policy_note}
            </p>
          )}
        </div>
      </Popover>
    </div>
  )
}


const STATUS_TEXT: Record<ConnectorStatus['status'], string> = { connected: 'Bağlı', attention: 'Dikkat', not_configured: 'Kurulum bekliyor', disabled: 'Yönetici kapattı' }

export function SourcePicker({ disabled, onChange }: { disabled: string[]; onChange: (disabled: string[]) => void }) {
  const [open, setOpen] = useState(false)
  const connectors = useQuery({ queryKey: ['connectors'], queryFn: api.connectors, staleTime: 60_000 })
  const sources = (connectors.data ?? []).filter((c) => DATA_SOURCES.includes(c.key))
  const available = sources.filter((c) => c.status !== 'not_configured' && c.status !== 'disabled')
  const active = available.filter((c) => !disabled.includes(c.key)).length

  const toggle = (key: string) => onChange(disabled.includes(key) ? disabled.filter((k) => k !== key) : [...disabled, key])

  return (
    <div className="relative">
      <button type="button" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen((o) => !o)} className="flex h-7 items-center gap-1.5 rounded-md px-2 text-xs text-muted hover:bg-raised hover:text-ink">
        <SlidersIcon />
        {active} kaynak
      </button>
      <Popover open={open} onClose={() => setOpen(false)} label="Kaynaklar" align="left">
        <ul className="m-0 flex w-64 list-none flex-col gap-0.5 p-2">
          {sources.map((c) => {
            const configured = c.status !== 'not_configured' && c.status !== 'disabled'
            return (
              <li key={c.key}>
                <label className={`flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-[13px] ${configured ? 'cursor-pointer hover:bg-raised' : 'opacity-60'}`}>
                  <input type="checkbox" disabled={!configured} checked={configured && !disabled.includes(c.key)} onChange={() => toggle(c.key)} className="accent-[var(--brand)]" />
                  <span className="flex-1 text-ink">{c.label}</span>
                  <span className={`text-[11px] ${c.status === 'connected' ? 'text-ok' : c.status === 'attention' ? 'text-warn' : 'text-faint'}`} title={c.detail ?? undefined}>
                    {STATUS_TEXT[c.status]}
                  </span>
                </label>
              </li>
            )
          })}
          {sources.length === 0 && <li className="px-2 py-1.5 text-xs text-muted">Yükleniyor…</li>}
        </ul>
        <p className="m-0 border-t border-line px-3 py-2 text-[11px] text-muted">Kapatılan kaynak bu sohbette kullanılmaz.</p>
      </Popover>
    </div>
  )
}
