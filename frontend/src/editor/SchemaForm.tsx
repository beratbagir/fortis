import { t } from '../i18n'
import { useId } from 'react'
import type { JsonSchema, Settings } from './types'

interface Props {
  schema: JsonSchema
  values: Settings
  onChange: (key: string, value: unknown) => void
  readOnly?: boolean
}

const fieldClass = 'rounded-xl border border-line-strong bg-surface px-3 text-sm text-ink disabled:bg-canvas disabled:text-muted read-only:bg-canvas'
const inputClass = `w-full ${fieldClass}`

/** Katalogdaki settings_schema'dan otomatik form: metin, çok satırlı metin, seçim, sayı, evet/hayır, anahtar-değer. */
export function SchemaForm({ schema, values, onChange, readOnly = false }: Props) {
  const required = new Set(schema.required ?? [])

  return (
    <div className="flex flex-col gap-4">
      {Object.entries(schema.properties ?? {}).map(([key, field]) => (
        <Field key={key} name={key} schema={field} value={values[key]} required={required.has(key)} readOnly={readOnly || !!field.readOnly} onChange={(v) => onChange(key, v)} />
      ))}
    </div>
  )
}

interface FieldProps {
  name: string
  schema: JsonSchema
  value: unknown
  required: boolean
  readOnly: boolean
  onChange: (value: unknown) => void
}

function Field({ name, schema, value, required, readOnly, onChange }: FieldProps) {
  const id = useId()
  const help = schema.description ? `${id}-help` : undefined
  const label = (
    <label htmlFor={id} className="text-[13px] font-bold text-ink-2">
      {schema.title ?? name}
      {required && <span className="font-semibold text-muted"> {t('editor.form.required')}</span>}
    </label>
  )
  const description = schema.description && (
    <p id={help} className="m-0 text-xs text-muted">
      {schema.description}
    </p>
  )

  if (schema.type === 'object' && schema.additionalProperties) {
    return (
      <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
        <legend className="mb-1.5 p-0 text-[13px] font-bold text-ink-2">{schema.title ?? name}</legend>
        <KeyValueEditor value={(value as Record<string, string>) ?? {}} onChange={onChange} readOnly={readOnly} label={schema.title ?? name} />
        {description}
      </fieldset>
    )
  }

  if (schema.type === 'boolean') {
    return (
      <div className="flex flex-col gap-1">
        <label className="flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={Boolean(value ?? schema.default)} disabled={readOnly} onChange={(e) => onChange(e.target.checked)} aria-describedby={help} />
          {schema.title ?? name}
        </label>
        {description}
      </div>
    )
  }

  const text = value === null || value === undefined ? '' : String(value)

  return (
    <div className="flex flex-col gap-1.5">
      {label}
      {schema.enum ? (
        <select id={id} value={text} disabled={readOnly} aria-describedby={help} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)} className={`${inputClass} h-10`}>
          {!required && <option value="">{schema.default ? t('editor.form.default_value', { value: labelOf(schema, String(schema.default)) }) : t('editor.form.default')}</option>}
          {schema.enum.map((option) => (
            <option key={option} value={option}>
              {labelOf(schema, option)}
            </option>
          ))}
        </select>
      ) : schema.format === 'textarea' ? (
        <textarea id={id} value={text} readOnly={readOnly} aria-describedby={help} aria-required={required} rows={4} onChange={(e) => onChange(e.target.value)} className={`${inputClass} py-2 font-mono text-[13px]`} />
      ) : (
        <input
          id={id}
          type={schema.type === 'number' || schema.type === 'integer' ? 'number' : 'text'}
          value={text}
          readOnly={readOnly}
          aria-describedby={help}
          aria-required={required}
          placeholder={schema.default !== undefined ? String(schema.default) : undefined}
          onChange={(e) => onChange(schema.type === 'number' || schema.type === 'integer' ? (e.target.value === '' ? null : Number(e.target.value)) : e.target.value)}
          className={`${inputClass} h-10 ${schema.readOnly ? 'font-mono text-xs' : ''}`}
        />
      )}
      {description}
    </div>
  )
}

const labelOf = (schema: JsonSchema, value: string) => schema.enumNames?.[schema.enum?.indexOf(value) ?? -1] ?? value

/** Anahtar-değer listesi (parametreler). Sıra korunur. */
function KeyValueEditor({ value, onChange, readOnly, label }: { value: Record<string, string>; onChange: (v: Record<string, string>) => void; readOnly: boolean; label: string }) {
  const entries = Object.entries(value)
  const update = (index: number, key: string, val: string) => onChange(Object.fromEntries(entries.map(([k, v], i) => (i === index ? [key, val] : [k, v]))))

  return (
    <div className="flex flex-col gap-1.5">
      {entries.map(([k, v], i) => (
        <div key={i} className="flex gap-1.5">
          <input aria-label={t('editor.form.key', { label, n: i + 1 })} value={k} readOnly={readOnly} onChange={(e) => update(i, e.target.value, v)} className={`${fieldClass} h-9 w-2/5 min-w-0 shrink-0 font-mono text-xs`} />
          <input aria-label={t('editor.form.value', { label, key: k || i + 1 })} value={v} readOnly={readOnly} onChange={(e) => update(i, k, e.target.value)} className={`${fieldClass} h-9 min-w-0 flex-1 font-mono text-xs`} />
          {!readOnly && (
            <button type="button" aria-label={t('editor.form.remove_param', { key: k || i + 1 })} onClick={() => onChange(Object.fromEntries(entries.filter((_, j) => j !== i)))} className="h-9 w-9 shrink-0 rounded-xl border border-line text-faint hover:text-bad">
              ×
            </button>
          )}
        </div>
      ))}
      {!readOnly && (
        <button type="button" onClick={() => onChange({ ...value, [entries.some(([k]) => k === '') ? `param_${entries.length + 1}` : '']: '' })} className="h-9 self-start rounded-xl border border-line px-3 text-xs font-bold text-ink hover:bg-canvas">
          {t('editor.form.add_param')}
        </button>
      )}
    </div>
  )
}
