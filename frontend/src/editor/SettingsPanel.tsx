import { t } from '../i18n'
import { useState } from 'react'
import { SchemaForm } from './SchemaForm'
import { tone } from './tones'
import type { BlockDef, EditError, GraphNode, Operation, Settings } from './types'

type SettingsOp = Extract<Operation, { op: 'update_settings' | 'update_trigger' }>

interface EditProps {
  mode: 'edit'
  node: GraphNode
  def: BlockDef | undefined
  isTrigger: boolean
  errors: EditError[]
  onSchedule: (op: SettingsOp) => void
  onDelete: () => void
  onMove?: (direction: -1 | 1) => void
  canMove?: { up: boolean; down: boolean }
}

interface DraftProps {
  mode: 'draft'
  def: BlockDef
  where: string
  errors: EditError[]
  busy: boolean
  onInsert: (settings: Settings) => void
  onCancel: () => void
}

/** Boş anahtarlı parametreler (yazılırken) gönderilmez. */
function clean(value: unknown): unknown {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).filter(([k]) => k !== ''))
  }
  return value
}

function defaults(def: BlockDef): Settings {
  return Object.fromEntries(
    Object.entries(def.settings_schema.properties ?? {})
      .filter(([, schema]) => schema.default !== undefined)
      .map(([key, schema]) => [key, schema.default]),
  )
}

function Header({ def, title, id }: { def: BlockDef | undefined; title: string; id?: string | null }) {
  const colors = tone(def?.tone)

  return (
    <div className="flex items-center gap-3">
      <div className="flex size-10 shrink-0 items-center justify-center rounded-xl text-[13px] font-extrabold" style={{ background: colors.bg, color: colors.fg }} aria-hidden>
        {def?.glyph ?? '</>'}
      </div>
      <div className="flex min-w-0 flex-col">
        <h2 className="m-0 truncate text-base font-extrabold">{title}</h2>
        <span className="truncate text-xs font-semibold text-faint">
          {def?.label ?? t('editor.settings.advanced')}
          {id && <span className="font-mono"> · {id}</span>}
        </span>
      </div>
    </div>
  )
}

function ErrorList({ errors }: { errors: EditError[] }) {
  if (errors.length === 0) return null

  return (
    <div role="alert" className="rounded-xl border border-bad/30 bg-bad-soft px-3 py-2 text-[13px] text-bad">
      <strong className="block">{t('editor.settings.not_saved')}</strong>
      <ul className="m-0 mt-1 list-disc pl-4">
        {errors.map((e, i) => (
          <li key={i}>{e.message}</li>
        ))}
      </ul>
    </div>
  )
}

