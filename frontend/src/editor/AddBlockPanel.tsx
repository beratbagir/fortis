import { useState } from 'react'
import { getLocale, t } from '../i18n'
import { ChevronIcon, CloseIcon, SearchIcon } from '../ui/icons'
import { tone } from './tones'
import type { AppDef, BlockDef, Catalog } from './types'

const CHIPS = ['Tümü', 'Tetikleyici', 'Microsoft 365', 'Havayolu verisi', 'İK', 'Bildirim', 'Mantık', 'AI'] as const
type Chip = (typeof CHIPS)[number]

// Grup değerleri katalogdan (Türkçe) gelir; ekranda dile göre etiket.
const CHIP_KEYS: Record<Chip, string> = {
  Tümü: 'editor.add.chip_all',
  Tetikleyici: 'editor.add.chip_trigger',
  'Microsoft 365': 'editor.add.chip_m365',
  'Havayolu verisi': 'editor.add.chip_airline',
  İK: 'editor.add.chip_hr',
  Bildirim: 'editor.add.chip_notification',
  Mantık: 'editor.add.chip_logic',
  AI: 'editor.add.chip_ai',
}

interface Props {
  catalog: Catalog
  where: string
  /** Paletten bir gruba basılarak açıldıysa başlangıç filtresi. */
  initialChip?: Chip
  onPick: (kind: string) => void
  onClose: () => void
  onDescribe: (text: string) => void
}

const matches = (text: string, term: string) => text.toLocaleLowerCase(getLocale()).includes(term)

function Tile({ glyph, t: toneName, size = 40 }: { glyph: string; t: BlockDef['tone']; size?: number }) {
  const colors = tone(toneName)
  return (
    <span className="flex shrink-0 items-center justify-center rounded-xl text-[13px] font-extrabold" style={{ width: size, height: size, background: colors.bg, color: colors.fg }} aria-hidden>
      {glyph}
    </span>
  )
}

