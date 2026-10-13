import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Fragment, useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { PASSWORD_CHANGE_EVENT, UNAUTHORIZED_EVENT } from '../http'
import { setLocale, t, type Locale } from '../i18n'
import { applyTheme, storedTheme } from '../theme'
import { authApi, type User } from './api'
import { AuthContext, type AuthState } from './useAuth'

/** Uygulama açılırken giriş ayarını ve oturumdaki kullanıcıyı yükler. Oturum düşerse kullanıcı null olur. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const config = useQuery({ queryKey: ['auth', 'config'], queryFn: authApi.config, staleTime: Infinity })
  const me = useQuery({ queryKey: ['auth', 'me'], queryFn: authApi.me, staleTime: Infinity, retry: false })

  // Dil: kullanıcının tercihi, girişsiz sunucunun bildirdiği (tarayıcı dili), yoksa İngilizce.
  const locale: Locale = (me.data?.locale ?? config.data?.locale) === 'tr' ? 'tr' : 'en'
  setLocale(locale)

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
      locale,
      setLanguage: (next) => {
        if (next === locale) return
        setLocale(next)
        if (me.data) {
          setUser({ ...me.data, locale: next })
          authApi.setLocale(next).then(() => queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })).catch(() => undefined)
        } else {
          queryClient.setQueryData(['auth', 'config'], { ...config.data!, locale: next })
        }
      },
      setTheme: (theme) => {
        applyTheme(theme)
        if (me.data) {
          setUser({ ...me.data, theme })
          authApi.setTheme(theme).catch(() => undefined)
        }
      },
    }
  }, [config.data, me.data, setUser, signOut, locale, queryClient])

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

  // Dil değişince ağaç yeniden kurulur: t() çağrıları yeni dilde çalışır.
  return (
    <AuthContext.Provider value={value}>
      <Fragment key={value.locale}>{children}</Fragment>
    </AuthContext.Provider>
  )
}
