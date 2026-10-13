import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { adminApi, HttpError, type User, type UserInput, type UserRole } from '../../auth/api'
import { useAuth } from '../../auth/useAuth'
import { dateTime, t } from '../../i18n'
import { Dialog } from '../../ui/Dialog'
import { Avatar } from '../../ui/Logo'

const ROLES: UserRole[] = ['user', 'builder', 'admin']

/** Kullanıcı yönetimi (admin): liste, ekle, düzenle, pasif yap. Silme yok. */
export function UsersPage() {
  const { user: me } = useAuth()
  const queryClient = useQueryClient()
  const users = useQuery({ queryKey: ['admin', 'users'], queryFn: adminApi.users })
  const [editing, setEditing] = useState<User | 'new' | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin'] })
  const toggle = useMutation({
    mutationFn: (u: User) => adminApi.updateUser(u.id, { is_active: !u.is_active }),
    onSuccess: refresh,
    onError: (e: Error) => setNotice(e.message),
  })

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-5xl flex-col gap-5 px-6 py-8">
        <div className="flex items-center gap-3">
          <h1 className="m-0 flex-1 font-serif text-[30px] font-normal tracking-tight">{t('admin.users.title')}</h1>
          <button type="button" onClick={() => setEditing('new')} className="h-9 rounded-lg bg-brand px-3.5 text-sm font-medium text-on-brand hover:bg-brand-hover">
            {t('admin.users.add')}
          </button>
        </div>

        {notice && (
          <p role="status" className="m-0 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink-2">
            {notice}
          </p>
        )}

        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full border-collapse text-left text-[13px]">
            <thead className="text-xs text-muted">
              <tr>
                {[t('admin.users.name'), t('admin.users.department'), t('admin.users.role'), t('admin.users.last_login'), t('admin.users.status'), ''].map((h, i) => (
                  <th key={i} className="border-b border-line px-4 py-2.5 font-medium">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {users.data?.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-muted">
                    {t('admin.users.empty')}
                  </td>
                </tr>
              )}
              {users.data?.map((u) => (
                <tr key={u.id} className={u.is_active ? '' : 'text-muted'}>
                  <td className="border-b border-line px-4 py-2.5">
                    <div className="flex items-center gap-2.5">
                      <Avatar name={u.name} color={u.avatar_color} />
                      <div className="flex min-w-0 flex-col">
                        <span className="font-medium text-ink">{u.name}</span>
                        <span className="text-xs text-muted">{u.email}</span>
                      </div>
                    </div>
                  </td>
                  <td className="border-b border-line px-4 py-2.5">{u.department?.name ?? '—'}</td>
                  <td className="border-b border-line px-4 py-2.5">{t(`role.${u.role}`)}</td>
                  <td className="border-b border-line px-4 py-2.5 font-mono text-xs">{u.last_login_at ? dateTime(u.last_login_at) : t('admin.users.never')}</td>
                  <td className="border-b border-line px-4 py-2.5">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${u.is_active ? 'bg-ok-soft text-ok' : 'bg-raised text-muted'}`}>{u.is_active ? t('admin.users.active') : t('admin.users.inactive')}</span>
                  </td>
                  <td className="border-b border-line px-4 py-2.5 text-right whitespace-nowrap">
                    <button type="button" onClick={() => setEditing(u)} className="rounded-md px-2 py-1 text-brand-fg hover:bg-raised">
                      {t('admin.users.edit_short')}
                    </button>
                    {u.id !== me?.id && (
                      <button type="button" disabled={toggle.isPending} onClick={() => toggle.mutate(u)} className="rounded-md px-2 py-1 text-ink-2 hover:bg-raised">
                        {u.is_active ? t('admin.users.deactivate') : t('admin.users.activate')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {editing && (
        <UserDialog
          user={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(temporary) => {
            setEditing(null)
            setNotice(temporary ? t('admin.users.temporary_password', { password: temporary }) : null)
            refresh()
          }}
        />
      )}
    </main>
  )
}

function UserDialog({ user, onClose, onSaved }: { user: User | null; onClose: () => void; onSaved: (temporaryPassword: string | null) => void }) {
  const departments = useQuery({ queryKey: ['admin', 'departments'], queryFn: adminApi.departments })
  const [form, setForm] = useState<UserInput>({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'user',
    department_id: user?.department?.id ?? null,
    title: user?.title ?? '',
  })
  const [resetPassword, setResetPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const input = { ...form, title: form.title || null, ...(resetPassword ? { reset_password: true } : {}) }
      const result = user ? await adminApi.updateUser(user.id, input) : await adminApi.createUser(input)
      onSaved(result.temporary_password)
    } catch (err) {
      setError(err instanceof HttpError ? Object.values(err.errors).flat().join(' ') || err.message : t('error.generic'))
      setBusy(false)
    }
  }

  const field = 'h-9 rounded-lg border border-line-strong bg-surface px-2.5 text-sm text-ink'
  const label = 'flex flex-col gap-1.5 text-[13px] font-medium text-ink-2'

  return (
    <Dialog title={user ? t('admin.users.edit') : t('admin.users.add')} onClose={onClose}>
      <form onSubmit={submit} className="flex flex-col gap-3.5">
        <label className={label}>
          {t('admin.users.name')}
          <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={field} />
        </label>
        <label className={label}>
          {t('admin.users.email')}
          <input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={field} />
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className={label}>
            {t('admin.users.department')}
            <select value={form.department_id ?? ''} onChange={(e) => setForm({ ...form, department_id: e.target.value ? Number(e.target.value) : null })} className={field}>
              <option value="">{t('admin.users.no_department')}</option>
              {departments.data?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </label>
          <label className={label}>
            {t('admin.users.role')}
            <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })} className={field}>
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {t(`role.${r}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <label className={label}>
          {t('admin.users.title_field')}
          <input value={form.title ?? ''} onChange={(e) => setForm({ ...form, title: e.target.value })} className={field} />
        </label>
        {user && (
          <label className="flex items-center gap-2 text-[13px] text-ink-2">
            <input type="checkbox" checked={resetPassword} onChange={(e) => setResetPassword(e.target.checked)} />
            {t('admin.users.reset_password')}
          </label>
        )}
        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="h-9 rounded-lg border border-line px-3.5 text-sm text-ink-2 hover:bg-raised">
            {t('admin.users.cancel')}
          </button>
          <button type="submit" disabled={busy} className="h-9 rounded-lg bg-brand px-3.5 text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
            {t('admin.users.save')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
