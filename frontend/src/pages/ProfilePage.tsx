import { useState, type FormEvent } from 'react'
import { HttpError, authApi } from '../auth/api'
import { useAuth } from '../auth/useAuth'
import { dateTime, t } from '../i18n'
import { Avatar } from '../ui/Logo'

const MIN_PASSWORD = 10

/** Profil: kullanıcı bilgileri ve şifre değiştirme. */
export function ProfilePage() {
  const { user } = useAuth()
  if (!user) return null

  const rows: [string, string][] = [
    [t('profile.department'), user.department?.name ?? t('profile.none')],
    [t('profile.role'), t(`role.${user.role}`)],
    [t('profile.job_title'), user.title ?? t('profile.none')],
    [t('profile.last_login'), user.last_login_at ? dateTime(user.last_login_at) : t('profile.none')],
  ]

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-2xl flex-col gap-6 px-6 py-8">
        <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">{t('profile.title')}</h1>
        <section className="flex items-center gap-4 rounded-xl border border-line bg-surface p-5">
          <Avatar name={user.name} color={user.avatar_color} size={48} />
          <div className="flex flex-col">
            <span className="text-lg font-medium">{user.name}</span>
            <span className="text-sm text-muted">{user.email}</span>
          </div>
        </section>
        <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2.5 rounded-xl border border-line bg-surface p-5 text-sm">
          {rows.map(([label, value]) => (
            <div key={label} className="contents">
              <dt className="text-muted">{label}</dt>
              <dd className="m-0 text-ink">{value}</dd>
            </div>
          ))}
        </dl>
        <section className="rounded-xl border border-line bg-surface p-5">
          <PasswordForm />
        </section>
      </div>
    </main>
  )
}

/** Şifre değiştirme. Geçici şifreyle giren kullanıcıya uygulamadan önce gösterilir. */
export function PasswordForm({ required = false }: { required?: boolean }) {
  const { setUser } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ text: string; ok: boolean } | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setMessage(null)
    try {
      const user = await authApi.changePassword(current, next, confirm)
      setCurrent('')
      setNext('')
      setConfirm('')
      setMessage({ text: t('password.saved'), ok: true })
      setUser(user)
    } catch (err) {
      const errors = err instanceof HttpError ? Object.values(err.errors).flat() : []
      setMessage({ text: errors.join(' ') || (err as Error).message || t('error.generic'), ok: false })
    } finally {
      setBusy(false)
    }
  }

  const field = 'h-10 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink'

  return (
    <form onSubmit={submit} className="flex flex-col gap-3.5">
      <h2 className="m-0 text-base font-medium">{t('password.title')}</h2>
      {required && <p className="m-0 text-sm text-ink-2">{t('password.required_note')}</p>}
      <label className="flex flex-col gap-1.5 text-[13px] font-medium text-ink-2">
        {t('password.current')}
        <input type="password" required autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} className={field} />
      </label>
      <label className="flex flex-col gap-1.5 text-[13px] font-medium text-ink-2">
        {t('password.new')}
        <input type="password" required minLength={MIN_PASSWORD} autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} className={field} />
        <span className="text-xs font-normal text-muted">{t('password.hint', { min: MIN_PASSWORD })}</span>
      </label>
      <label className="flex flex-col gap-1.5 text-[13px] font-medium text-ink-2">
        {t('password.confirm')}
        <input type="password" required minLength={MIN_PASSWORD} autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} className={field} />
      </label>
      {message && (
        <p role={message.ok ? 'status' : 'alert'} className={`m-0 rounded-lg px-3 py-2 text-sm ${message.ok ? 'bg-ok-soft text-ok' : 'bg-bad-soft text-bad'}`}>
          {message.text}
        </p>
      )}
      <button type="submit" disabled={busy} className="h-10 self-start rounded-lg bg-brand px-4 text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
        {t('password.submit')}
      </button>
    </form>
  )
}
