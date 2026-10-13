import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import type { User } from './auth/api'

const user = (overrides: Partial<User> = {}): User => ({
  id: 1, name: 'Kerem B.', email: 'kerem@freebird.test', title: null, role: 'admin', avatar_color: 'crimson', is_active: true,
  must_change_password: false, theme: 'system', last_login_at: null, department: { id: 1, name: 'Commercial', slug: 'commercial' },
  abilities: { build: true, admin: true }, ...overrides,
})

const json = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))

/** Sahte Laravel: oturum `session.user` ile tutulur. */
function fakeBackend(session: { user: User | null }, config: { show_coming_soon?: boolean } = {}) {
  const calls: { url: string; method: string; body: unknown }[] = []
  const fetchMock = vi.fn((url: string, init: RequestInit = {}) => {
    const method = init.method ?? 'GET'
    calls.push({ url, method, body: init.body ? JSON.parse(String(init.body)) : null })

    if (url === '/sanctum/csrf-cookie') return Promise.resolve(new Response(null, { status: 204 }))
    if (url === '/api/auth/config') return json({ data: { driver: 'password', locale: 'tr', ...config } })
    if (url === '/api/auth/me') return session.user ? json({ data: session.user }) : json({ message: 'Unauthenticated.' }, 401)
    if (url === '/api/auth/login') {
      session.user = user()
      return json({ data: session.user })
    }
    if (url === '/api/me/preferences') return json({ data: { ...session.user, ...(init.body ? JSON.parse(String(init.body)) : {}) } })
    if (url.startsWith('/api/conversations')) return json({ data: [] })
    if (url.startsWith('/api/usage/summary')) return json({ data: { cost_usd: 1.5, budget: { limit: null, spent: 1.5, ratio: null } } })
    if (url === '/api/agents') return json({ data: { counts: { live: 0, pilot: 0, draft: 0, paused: 0, template: 0 }, agents: [], templates: [] } })
    if (url.startsWith('/api/flows')) return json({ data: [], unmanaged: [], kestra_error: null })
    if (url === '/api/inbox/count') return json({ data: { action: 3 } })
    if (url.startsWith('/api/executions')) return json({ data: [], total: 0, counts: { paused: 0, failed: 0 }, warning: null })
    return json({ message: `tanımsız: ${method} ${url}` }, 404)
  })
  vi.stubGlobal('fetch', fetchMock)
  return calls
}

const renderApp = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <App />
    </QueryClientProvider>,
  )

beforeEach(() => {
  // jsdom'da yok; sohbet ekranı son mesaja kaydırır.
  Element.prototype.scrollIntoView = vi.fn()
  localStorage.clear()
  document.documentElement.removeAttribute('data-theme')
})

afterEach(() => {
  vi.unstubAllGlobals()
  window.location.hash = ''
})

describe('giriş', () => {
  it('girişsiz kullanıcı #/login\'e gider, girişten sonra istediği sayfaya döner', async () => {
    const calls = fakeBackend({ user: null })
    window.location.hash = '#/agents'
    renderApp()

    await screen.findByRole('button', { name: 'Giriş' })
    await waitFor(() => expect(window.location.hash).toBe('#/login?next=%23%2Fagents'))

    fireEvent.change(screen.getByLabelText('E-posta'), { target: { value: 'kerem@freebird.test' } })
    fireEvent.change(screen.getByLabelText('Şifre'), { target: { value: 'dogru-sifre-123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Giriş' }))

    await waitFor(() => expect(window.location.hash).toBe('#/agents'))
    expect(await screen.findByRole('heading', { name: 'Agent Kaydı' })).toBeInTheDocument()
    expect(calls.map((c) => c.url)).toContain('/sanctum/csrf-cookie')
    expect(calls.find((c) => c.url === '/api/auth/login')?.body).toEqual({ email: 'kerem@freebird.test', password: 'dogru-sifre-123' })
  })
})

