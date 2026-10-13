// Açık / koyu tema. Tercih tarayıcıda (ilk çizimde yanıp sönmesin diye, bkz. index.html) ve kullanıcıda saklanır.
import { useSyncExternalStore } from 'react'
import type { ThemePreference } from './auth/api'

export const THEME_KEY = 'fortis.theme'
const listeners = new Set<() => void>()
const media = () => window.matchMedia?.('(prefers-color-scheme: dark)')

export function storedTheme(): ThemePreference {
  try {
    const value = localStorage.getItem(THEME_KEY)
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch {
    return 'system'
  }
}

export const resolveTheme = (preference: ThemePreference): 'light' | 'dark' =>
  preference === 'system' ? (media()?.matches ? 'dark' : 'light') : preference

/** <html data-theme> niteliğini ayarlar ve tercihi tarayıcıda saklar. */
export function applyTheme(preference: ThemePreference) {
  document.documentElement.dataset.theme = resolveTheme(preference)
  try {
    localStorage.setItem(THEME_KEY, preference)
  } catch {
    // Gizli pencere vb.: tercih sadece bu oturumda geçerli.
  }
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  const query = media()
  const onSystemChange = () => {
    if (storedTheme() === 'system') applyTheme('system')
  }
  query?.addEventListener?.('change', onSystemChange)
  return () => {
    listeners.delete(listener)
    query?.removeEventListener?.('change', onSystemChange)
  }
}

/** Görünen tema (light/dark) ve tercih (system dahil). React Flow ve Monaco da buna uyar. */
export function useTheme(): { preference: ThemePreference; resolved: 'light' | 'dark' } {
  const preference = useSyncExternalStore(subscribe, storedTheme, () => 'system' as const)
  const resolved = useSyncExternalStore(subscribe, () => (document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'), () => 'light' as const)
  return { preference, resolved }
}
