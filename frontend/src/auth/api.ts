// Giriş, oturumdaki kullanıcı ve yönetim (kullanıcılar, departmanlar) uçları.
import { apiFetch, csrfCookie } from '../http'
import type { Locale } from '../i18n'

export type UserRole = 'admin' | 'builder' | 'user'
export type ThemePreference = 'system' | 'light' | 'dark'
export type AvatarColor = 'crimson' | 'blue' | 'green' | 'amber' | 'violet' | 'slate'

export interface Department {
  id: number
  name: string
  slug: string
}

export interface User {
  id: number
  name: string
  email: string
  title: string | null
  role: UserRole
  avatar_color: AvatarColor | null
  is_active: boolean
  must_change_password: boolean
  theme: ThemePreference
  last_login_at: string | null
  department: Department | null
  abilities: { build: boolean; admin: boolean }
}

export interface AuthConfig {
  driver: 'password' | 'entra'
  locale: Locale
  /** Henüz yapılmamış ekranlar menüde "Yakında" olarak görünsün mü (sunucuda FORTIS_SHOW_COMING_SOON). */
  show_coming_soon?: boolean
}

export class HttpError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    ...init,
    headers: { 'Content-Type': 'application/json', ...init.headers },
  })
  const json = response.status === 204 ? {} : await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new HttpError(response.status, json.message ?? `İstek başarısız (${response.status})`, json.errors ?? {})
  }

  return json as T
}

const send = <T>(method: string, path: string, body?: unknown) => call<T>(path, { method, body: body === undefined ? undefined : JSON.stringify(body) })

export const authApi = {
  config: () => call<{ data: AuthConfig }>('/auth/config').then((r) => r.data),
  /** Oturum yoksa null. */
  me: async (): Promise<User | null> => {
    try {
      return (await call<{ data: User }>('/auth/me')).data
    } catch (e) {
      if (e instanceof HttpError && e.status === 401) return null
      throw e
    }
  },
  login: async (email: string, password: string) => {
    await csrfCookie()
    return (await send<{ data: User }>('POST', '/auth/login', { email, password })).data
  },
  logout: () => send<void>('POST', '/auth/logout'),
  setTheme: (theme: ThemePreference) => send<{ data: User }>('PATCH', '/me/preferences', { theme }).then((r) => r.data),
  changePassword: (current_password: string, password: string, password_confirmation: string) =>
    send<{ data: User }>('POST', '/me/password', { current_password, password, password_confirmation }).then((r) => r.data),
}

export interface AdminDepartment extends Department {
  users_count: number
}

export interface UserInput {
  name?: string
  email?: string
  role?: UserRole
  department_id?: number | null
  title?: string | null
  is_active?: boolean
  reset_password?: boolean
}

export const adminApi = {
  users: () => call<{ data: User[] }>('/admin/users').then((r) => r.data),
  createUser: (input: UserInput) => send<{ data: User; temporary_password: string | null }>('POST', '/admin/users', input),
  updateUser: (id: number, input: UserInput) => send<{ data: User; temporary_password: string | null }>('PATCH', `/admin/users/${id}`, input),
  departments: () => call<{ data: AdminDepartment[] }>('/admin/departments').then((r) => r.data),
  createDepartment: (name: string) => send<{ data: AdminDepartment }>('POST', '/admin/departments', { name }).then((r) => r.data),
  renameDepartment: (id: number, name: string) => send<{ data: AdminDepartment }>('PATCH', `/admin/departments/${id}`, { name }).then((r) => r.data),
}
