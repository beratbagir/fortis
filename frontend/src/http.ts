// Laravel'e giden bütün istekler buradan geçer: oturum çerezi, CSRF başlığı ve oturum düşünce girişe dönüş.

/** Sanctum'un XSRF-TOKEN çerezi; yazan isteklerde X-XSRF-TOKEN başlığıyla geri gönderilir. */
function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

/** Oturum düştü (401) veya geçici şifre değiştirilmeli (403): AuthProvider dinler. */
export const UNAUTHORIZED_EVENT = 'fortis:unauthorized'
export const PASSWORD_CHANGE_EVENT = 'fortis:password-change-required'

export async function apiFetch(url: string, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers)
  if (!headers.has('Accept')) headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')

  const method = (init.method ?? 'GET').toUpperCase()
  const token = method === 'GET' || method === 'HEAD' ? null : xsrfToken()
  if (token) headers.set('X-XSRF-TOKEN', token)

  const response = await fetch(url, { ...init, headers, credentials: 'same-origin' })

  if (response.status === 401 && !url.startsWith('/api/auth/')) {
    window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
  }
  if (response.status === 403 && response.headers.get('Content-Type')?.includes('json')) {
    const body = await response.clone().json().catch(() => ({}))
    if (body.code === 'password_change_required') window.dispatchEvent(new Event(PASSWORD_CHANGE_EVENT))
  }

  return response
}

/** Girişten önce CSRF çerezini alır (Sanctum SPA akışı). */
export const csrfCookie = () => fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
