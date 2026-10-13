import { useSyncExternalStore } from 'react'

// Kullanıcı sistemi yok; tercih bu tarayıcıda saklanır.
const KEY = 'fortis.voice.autoSend'
const listeners = new Set<() => void>()

function read(): boolean {
  try {
    return localStorage.getItem(KEY) === '1'
  } catch {
    return false
  }
}

export function setAutoSend(value: boolean): void {
  try {
    localStorage.setItem(KEY, value ? '1' : '0')
  } catch {
    // Saklanamazsa varsayılan (kapalı) geçerli.
  }
  listeners.forEach((listener) => listener())
}

/** "Sesli komutu otomatik gönder" (varsayılan kapalı). */
export function useAutoSend(): boolean {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    read,
    () => false,
  )
}
