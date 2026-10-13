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
          {def?.label ?? 'Gelişmiş'}
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
      <strong className="block">Kaydedilmedi; doğrulama hatası:</strong>
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
        <Header def={def} title={`Yeni: ${props.def.label}`} />
        <p className="m-0 text-[13px] text-muted">{props.where}. Zorunlu alanları doldurup ekleyin; blok doğrulamadan geçerse taslağa eklenir.</p>
        <TitleField value={values.title} onChange={(v) => change('title', v)} />
        <SchemaForm schema={schema} values={values} onChange={change} />
        <ErrorList errors={props.errors} />
        <div className="flex gap-2">
          <button type="submit" disabled={props.busy} className="h-10 rounded-xl bg-brand px-4 text-sm font-bold text-on-brand disabled:opacity-60">
            {props.busy ? 'Ekleniyor…' : 'Ekle'}
          </button>
          <button type="button" onClick={props.onCancel} className="h-10 rounded-xl border border-line px-4 text-sm font-bold">
            Vazgeç
          </button>
        </div>
      </form>
    )
  }

  const { node } = props

  return (
    <div className="flex flex-col gap-4">
      <Header def={def} title={node.title ?? node.id ?? 'Adım'} id={node.id} />

      {node.writes && (
        <p className="m-0 rounded-xl bg-accent-soft px-3 py-2 text-[13px] text-accent">
          Bu blok dış sisteme yazar. Test çalıştırmada gerçekten gönderilmez, logda gösterilir.
        </p>
      )}

      {node.readonly ? (
        <p className="m-0 rounded-xl bg-canvas px-3 py-2 text-[13px] text-ink-2">
          Bu adım ({node.raw_type ?? 'bilinmeyen tür'}) tuvaldeki bloklardan birine karşılık gelmiyor. Salt okunurdur; tuvaldeki düzenlemeler onu değiştirmez. YAML görünümünden düzenleyebilirsiniz.
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
            Yukarı taşı
          </button>
          <button type="button" disabled={!props.canMove?.down} onClick={() => props.onMove?.(1)} className="h-9 rounded-xl border border-line px-3 text-[13px] font-bold disabled:opacity-50">
            Aşağı taşı
          </button>
        </div>
      )}

      <div className="border-t border-line pt-4">
        {confirmDelete ? (
          <div role="alertdialog" aria-label="Silme onayı" className="flex flex-col gap-2 rounded-xl bg-bad-soft p-3 text-[13px] text-bad">
            <span>
              {node.readonly ? 'Bu gelişmiş adım tuvalden geri getirilemez (sürüm geçmişinden geri yüklenebilir). ' : ''}
              {node.children?.length || node.branches ? 'İçindeki adımlarla birlikte silinecek. ' : ''}
              Silinsin mi?
            </span>
            <div className="flex gap-2">
              <button type="button" onClick={props.onDelete} className="h-9 rounded-xl bg-bad px-3 font-bold text-bg">
                Sil
              </button>
              <button type="button" onClick={() => setConfirmDelete(false)} className="h-9 rounded-xl border border-bad/30 bg-surface px-3 font-bold text-ink">
                Vazgeç
              </button>
            </div>
          </div>
        ) : (
          <button type="button" onClick={() => setConfirmDelete(true)} className="h-9 rounded-xl border border-line px-3 text-[13px] font-bold text-bad hover:bg-bad-soft">
            {props.isTrigger ? 'Tetikleyiciyi sil' : 'Bloğu sil'}
          </button>
        )}
      </div>
    </div>
  )
}

function TitleField({ value, onChange }: { value: unknown; onChange: (value: string) => void }) {
  return (
    <SchemaForm
      schema={{ type: 'object', properties: { title: { type: 'string', title: 'Başlık', description: 'Tuvalde ve çalışmalarda görünen ad.' } } }}
      values={{ title: value }}
      onChange={(_, v) => onChange(v as string)}
    />
  )
}