/** Sağ panel: uygulamalar ve aksiyonları, mantık ve AI blokları, bağlanabilecek uygulamalar, AI'a tarif et. */
export function AddBlockPanel({ catalog, where, initialChip = 'Tümü', onPick, onClose, onDescribe }: Props) {
  const [chip, setChip] = useState<Chip>(initialChip)
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState<string | null>(null)
  const [connectInfo, setConnectInfo] = useState<AppDef | null>(null)
  const [describe, setDescribe] = useState('')
  const term = search.trim().toLocaleLowerCase(getLocale())
  const blocks = new Map(catalog.blocks.map((b) => [b.kind, b]))

  const appVisible = (app: AppDef) =>
    (chip === 'Tümü' || app.group === chip) &&
    (term === '' || matches(app.name, term) || matches(app.description, term) || app.actions.some((a) => matches(a.name, term)))

  const connected = catalog.apps.filter((a) => a.connected && appVisible(a))
  const connectable = catalog.apps.filter((a) => !a.connected && appVisible(a))
  const logic = catalog.blocks.filter(
    (b) =>
      b.palette &&
      !b.app &&
      ((chip === 'Tümü' && !b.trigger) || b.group === chip || (chip === 'Mantık' && b.group === 'Mantık')) &&
      (term === '' || matches(b.label, term)),
  )

  return (
    <aside aria-label={t('editor.add.library')} className="flex min-h-0 flex-col bg-surface">
      <div className="flex flex-col gap-3.5 border-b border-line px-5 pt-5 pb-3">
        <div className="flex items-start gap-3">
          <div className="flex flex-1 flex-col gap-0.5">
            <h2 className="m-0 text-lg font-extrabold">{t('editor.add.title')}</h2>
            <span className="text-[13px] font-semibold text-muted">{where}</span>
          </div>
          <button type="button" aria-label={t('editor.common.close')} onClick={onClose} className="flex size-9 items-center justify-center rounded-[10px] border border-line text-ink-2">
            <CloseIcon />
          </button>
        </div>
        <label className="flex h-[42px] items-center gap-2 rounded-xl border border-line-strong px-3">
          <SearchIcon className="text-faint" />
          <span className="sr-only">{t('editor.add.search_label')}</span>
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('editor.add.search_placeholder')} className="h-[38px] flex-1 border-0 text-sm outline-none" />
        </label>
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('editor.add.category')}>
          {CHIPS.map((c) => (
            <button
              key={c}
              type="button"
              aria-pressed={chip === c}
              onClick={() => setChip(c)}
              className={`h-8 rounded-full px-3 text-xs font-bold ${chip === c ? 'border border-ink bg-ink text-bg' : 'border border-line bg-surface text-ink-2'}`}
            >
              {t(CHIP_KEYS[c])}
            </button>
          ))}
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-5 py-4">
        {connected.length > 0 && (
          <section className="flex flex-col gap-2">
            <h3 className="m-0 text-xs font-extrabold tracking-wide text-faint">{t('editor.add.connected_apps')}</h3>
            {connected.map((app) => {
              const expanded = open === app.app || (term !== '' && connected.length === 1)
              return (
                <div key={app.app} className={`overflow-hidden rounded-2xl ${expanded ? 'border-2 border-brand' : 'border border-line'}`}>
                  <button type="button" aria-expanded={expanded} onClick={() => setOpen(expanded ? null : app.app)} className="flex w-full items-center gap-3 bg-surface p-3 text-left">
                    <Tile glyph={app.glyph} t={app.tone} />
                    <span className="flex flex-1 flex-col">
                      <span className="text-[15px] font-extrabold text-ink">{app.name}</span>
                      <span className="text-xs font-semibold text-ok">{t('editor.add.connected', { description: app.description })}</span>
                    </span>
                    <ChevronIcon open={expanded} className="text-ink-2" />
                  </button>
                  {expanded && (
                    <div className="flex flex-col gap-0.5 px-2 pb-2">
                      {app.actions.map((action) => {
                        const block = action.kind ? blocks.get(action.kind) : undefined
                        const disabled = !block || block.available === false || !!action.unavailable_reason
                        return (
                          <button
                            key={action.name}
                            type="button"
                            disabled={disabled}
                            onClick={() => action.kind && onPick(action.kind)}
                            className="flex items-center gap-2.5 rounded-[10px] p-2.5 text-left hover:bg-brand-tint disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                          >
                            <span className="flex flex-1 flex-col gap-px">
                              <span className="text-sm font-bold text-ink">{action.name}</span>
                              <span className="text-xs font-medium text-muted">{action.unavailable_reason ?? action.description}</span>
                            </span>
                            <span className="text-xs font-extrabold text-brand-fg">{disabled ? t('editor.add.soon') : t('editor.add.add')}</span>
                          </button>
                        )
                      })}
                    </div>
                  )}
                </div>
              )
            })}
          </section>
        )}

        {logic.length > 0 && (
          <section className="flex flex-col gap-2">
            <h3 className="m-0 text-xs font-extrabold tracking-wide text-faint">{chip === 'Tetikleyici' ? t('editor.add.triggers') : t('editor.add.logic_ai')}</h3>
            <div className="grid grid-cols-2 gap-2">
              {logic.map((block) => (
                <button
                  key={block.kind}
                  type="button"
                  disabled={block.available === false}
                  title={block.unavailable_reason}
                  onClick={() => onPick(block.kind)}
                  className="flex items-center gap-2.5 rounded-[14px] border border-line bg-surface p-2.5 text-left hover:border-brand disabled:cursor-not-allowed disabled:opacity-55"
                >
                  <Tile glyph={block.glyph} t={block.tone} size={32} />
                  <span className="flex flex-col">
                    <span className="text-[13px] font-bold text-ink">{block.label.replace('Tetikleyici · ', '')}</span>
                    {block.available === false && <span className="text-[11px] font-semibold text-muted">{t('editor.add.unavailable')}</span>}
                  </span>
                </button>
              ))}
            </div>
            {logic.some((b) => b.available === false) && <p className="m-0 text-xs text-muted">{logic.find((b) => b.available === false)?.unavailable_reason}</p>}
          </section>
        )}

        {connectable.length > 0 && (
          <section className="flex flex-col gap-2">
            <h3 className="m-0 text-xs font-extrabold tracking-wide text-faint">{t('editor.add.connectable_apps')}</h3>
            {connectable.map((app) => (
              <div key={app.app} className="flex items-center gap-3 rounded-2xl border border-dashed border-line-strong bg-canvas p-3">
                <Tile glyph={app.glyph} t={app.tone} />
                <div className="flex flex-1 flex-col">
                  <span className="text-[15px] font-extrabold">{app.name}</span>
                  <span className="text-xs font-semibold text-muted">
                    {app.description}
                    {app.actions.length > 0 && t('editor.add.connect_first')}
                  </span>
                </div>
                <button type="button" onClick={() => setConnectInfo(app)} className="h-[34px] rounded-[10px] bg-ink px-3.5 text-[13px] font-bold text-bg">
                  {t('editor.add.connect')}
                </button>
              </div>
            ))}
            {connectInfo && (
              <div role="dialog" aria-label={t('editor.add.connection', { name: connectInfo.name })} className="flex flex-col gap-2 rounded-2xl border border-line bg-brand-tint p-3.5 text-[13px] text-ink-2">
                <strong className="text-ink">{connectInfo.name}</strong>
                {connectInfo.available ? (
                  <span>
                    {t('editor.add.secrets_before')}{' '}
                    <code className="font-mono">KESTRA_SECRETS</code> {t('editor.add.secrets_after')}{' '}
                    <span className="font-mono font-bold">{connectInfo.missing_secrets.join(', ')}</span>
                  </span>
                ) : (
                  <span>{t('editor.add.no_connector')}</span>
                )}
                <button type="button" onClick={() => setConnectInfo(null)} className="h-8 self-start rounded-[10px] border border-line bg-surface px-3 font-bold">
                  {t('editor.common.ok')}
                </button>
              </div>
            )}
            <span className="text-xs font-semibold text-muted">
              {t('editor.add.connect_note')}
            </span>
          </section>
        )}

        {connected.length === 0 && logic.length === 0 && connectable.length === 0 && <p className="m-0 text-sm text-muted">{t('editor.add.no_results')}</p>}
      </div>

      <form
        className="flex flex-col gap-2 border-t border-line px-5 pt-3.5 pb-5"
        onSubmit={(e) => {
          e.preventDefault()
          if (describe.trim()) onDescribe(describe.trim())
        }}
      >
        <span className="text-xs font-extrabold text-brand-fg">{t('editor.add.describe_title')}</span>
        <div className="flex gap-2 rounded-[14px] border border-line-strong bg-accent-soft p-2">
          <label className="flex flex-1">
            <span className="sr-only">{t('editor.add.describe_label')}</span>
            <input value={describe} onChange={(e) => setDescribe(e.target.value)} placeholder={t('editor.add.describe_placeholder')} className="flex-1 border-0 bg-transparent text-sm outline-none" />
          </label>
          <button type="submit" className="h-[34px] rounded-[10px] bg-brand px-3.5 text-[13px] font-bold text-on-brand">
            {t('editor.add.suggest')}
          </button>
        </div>
      </form>
    </aside>
  )
}

export type { Chip }
