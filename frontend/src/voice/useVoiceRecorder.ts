import { useCallback, useEffect, useRef, useState } from 'react'
import { voiceApi } from './api'

export type RecorderStatus = 'idle' | 'recording' | 'transcribing' | 'error'

interface Options {
  maxSeconds: number
  /** Metin geldiğinde. Boş sonuçta (sessizlik) çağrılmaz; hata mesajı gösterilir. */
  onText: (text: string, transcriptionId: number) => void
}

interface StartOptions {
  /** Bas-konuş: tuş bırakılınca durur, sessizlikte otomatik durmaz. */
  holdToTalk?: boolean
}

interface Session {
  recorder: MediaRecorder | null
  stream: MediaStream | null
  audio: AudioContext | null
  timer: number | null
  cancelled: boolean
  stopRequested: boolean
  abort: AbortController | null
}

/** Bu seviyenin (RMS, 0-1) üstü konuşma sayılır. */
const SPEECH_LEVEL = 0.02
const SILENCE_MS = 1500
const ERROR_MS = 3000

function pickMimeType(): string | undefined {
  // Safari webm/opus kaydedemiyor; mp4'e düşülür.
  for (const type of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']) {
    if (MediaRecorder.isTypeSupported?.(type)) return type
  }
  return undefined
}

export function isRecordingSupported(): boolean {
  return typeof window !== 'undefined' && typeof window.MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
}

/**
 * Mikrofon kaydı → Whisper → metin. Kayıt sadece start() ile başlar, en fazla maxSeconds sürer.
 * Ses tarayıcıda bellekte tutulur, sadece Laravel'e gönderilir ve hemen bırakılır.
 */
export function useVoiceRecorder({ maxSeconds, onText }: Options) {
  const [status, setStatus] = useState<RecorderStatus>('idle')
  const [error, setError] = useState<string | null>(null)
  const [elapsed, setElapsed] = useState(0)
  const [level, setLevel] = useState(0)

  const onTextRef = useRef(onText)
  useEffect(() => {
    onTextRef.current = onText
  })

  const session = useRef<Session | null>(null)
  const errorTimer = useRef<number | null>(null)

  const release = useCallback(() => {
    const current = session.current
    if (!current) return
    if (current.timer !== null) window.clearInterval(current.timer)
    current.stream?.getTracks().forEach((track) => track.stop())
    current.audio?.close().catch(() => undefined)
    current.timer = null
    current.stream = null
    current.audio = null
    setLevel(0)
  }, [])

  const fail = useCallback((message: string) => {
    setStatus('error')
    setError(message)
    if (errorTimer.current !== null) window.clearTimeout(errorTimer.current)
    errorTimer.current = window.setTimeout(() => {
      setStatus('idle')
      setError(null)
    }, ERROR_MS)
  }, [])

  const stop = useCallback(() => {
    const current = session.current
    if (!current) return
    if (!current.recorder) {
      // Mikrofon izni beklenirken durduruldu (bas-konuş kısa basıldı).
      current.stopRequested = true
      return
    }
    if (current.recorder.state === 'recording') current.recorder.stop()
  }, [])

  const cancel = useCallback(() => {
    const current = session.current
    if (!current) return
    current.cancelled = true
    current.abort?.abort()
    if (current.recorder?.state === 'recording') current.recorder.stop()
    release()
    session.current = null
    setStatus('idle')
  }, [release])

  const start = useCallback(
    async ({ holdToTalk = false }: StartOptions = {}) => {
      if (session.current) return
      if (errorTimer.current !== null) window.clearTimeout(errorTimer.current)
      setError(null)
      const current: Session = { recorder: null, stream: null, audio: null, timer: null, cancelled: false, stopRequested: false, abort: null }
      session.current = current

      let stream: MediaStream
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } })
      } catch (e) {
        session.current = null
        const denied = (e as DOMException).name === 'NotAllowedError' || (e as DOMException).name === 'SecurityError'
        fail(denied ? 'Mikrofon izni gerekli. Adres çubuğundaki site ayarlarından mikrofona izin ver.' : 'Mikrofon açılamadı.')
        return
      }

      if (current.cancelled || (session.current as Session | null) !== current) {
        stream.getTracks().forEach((track) => track.stop())
        return
      }
      current.stream = stream

      const mimeType = pickMimeType()
      const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
      const chunks: Blob[] = []
      current.recorder = recorder
      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data)
      }
      recorder.onstop = async () => {
        release()
        if (current.cancelled) return

        const blob = new Blob(chunks, { type: recorder.mimeType || mimeType || 'audio/webm' })
        chunks.length = 0
        setStatus('transcribing')
        current.abort = new AbortController()

        try {
          const result = await voiceApi.transcribe(blob, current.abort.signal)
          if (current.cancelled) return
          session.current = null
          if (result.text.trim() === '') {
            fail('Ses anlaşılamadı, tekrar dene.')
            return
          }
          setStatus('idle')
          onTextRef.current(result.text, result.transcription_id)
        } catch (e) {
          if (current.cancelled) return
          session.current = null
          fail((e as Error).message)
        }
      }

      // Ses seviyesi (halka) ve sessizlikte otomatik durma
      let analyser: AnalyserNode | null = null
      try {
        const audio = new AudioContext()
        current.audio = audio
        analyser = audio.createAnalyser()
        analyser.fftSize = 1024
        audio.createMediaStreamSource(stream).connect(analyser)
      } catch {
        analyser = null
      }
      const samples = new Float32Array(1024)
      const startedAt = Date.now()
      let lastSpeech: number | null = null

      current.timer = window.setInterval(() => {
        const now = Date.now()
        setElapsed(Math.min(maxSeconds, Math.floor((now - startedAt) / 1000)))

        if (analyser) {
          analyser.getFloatTimeDomainData(samples)
          let sum = 0
          for (const sample of samples) sum += sample * sample
          const rms = Math.sqrt(sum / samples.length)
          setLevel(Math.min(1, rms * 8))
          if (rms > SPEECH_LEVEL) lastSpeech = now
        }

        if (now - startedAt >= maxSeconds * 1000) stop()
        else if (!holdToTalk && lastSpeech !== null && now - lastSpeech >= SILENCE_MS) stop()
      }, 100)

      setElapsed(0)
      setStatus('recording')
      recorder.start()
      if (current.stopRequested) stop()
    },
    [fail, maxSeconds, release, stop],
  )

  useEffect(
    () => () => {
      if (errorTimer.current !== null) window.clearTimeout(errorTimer.current)
      const current = session.current
      if (current) {
        current.cancelled = true
        current.abort?.abort()
        if (current.recorder?.state === 'recording') current.recorder.stop()
      }
      release()
    },
    [release],
  )

  return { status, error, elapsed, level, start, stop, cancel }
}
