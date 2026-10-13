import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react'
import type { MessageExtras } from '../api'
import { ArrowUpIcon } from '../ui/icons'
import { MicButton } from '../voice/MicButton'
import { useVoiceDraft } from '../voice/useVoiceDraft'
import { AutoSendToggle, VoiceHint } from '../voice/VoiceHint'

interface Props {
  disabled: boolean
  onSend: (text: string, extras?: MessageExtras) => void
  /** Dışarıdan yazma alanına konacak metin (ör. "Değiştir" butonu). `id` her seferinde değişmeli. */
  draft?: { id: number; text: string }
  placeholder?: string
  /** Soru kutusunun altındaki araçlar: solda (kaynak seçici), sağda (model seçici). */
  leading?: ReactNode
  trailing?: ReactNode
  /** home: sayfanın ortasında, çerçevesiz zemin; dock: sohbetin altında. */
  variant?: 'home' | 'dock'
}

export function Composer({ disabled, onSend, draft, placeholder = 'Fortis sana nasıl yardım edebilir?', leading, trailing, variant = 'dock' }: Props) {
  const [text, setText] = useState('')
  const [draftId, setDraftId] = useState<number>()
  const textarea = useRef<HTMLTextAreaElement>(null)

  // Yeni bir taslak geldiyse metni render sırasında güncelle (efekt içinde setState yerine).
  if (draft && draft.id !== draftId) {
    setDraftId(draft.id)
    setText(draft.text)
  }

  useEffect(() => {
    if (!draft) return
    const el = textarea.current
    el?.focus()
    el?.setSelectionRange(draft.text.length, draft.text.length)
  }, [draft])

  useEffect(() => {
    const el = textarea.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 240)}px`
  }, [text])

  useEffect(() => {
    if (!disabled) textarea.current?.focus()
  }, [disabled])

  const submit = (event?: FormEvent) => {
    event?.preventDefault()
    const value = text.trim()
    if (value === '' || disabled) return
    const meta = voice.consume(value)
    onSend(value, meta ? { voice: meta } : undefined)
    setText('')
  }

  const voice = useVoiceDraft({ text, setText, input: textarea, submit })

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      submit()
    }
  }

  return (
    <form onSubmit={submit} className={variant === 'dock' ? 'bg-bg px-4 pt-2 pb-3' : ''}>
      <div className="mx-auto mb-1 max-w-3xl">
        <VoiceHint fromVoice={voice.fromVoice} countdown={voice.countdown} />
      </div>
      <div className="mx-auto flex max-w-3xl flex-col gap-1 rounded-2xl border border-line-strong bg-surface px-3 pt-2.5 pb-2 shadow-[0_2px_10px_var(--shadow)] focus-within:border-faint">
        <textarea
          ref={textarea}
          rows={variant === 'home' ? 2 : 1}
          value={text}
          aria-label="Mesaj"
          onChange={(event) => setText(event.target.value)}
          onKeyDown={onKeyDown}
          placeholder={placeholder}
          className="max-h-60 w-full resize-none bg-transparent px-1 py-1 text-[15px] outline-none placeholder:text-faint focus-visible:outline-none"
        />
        <div className="flex items-center gap-1">
          {leading}
          <span className="flex-1" />
          <MicButton onText={voice.receive} pushToTalk className="self-center" />
          {trailing}
          <button
            type="submit"
            aria-label="Gönder"
            disabled={disabled || text.trim() === ''}
            className="ml-1 flex size-8 items-center justify-center rounded-lg bg-brand text-on-brand hover:bg-brand-hover disabled:cursor-not-allowed disabled:bg-line-strong disabled:text-bg"
          >
            <ArrowUpIcon />
          </button>
        </div>
      </div>
      <div className="mx-auto mt-1.5 flex max-w-3xl flex-wrap justify-between gap-2 text-xs text-faint">
        <p className="m-0">Enter ile gönder, Shift+Enter ile yeni satır.</p>
        <AutoSendToggle />
      </div>
    </form>
  )
}
