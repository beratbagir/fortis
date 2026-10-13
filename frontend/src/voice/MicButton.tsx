import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { MicIcon } from '../ui/icons'
import { voiceApi } from './api'
import { isRecordingSupported, useVoiceRecorder } from './useVoiceRecorder'

interface Props {
  onText: (text: string, transcriptionId: number) => void
  /** Metin kutusu odakta değilken boşluk tuşuna basılı tutarak konuşma. Sayfada tek bir buton için açık olmalı. */
  pushToTalk?: boolean
  /** Kayıt başladığında (ör. editörde chat sekmesine geçmek için). */
  onStart?: () => void
  className?: string
}

const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

/** Boşluk tuşu bu öğelerde kendi işini yapar (yazma, buton tıklama). */
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName) || target.getAttribute('role') === 'textbox'
}

/** Chat kutusundaki mikrofon. Metni sadece kutuya yazar; göndermek kullanıcının işi. */
export function MicButton({ onText, pushToTalk = false, onStart, className = '' }: Props) {
  const config = useQuery({ queryKey: ['voice-config'], queryFn: voiceApi.config, staleTime: Infinity })
  const maxSeconds = config.data?.max_seconds ?? 30
  const recorder = useVoiceRecorder({ maxSeconds, onText })
  const { status, start, stop, cancel } = recorder

  const onStartRef = useRef(onStart)
  useEffect(() => {
    onStartRef.current = onStart
  })

  const holding = useRef(false)

  // Esc kaydı (veya çeviriyi) iptal eder; hiçbir şey gönderilmez.
  useEffect(() => {
    if (status !== 'recording' && status !== 'transcribing') return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        holding.current = false
        cancel()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [status, cancel])

  // Bas-konuş
  useEffect(() => {
    if (!pushToTalk || !config.data?.enabled) return
    const down = (event: KeyboardEvent) => {
      if (event.code !== 'Space' || event.repeat || event.ctrlKey || event.metaKey || event.altKey || isTypingTarget(event.target)) return
      event.preventDefault()
      if (status !== 'idle' && status !== 'error') return
      holding.current = true
      onStartRef.current?.()
      start({ holdToTalk: true })
    }
    const up = (event: KeyboardEvent) => {
      if (event.code !== 'Space' || !holding.current) return
      event.preventDefault()
      holding.current = false
      stop()
    }
    window.addEventListener('keydown', down)
    window.addEventListener('keyup', up)
    return () => {
      window.removeEventListener('keydown', down)
      window.removeEventListener('keyup', up)
    }
  }, [pushToTalk, config.data?.enabled, status, start, stop])

  if (!config.data?.enabled || !isRecordingSupported()) return null

  const toggle = () => {
    if (status === 'recording') stop()
    else if (status === 'idle' || status === 'error') {
      onStartRef.current?.()
      start()
    }
  }

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <span aria-live="polite" className="text-xs font-semibold whitespace-nowrap">
        {status === 'recording' && (
          <span className="text-bad tabular-nums">
            {clock(recorder.elapsed)} / {clock(maxSeconds)}
          </span>
        )}
        {status === 'transcribing' && (
          <span className="flex items-center gap-1.5 text-muted">
            <span className="size-3 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />
            Metne çevriliyor…
          </span>
        )}
        {status === 'error' && (
          <span role="alert" className="text-bad whitespace-normal">
            {recorder.error}
          </span>
        )}
      </span>
      <button
        type="button"
        onClick={toggle}
        disabled={status === 'transcribing'}
        aria-label={status === 'recording' ? 'Kaydı durdur' : 'Sesli komut'}
        aria-pressed={status === 'recording'}
        title={status === 'recording' ? 'Durdurmak için tıkla, iptal için Esc' : 'Sesli komut (metin kutusu dışındayken boşluk tuşuna basılı tut)'}
        className={`relative flex size-[34px] shrink-0 items-center justify-center rounded-[10px] border transition-colors disabled:opacity-60 ${
          status === 'recording' ? 'border-bad/40 bg-bad-soft text-bad' : 'border-line bg-surface text-ink hover:bg-canvas'
        }`}
      >
        {status === 'recording' ? (
          <>
            <span
              className="absolute inset-0 rounded-[10px] border-2 border-bad/50"
              style={{ transform: `scale(${1 + recorder.level * 0.35})`, transition: 'transform 100ms linear' }}
              aria-hidden
            />
            <span className="size-2.5 rounded-full bg-bad" aria-hidden />
          </>
        ) : status === 'transcribing' ? (
          <span className="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />
        ) : (
          <MicIcon />
        )}
      </button>
    </div>
  )
}
