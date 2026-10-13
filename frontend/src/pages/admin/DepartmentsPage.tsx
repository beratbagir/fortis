import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { adminApi, HttpError, type AdminDepartment } from '../../auth/api'
import { t } from '../../i18n'

const message = (err: unknown) => (err instanceof HttpError ? Object.values(err.errors).flat().join(' ') || err.message : t('error.generic'))

/** Departman yönetimi (admin): liste, ekle, yeniden adlandır. */
export function DepartmentsPage() {
  const queryClient = useQueryClient()
  const departments = useQuery({ queryKey: ['admin', 'departments'], queryFn: adminApi.departments })
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin'] })

  const add = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await adminApi.createDepartment(name.trim())
      setName('')
      refresh()
    } catch (err) {
      setError(message(err))
    }
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-2xl flex-col gap-5 px-6 py-8">
        <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">{t('admin.departments.title')}</h1>

        <form onSubmit={add} className="flex gap-2">
          <input required aria-label={t('admin.departments.name')} placeholder={t('admin.departments.name')} value={name} onChange={(e) => setName(e.target.value)} className="h-9 flex-1 rounded-lg border border-line-strong bg-surface px-2.5 text-sm text-ink" />
          <button type="submit" className="h-9 rounded-lg bg-brand px-3.5 text-sm font-medium text-on-brand hover:bg-brand-hover">
            {t('admin.departments.add')}
          </button>
        </form>
        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}

        <ul className="m-0 flex list-none flex-col rounded-xl border border-line bg-surface p-0">
          {departments.data?.map((d) => <DepartmentRow key={d.id} department={d} onSaved={refresh} />)}
        </ul>
      </div>
    </main>
  )
}

function DepartmentRow({ department, onSaved }: { department: AdminDepartment; onSaved: () => void }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(department.name)
  const [error, setError] = useState<string | null>(null)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await adminApi.renameDepartment(department.id, name.trim())
      setEditing(false)
      setError(null)
      onSaved()
    } catch (err) {
      setError(message(err))
    }
  }

  return (
    <li className="flex flex-col gap-1 border-b border-line px-4 py-3 last:border-b-0">
      {editing ? (
        <form onSubmit={save} className="flex items-center gap-2">
          <input autoFocus required aria-label={t('admin.departments.name')} value={name} onChange={(e) => setName(e.target.value)} className="h-8 flex-1 rounded-lg border border-line-strong bg-surface px-2.5 text-sm text-ink" />
          <button type="submit" className="h-8 rounded-lg bg-brand px-3 text-[13px] font-medium text-on-brand">
            {t('admin.departments.save')}
          </button>
          <button type="button" onClick={() => setEditing(false)} className="h-8 rounded-lg px-2 text-[13px] text-ink-2 hover:bg-raised">
            {t('admin.departments.cancel')}
          </button>
        </form>
      ) : (
        <div className="flex items-center gap-3">
          <span className="flex-1 text-sm font-medium">{department.name}</span>
          <span className="text-xs text-muted">{t('admin.departments.users', { count: department.users_count })}</span>
          <button type="button" onClick={() => setEditing(true)} className="rounded-md px-2 py-1 text-[13px] text-brand-fg hover:bg-raised">
            {t('admin.departments.rename')}
          </button>
        </div>
      )}
      {error && <span className="text-xs text-bad">{error}</span>}
    </li>
  )
}