/** Sağ paneldeki "Blok ayarları": seçili bloğun formu veya eklenmek üzere olan yeni bloğun formu. */
export function SettingsPanel(props: EditProps | DraftProps) {
  const initial = props.mode === 'edit' ? { title: props.node.title, ...props.node.settings } : { title: null, ...defaults(props.def) }
  const [values, setValues] = useState<Settings>(initial)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const def = props.def
  const schema = def?.settings_schema ?? { type: 'object', properties: {} }

  const change = (key: string, value: unknown) => {
    setValues((v) => ({ ...v, [key]: value }))

    if (props.mode === 'edit' && props.node.id) {
      const settings = { [key]: clean(value) }
      props.onSchedule(props.isTrigger ? { op: 'update_trigger', trigger_id: props.node.id, settings } : { op: 'update_settings', task_id: props.node.id, settings })
    }
  }

  if (props.mode === 'draft') {
    return (
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault()
          props.onInsert(Object.fromEntries(Object.entries(values).map(([k, v]) => [k, clean(v)])))
        }}
      >
        <Header def={def} title={t('editor.settings.new_title', { label: props.def.label })} />
        <p className="m-0 text-[13px] text-muted">{t('editor.settings.insert_hint', { where: props.where })}</p>
        <TitleField value={values.title} onChange={(v) => change('title', v)} />
        <SchemaForm schema={schema} values={values} onChange={change} />
        <ErrorList errors={props.errors} />
        <div className="flex gap-2">
          <button type="submit" disabled={props.busy} className="h-10 rounded-xl bg-brand px-4 text-sm font-bold text-on-brand disabled:opacity-60">
            {props.busy ? t('editor.settings.adding') : t('editor.settings.add')}
          </button>
          <button type="button" onClick={props.onCancel} className="h-10 rounded-xl border border-line px-4 text-sm font-bold">
            {t('editor.common.cancel')}
          </button>
        </div>
      </form>
    )
  }

  const { node } = props

  return (
    <div className="flex flex-col gap-4">
      <Header def={def} title={node.title ?? node.id ?? t('editor.settings.step')} id={node.id} />

      {node.writes && (
        <p className="m-0 rounded-xl bg-accent-soft px-3 py-2 text-[13px] text-accent">
          {t('editor.settings.writes')}
        </p>
      )}

      {node.readonly ? (
        <p className="m-0 rounded-xl bg-canvas px-3 py-2 text-[13px] text-ink-2">
          {t('editor.settings.readonly', { type: node.raw_type ?? t('editor.settings.unknown_type') })}
        </p>
      ) : (
        <>
          {!props.isTrigger && <TitleField value={values.title} onChange={(v) => change('title', v)} />}
          <SchemaForm schema={schema} values={values} onChange={change} />
        </>
      )}

      <ErrorList errors={props.errors} />

      {!props.isTrigger && props.onMove && (
        <div className="flex gap-2">
          <button type="button" disabled={!props.canMove?.up} onClick={() => props.onMove?.(-1)} className="h-9 rounded-xl border border-line px-3 text-[13px] font-bold disabled:opacity-50">
            {t('editor.settings.move_up')}
          </button>
          <button type="button" disabled={!props.canMove?.down} onClick={() => props.onMove?.(1)} className="h-9 rounded-xl border border-line px-3 text-[13px] font-bold disabled:opacity-50">
            {t('editor.settings.move_down')}
          </button>
        </div>
      )}

      <div className="border-t border-line pt-4">
        {confirmDelete ? (
          <div role="alertdialog" aria-label={t('editor.settings.delete_confirm')} className="flex flex-col gap-2 rounded-xl bg-bad-soft p-3 text-[13px] text-bad">
            <span>
              {node.readonly ? t('editor.settings.delete_advanced') + ' ' : ''}
              {node.children?.length || node.branches ? t('editor.settings.delete_children') + ' ' : ''}
              {t('editor.settings.delete_question')}
            </span>
            <div className="flex gap-2">
              <button type="button" onClick={props.onDelete} className="h-9 rounded-xl bg-bad px-3 font-bold text-bg">
                {t('editor.settings.delete')}
              </button>
              <button type="button" onClick={() => setConfirmDelete(false)} className="h-9 rounded-xl border border-bad/30 bg-surface px-3 font-bold text-ink">
                {t('editor.common.cancel')}
              </button>
            </div>
          </div>
        ) : (
          <button type="button" onClick={() => setConfirmDelete(true)} className="h-9 rounded-xl border border-line px-3 text-[13px] font-bold text-bad hover:bg-bad-soft">
            {props.isTrigger ? t('editor.settings.delete_trigger') : t('editor.settings.delete_block')}
          </button>
        )}
      </div>
    </div>
  )
}

function TitleField({ value, onChange }: { value: unknown; onChange: (value: string) => void }) {
  return (
    <SchemaForm
      schema={{ type: 'object', properties: { title: { type: 'string', title: t('editor.settings.title_field'), description: t('editor.settings.title_help') } } }}
      values={{ title: value }}
      onChange={(_, v) => onChange(v as string)}
    />
  )
}
