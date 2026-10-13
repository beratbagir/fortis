import { createContext, useContext } from 'react'
import type { Locale } from '../i18n'
import type { AuthConfig, ThemePreference, User } from './api'

export interface AuthState {
  config: AuthConfig
  user: User | null
  setUser: (user: User | null) => void
  logout: () => Promise<void>
  setTheme: (theme: ThemePreference) => void
  /** Arayüz dili (kullanıcı başına; girişsiz tarayıcı dili). */
  locale: Locale
  setLanguage: (locale: Locale) => void
}

export const AuthContext = createContext<AuthState | null>(null)

/** Oturumdaki kullanıcı ve giriş işlemleri. AuthProvider içinde kullanılır. */
export function useAuth(): AuthState {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth AuthProvider içinde kullanılmalı')
  return value
}
