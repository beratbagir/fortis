import { useCallback, useEffect, useRef, useState, type RefObject } from 'react'
import type { VoiceMeta } from './api'
import { useAutoSend } from './settings'

const AUTO_SEND_SECONDS = 2

interface Options {
  text: string
  setText: (text: string) => void
  input: RefObject<HTMLTextAreaElement | HTMLInputElement | null>
  /** Otomatik gönder açıksa geri sayım bitince çağrılır. */
  submit: () => void
}

/**
 * Sesten çevrilen metnin kutudaki hali: kutuya yazar, "kontrol et" etiketi, otomatik gönder geri sayımı
 * ve gönderirken kullanıcının metni değiştirip değiştirmediği.
 */
export function useVoiceDraft({ text, setText, input, submit }: Options) {
  const autoSend = useAutoSend()
  const [meta, setMeta] = useState<{ id: number; text: string } | null>(null)
  const [countdown, setCountdown] = useState<number | null>(null)
  const [cursorRev, setCursorRev] = useState(0)

  const submitRef = useRef(submit)
  useEffect(() => {
    submitRef.current = submit
  })

  const receive = useCallback(
    (transcript: string, transcriptionId: number) => {
      const next = text.trim() === '' ? transcript : `${text.trimEnd()} ${transcript}`
      setText(next)
      setMeta({ id: transcriptionId, text: next })
      setCursorRev((n) => n + 1)
      if (autoSend) setCountdown(AUTO_SEND_SECONDS)
    },
    [text, setText, autoSend],
  )

  // İmleç metnin sonuna
  useEffect(() => {
    if (cursorRev === 0) return
    const el = input.current
    if (!el) return
    el.focus()
    el.setSelectionRange(el.value.length, el.value.length)
  }, [cursorRev, input])

  // Otomatik gönder: geri sayım, herhangi bir tuş iptal eder.
  useEffect(() => {
    if (countdown === null) return
    const timer = window.setTimeout(() => {
      if (countdown > 1) {
        setCountdown(countdown - 1)
        return
      }
      setCountdown(null)
      submitRef.current()
    }, 1000)
    const cancel = () => setCountdown(null)
    window.addEventListener('keydown', cancel)
    window.addEventListener('pointerdown', cancel)
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('keydown', cancel)
      window.removeEventListener('pointerdown', cancel)
    }
  }, [countdown])

  /** Gönderirken çağrılır: mesajla gidecek ses bilgisi (sesten gelmediyse undefined) ve sıfırlama. */
  const consume = useCallback(
    (sent: string): VoiceMeta | undefined => {
      setCountdown(null)
      if (!meta) return undefined
      setMeta(null)
      return { transcription_id: meta.id, edited: sent.trim() !== meta.text.trim() }
    },
    [meta],
  )

  // Kutu boşaltılırsa etiket de kalkar.
  const fromVoice = meta !== null && text.trim() !== ''

  return { receive, consume, fromVoice, countdown }
}
