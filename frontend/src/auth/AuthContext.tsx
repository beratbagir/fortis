import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { PASSWORD_CHANGE_EVENT, UNAUTHORIZED_EVENT } from '../http'
import { setLocale, t } from '../i18n'
import { applyTheme, storedTheme } from '../theme'
import { authApi, type User } from './api'
import { AuthContext, type AuthState } from './useAuth'

/** Uygulama açılırken giriş ayarını ve oturumdaki kullanıcıyı yükler. Oturum düşerse kullanıcı null olur. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const config = useQuery({ queryKey: ['auth', 'config'], queryFn: authApi.config, staleTime: Infinity })
  const me = useQuery({ queryKey: ['auth', 'me'], queryFn: authApi.me, staleTime: Infinity, retry: false })

  if (config.data) setLocale(config.data.locale)

  const setUser = useCallback((user: User | null) => queryClient.setQueryData(['auth', 'me'], user), [queryClient])

  /** Oturum kapanınca başka kullanıcının verisi ekranda kalmasın; giriş sorguları (izlenen) korunur. */
  const signOut = useCallback(() => {
    setUser(null)
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
  }, [queryClient, setUser])

  // Sunucudaki tema tercihi, tarayıcıdakinden farklıysa (başka cihazda değiştirilmiş) uygulanır.
  useEffect(() => {
    if (me.data && me.data.theme !== storedTheme()) applyTheme(me.data.theme)
  }, [me.data])

  useEffect(() => {
    const onUnauthorized = signOut
    const onPasswordChange = () => me.refetch()
    window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized)
    window.addEventListener(PASSWORD_CHANGE_EVENT, onPasswordChange)
    return () => {
      window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized)
      window.removeEventListener(PASSWORD_CHANGE_EVENT, onPasswordChange)
    }
  }, [signOut, me])

  const value = useMemo<AuthState | null>(() => {
    if (!config.data) return null
    return {
      config: config.data,
      user: me.data ?? null,
      setUser,
      logout: async () => {
        await authApi.logout().catch(() => undefined)
        signOut()
      },
      setTheme: (theme) => {
        applyTheme(theme)
        if (me.data) {
          setUser({ ...me.data, theme })
          authApi.setTheme(theme).catch(() => undefined)
        }
      },
    }
  }, [config.data, me.data, setUser, signOut])

  if (config.isError || me.isError) {
    return (
      <div role="alert" className="flex h-full items-center justify-center p-8 text-sm text-bad">
        {t('login.error_unavailable')}
      </div>
    )
  }

  if (!value || me.isPending) {
    return <p className="p-8 text-muted">{t('app.loading')}</p>
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
