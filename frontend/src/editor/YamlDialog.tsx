import Editor from '@monaco-editor/react'
import { useState } from 'react'
import { t } from '../i18n'
import { useTheme } from '../theme'
import { Dialog } from '../ui/Dialog'
import { ApiError } from './api'
import './monaco'
import type { EditError } from './types'

interface Props {
  yaml: string
  version: number
  onClose: () => void
  onSave: (yaml: string) => Promise<void>
}

/**
 * YAML görünümü: varsayılan salt okunur. "Düzenle" ile açılır, kaydetme aynı doğrulayıcıdan geçer.
 * Secret'ların sadece adları görünür ({{ secret('X') }}); değerler Fortis'te hiç yoktur.
 */
export default function YamlDialog({ yaml, version, onClose, onSave }: Props) {
  const monacoTheme = useTheme().resolved === 'dark' ? 'vs-dark' : 'light'
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(yaml)
  const [errors, setErrors] = useState<EditError[]>([])
  const [saving, setSaving] = useState(false)

  const save = async () => {
    setSaving(true)
    setErrors([])
    try {
      await onSave(value)
      setEditing(false)
    } catch (e) {
      setErrors(e instanceof ApiError && e.errors.length ? e.errors : [{ message: (e as Error).message, task_id: null }])
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog
      title={t('editor.yaml.title', { version })}
      onClose={onClose}
      wide
      actions={
        editing ? (
          <>
            <button type="button" onClick={() => { setEditing(false); setValue(yaml); setErrors([]) }} className="h-9 rounded-xl border border-line px-3 text-sm font-bold">
              {t('editor.common.cancel')}
            </button>
            <button type="button" disabled={saving} onClick={save} className="h-9 rounded-xl bg-brand px-3 text-sm font-bold text-on-brand disabled:opacity-60">
              {saving ? t('editor.yaml.validating') : t('editor.yaml.save')}
            </button>
          </>
        ) : (
          <button type="button" onClick={() => setEditing(true)} className="h-9 rounded-xl border border-line px-3 text-sm font-bold">
            {t('editor.yaml.edit')}
          </button>
        )
      }
    >
      {errors.length > 0 && (
        <ul role="alert" className="m-0 mb-3 list-disc rounded-xl border border-bad/30 bg-bad-soft py-2 pr-3 pl-7 text-[13px] text-bad">
          {errors.map((e, i) => (
            <li key={i}>
              {e.task_id && <strong className="font-mono">{e.task_id}: </strong>}
              {e.message}
            </li>
          ))}
        </ul>
      )}
      <div className="h-[65vh] overflow-hidden rounded-xl border border-line">
        <Editor
          theme={monacoTheme}
          language="yaml"
          value={value}
          onChange={(v) => setValue(v ?? '')}
          options={{ readOnly: !editing, minimap: { enabled: false }, fontFamily: 'Geist Mono', fontSize: 13, scrollBeyondLastLine: false, wordWrap: 'on' }}
        />
      </div>
    </Dialog>
  )
}
