import { DiffEditor } from '@monaco-editor/react'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { t } from '../i18n'
import { useTheme } from '../theme'
import { Dialog } from '../ui/Dialog'
import { when } from '../ui/format'
import { editorApi } from './api'
import './monaco'

const SOURCE = { chat: 'editor.source.chat', canvas: 'editor.source.canvas', yaml: 'editor.source.yaml', restore: 'editor.source.restore' } as const

interface Props {
  flowId: number
  current: number
  onClose: () => void
  onRestore: (number: number) => Promise<void>
}

/** Sürüm geçmişi: kim, ne zaman, kaynak; seçili sürümün güncel sürümle YAML farkı ve geri yükleme. */
export default function VersionsDialog({ flowId, current, onClose, onRestore }: Props) {
  const versions = useQuery({ queryKey: ['versions', flowId, current], queryFn: () => editorApi.versions(flowId) })
  const [selected, setSelected] = useState<number | null>(null)
  const compare = selected ?? versions.data?.find((v) => v.number !== current)?.number ?? null
  const diff = useQuery({ queryKey: ['diff', flowId, compare, current], queryFn: () => editorApi.diff(flowId, compare!, current), enabled: compare !== null })
  const [error, setError] = useState<string | null>(null)
  const monacoTheme = useTheme().resolved === 'dark' ? 'vs-dark' : 'light'
  const [busy, setBusy] = useState(false)

  return (
    <Dialog title={t('editor.versions.title')} onClose={onClose} wide>
      <div className="flex flex-col gap-4 lg:flex-row">
        <ol className="m-0 flex list-none flex-col gap-1.5 p-0 lg:w-72 lg:shrink-0" aria-label={t('editor.versions.list')}>
          {versions.data?.map((v) => (
            <li key={v.number}>
              <button
                type="button"
                aria-pressed={compare === v.number}
                onClick={() => setSelected(v.number)}
                className={`flex w-full flex-col gap-0.5 rounded-xl border px-3 py-2 text-left ${compare === v.number ? 'border-2 border-brand' : 'border-line'}`}
              >
                <span className="flex items-center gap-2 text-sm font-extrabold">
                  v{v.number}
                  {v.number === current && <span className="rounded-full bg-brand-soft px-2 text-[11px] text-brand-fg">{t('editor.versions.current')}</span>}
                  {v.is_published && <span className="rounded-full bg-ok-soft px-2 text-[11px] text-ok">{t('editor.versions.published')}</span>}
                </span>
                <span className="text-xs text-muted">
                  {SOURCE[v.source] ? t(SOURCE[v.source]) : v.source} · {when(v.created_at)}
                  {v.created_by ? ` · ${v.created_by}` : ''}
                </span>
              </button>
            </li>
          ))}
        </ol>

        <div className="flex min-w-0 flex-1 flex-col gap-3">
          {compare === null ? (
            <p className="m-0 text-sm text-muted">{t('editor.versions.none')}</p>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-semibold text-ink-2">
                  {t('editor.versions.compare', { left: compare, right: current })}
                </span>
                <div className="flex-1" />
                {compare !== current && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true)
                      setError(null)
                      try {
                        await onRestore(compare)
                        onClose()
                      } catch (e) {
                        setError((e as Error).message)
                      } finally {
                        setBusy(false)
                      }
                    }}
                    className="h-9 rounded-xl bg-brand px-3 text-sm font-bold text-on-brand disabled:opacity-60"
                  >
                    {t('editor.versions.restore', { version: compare })}
                  </button>
                )}
              </div>
              {error && <p role="alert" className="m-0 rounded-xl bg-bad-soft px-3 py-2 text-[13px] text-bad">{error}</p>}
              <div className="h-[60vh] overflow-hidden rounded-xl border border-line">
                {diff.data && (
                  <DiffEditor
                    theme={monacoTheme}
                    language="yaml"
                    original={diff.data.version.yaml}
                    modified={diff.data.against.yaml}
                    options={{ readOnly: true, renderSideBySide: true, minimap: { enabled: false }, fontFamily: 'Geist Mono', fontSize: 13 }}
                  />
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </Dialog>
  )
}
