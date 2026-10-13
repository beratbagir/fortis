import { useEffect, useRef, type ReactNode } from 'react'
import { CloseIcon } from './icons'

/** Erişilebilir modal: <dialog>, Esc ile kapanır, odak içeride kalır. */
export function Dialog({ title, onClose, children, wide = false, actions }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean; actions?: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (dialog && !dialog.open) dialog.showModal()
    return () => dialog?.close()
  }, [])

  return (
    <dialog
      ref={ref}
      aria-label={title}
      onCancel={(e) => {
        e.preventDefault()
        onClose()
      }}
      className={`m-auto flex max-h-[90vh] w-[calc(100%-32px)] flex-col rounded-[20px] border border-line bg-surface p-0 text-ink shadow-[0_24px_60px_var(--shadow)] backdrop:bg-overlay ${wide ? 'max-w-6xl' : 'max-w-2xl'}`}
    >
      <div className="flex items-center gap-3 border-b border-line px-5 py-4">
        <h2 className="m-0 flex-1 text-lg font-extrabold">{title}</h2>
        {actions}
        <button type="button" aria-label="Kapat" onClick={onClose} className="flex size-9 items-center justify-center rounded-[10px] border border-line text-ink-2">
          <CloseIcon />
        </button>
      </div>
      <div className="min-h-0 flex-1 overflow-auto p-5">{children}</div>
    </dialog>
  )
}
