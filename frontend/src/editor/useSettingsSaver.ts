import { useCallback, useEffect, useRef, useState } from 'react'
import { SettingsQueue } from './queue'
import type { Operation } from './types'

/** Form değişikliklerinin gönderilmeden önce beklediği süre. */
export const SETTINGS_DELAY_MS = 600

type SettingsOp = Extract<Operation, { op: 'update_settings' | 'update_trigger' }>

/**
 * Ayar değişikliklerini biriktirir; son değişiklikten 600 ms sonra tek istekte gönderir. Yapısal bir işlemden
 * (ekleme, silme) önce flush() çağrılır ki sıra korunsun.
 */
export function useSettingsSaver(send: (ops: Operation[]) => void, delay = SETTINGS_DELAY_MS) {
  const queue = useRef(new SettingsQueue())
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const sendRef = useRef(send)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    sendRef.current = send
  }, [send])

  const flush = useCallback(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
    const ops = queue.current.take()
    setPending(false)
    if (ops.length > 0) sendRef.current(ops)
  }, [])

  const schedule = useCallback(
    (op: SettingsOp) => {
      queue.current.push(op)
      setPending(true)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(flush, delay)
    },
    [delay, flush],
  )

  // Sayfadan çıkarken bekleyen değişiklik kaybolmasın.
  useEffect(() => () => flush(), [flush])

  return { schedule, flush, pending }
}
