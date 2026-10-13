import { useState, type FormEvent } from 'react'
import { HttpError, authApi } from '../auth/api'
import { useAuth } from '../auth/useAuth'
import { t } from '../i18n'
import { Logo } from '../ui/Logo'

const ERRORS: Record<string, string> = { no_access: 'login.error_no_access', entra_failed: 'login.error_entra_failed' }

/** Giriş ekranı. Hangi alanın yanlış olduğu söylenmez. Entra ID açıksa tek buton. */
export function LoginPage({ error: initialError }: { error?: string | null }) {
  const { config, setUser } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(initialError && ERRORS[initialError] ? t(ERRORS[initialError]) : null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setUser(await authApi.login(email.trim(), password))
    } catch (err) {
      setError(err instanceof HttpError && (err.status === 422 || err.status === 429) ? (err.status === 429 ? err.message : t('login.error_generic')) : t('login.error_unavailable'))
      setBusy(false)
    }
  }

  return (
    <main className="flex min-h-full items-center justify-center bg-canvas px-4 py-10">
      <div className="flex w-full max-w-[380px] flex-col items-center gap-6 rounded-2xl border border-line bg-surface px-7 py-9 shadow-[0_12px_40px_var(--shadow)]">
        <div className="flex flex-col items-center gap-2 text-center">
          <Logo size={36} />
          <h1 className="m-0 font-serif text-[32px] font-normal tracking-tight">{t('app.name')}</h1>
          <p className="m-0 text-sm text-muted">{t('login.subtitle')}</p>
        </div>

        {error && (
          <p role="alert" className="m-0 w-full rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}

        {config.driver === 'entra' ? (
          <a href="/api/auth/entra/redirect" className="flex h-11 w-full items-center justify-center rounded-lg bg-ink text-sm font-medium text-bg no-underline hover:opacity-90">
            {t('login.microsoft')}
          </a>
        ) : (
          <form onSubmit={submit} className="flex w-full flex-col gap-3.5">
            <label className="flex flex-col gap-1.5 text-[13px] font-medium text-ink-2">
              {t('login.email')}
              <input type="email" required autoComplete="username" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} className="h-10 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink" />
            </label>
            <label className="flex flex-col gap-1.5 text-[13px] font-medium text-ink-2">
              {t('login.password')}
              <input type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="h-10 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink" />
            </label>
            <button type="submit" disabled={busy} className="mt-1 h-10 rounded-lg bg-brand text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
              {busy ? t('login.submitting') : t('login.submit')}
            </button>
          </form>
        )}
      </div>
    </main>
  )
}