describe('tema', () => {
  it('Açık / Koyu düğmesi data-theme niteliğini değiştirir ve tercihi kaydeder', async () => {
    const calls = fakeBackend({ user: user({ theme: 'light' }) })
    window.location.hash = '#/chat'
    renderApp()

    const theme = await screen.findByRole('group', { name: 'Tema' })
    await waitFor(() => expect(document.documentElement.dataset.theme).toBe('light'))

    fireEvent.click(within(theme).getByRole('button', { name: /Koyu/ }))

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(localStorage.getItem('fortis.theme')).toBe('dark')
    await waitFor(() => expect(calls.find((c) => c.url === '/api/me/preferences')).toMatchObject({ method: 'PATCH', body: { theme: 'dark' } }))
    expect(within(theme).getByRole('button', { name: /Koyu/ })).toHaveAttribute('aria-pressed', 'true')
  })
})

describe('kabuk', () => {
  it('"Yakında" öğeleri varsayılan olarak menüde yok; adresle açılan sayfa boş durumu gösterir', async () => {
    fakeBackend({ user: user() })
    window.location.hash = '#/chat'
    renderApp()

    const menu = await screen.findByRole('navigation', { name: 'Ana menü' })
    expect(within(menu).getByRole('link', { name: /Agentlar/ })).toBeInTheDocument()
    for (const name of [/Yapılacaklar/, /Çıktılar/, /Paylaşılan agentlar/, /Bellek/, /Bilgi/, /Beceriler/]) {
      expect(within(menu).queryByRole('link', { name })).not.toBeInTheDocument()
    }
    expect(within(menu).queryByText('Yakında')).not.toBeInTheDocument()

    window.location.hash = '#/memory'
    window.dispatchEvent(new HashChangeEvent('hashchange'))
    expect(await screen.findByText('Bu ekran sonraki fazda gelecek.')).toBeInTheDocument()
  })

  it('ayar açıksa "Yakında" öğeleri boş durum sayfasını açar', async () => {
    fakeBackend({ user: user() }, { show_coming_soon: true })
    window.location.hash = '#/chat'
    renderApp()

    const menu = await screen.findByRole('navigation', { name: 'Ana menü' })
    const todo = within(menu).getByRole('link', { name: /Yapılacaklar/ })
    expect(todo).toHaveAttribute('href', '#/actions')
    expect(within(todo).getByText('Yakında')).toBeInTheDocument()

    // Gelen kutusu artık gerçek ekran: bekleyen sayısı rozette.
    const inbox = within(menu).getByRole('link', { name: /Gelen kutusu/ })
    expect(inbox).toHaveAttribute('href', '#/inbox')
    expect(await within(inbox).findByText('3')).toBeInTheDocument()
    expect(within(inbox).queryByText('Yakında')).not.toBeInTheDocument()

    window.location.hash = '#/actions'
    window.dispatchEvent(new HashChangeEvent('hashchange'))

    expect(await screen.findByRole('heading', { name: 'Yapılacaklar' })).toBeInTheDocument()
    expect(screen.getByText('Bu ekran sonraki fazda gelecek.')).toBeInTheDocument()
  })

  it('user rolünde "Oluştur" ve admin menüsü görünmez', async () => {
    fakeBackend({ user: user({ role: 'user', abilities: { build: false, admin: false } }) })
    window.location.hash = '#/chat'
    renderApp()

    const menu = await screen.findByRole('navigation', { name: 'Ana menü' })
    expect(within(menu).getByRole('link', { name: /Agentlar/ })).toBeInTheDocument()
    expect(within(menu).queryByRole('link', { name: /Oluştur/ })).not.toBeInTheDocument()

    fireEvent.click(within(menu).getByRole('button', { name: 'Kullanıcı menüsü' }))
    const userMenu = screen.getByRole('menu')
    expect(within(userMenu).getByRole('menuitem', { name: /Profil/ })).toBeInTheDocument()
    expect(within(userMenu).queryByRole('menuitem', { name: /Kullanıcılar/ })).not.toBeInTheDocument()
    expect(within(userMenu).queryByRole('menuitem', { name: /Departmanlar/ })).not.toBeInTheDocument()
  })

  it('admin "Oluştur"u ve yönetim menüsünü görür', async () => {
    fakeBackend({ user: user() })
    window.location.hash = '#/chat'
    renderApp()

    const menu = await screen.findByRole('navigation', { name: 'Ana menü' })
    expect(within(menu).getByRole('link', { name: /Oluştur/ })).toHaveAttribute('href', '#/build')

    fireEvent.click(within(menu).getByRole('button', { name: 'Kullanıcı menüsü' }))
    expect(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Kullanıcılar/ })).toHaveAttribute('href', '#/admin/users')
  })
})
