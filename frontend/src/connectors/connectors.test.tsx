import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ConnectorCard, ConnectorDetail } from '../api'
import { AuthContext, type AuthState } from '../auth/useAuth'
import { ConnectorsPage } from '../pages/ConnectorsPage'
import { setupText } from './format'

const card = (o: Partial<ConnectorCard>): ConnectorCard => ({
  key: 'apm', label: 'APM', abbr: 'AP', category: 'data', description: 'Uçuşlar ve rötarlar.', status: 'connected', detail: null, enabled: true,
  scopes: ['read', 'events'], last_checked_at: new Date().toISOString(), uses_this_week: 12, ...o,
})

const list = [
  card({}),
  card({ key: 'ibs', label: 'IBS', abbr: 'IB', status: 'attention', scopes: ['read'], uses_this_week: 0 }),
  card({ key: 'email', label: 'E-posta', abbr: 'EP', category: 'channel', status: 'not_configured', scopes: ['write·approval'], last_checked_at: null }),
  card({ key: 'airrm', label: 'AirRM', abbr: 'RM', category: 'setup', status: 'not_configured', scopes: [], last_checked_at: null }),
]

const detail: ConnectorDetail = {
  ...card({}), disabled: null, checks: [{ ok: true, at: new Date().toISOString(), duration_ms: 90 }, { ok: false, at: new Date().toISOString(), duration_ms: 120 }],
  avg_duration_ms: 105, last_error: { message: 'RuntimeException: https://[gizli]@apm.test', at: new Date().toISOString() }, checkable: true,
  tools: [{ name: 'query_apm', kind: 'read', label: 'APM sorgusu', description: 'Uçuş verisi.' }],
  used_by: [{ id: 5, name: 'Sabah Özeti', status: 'pilot', owner: 'Selin' }], chat_queries_7d: 14,
  protection: { masked_groups: ['Ad ve soyad', 'İletişim bilgileri'], rules: ['Mürettebat adları hiç okunmaz.'] }, needs: [],
  env: [{ name: 'APM_CLIENT_SECRET', defined: true }, { name: 'APM_PASSWORD', defined: false }], affected_agents: 3,
}

const auth = (admin: boolean): AuthState => ({
  config: { driver: 'password', locale: 'tr' } as AuthState['config'],
  user: { id: 1, name: 'K', email: 'k@x', role: admin ? 'admin' : 'user', abilities: { build: admin, admin } } as unknown as AuthState['user'],
  setUser: () => {}, logout: async () => {}, setTheme: () => {}, locale: 'tr', setLanguage: () => {},
})

let posts: { url: string; body: unknown }[] = []
let current: ConnectorDetail = detail

beforeEach(() => {
  posts = []
  current = detail
  vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => {
    if (init?.method === 'POST') {
      posts.push({ url, body: init.body ? JSON.parse(String(init.body)) : null })
      current = { ...detail, status: 'disabled', enabled: false, disabled: { reason: 'Bakım', by: 'K', at: null } }
      return Promise.resolve(new Response(JSON.stringify({ data: current })))
    }
    if (url === '/api/connectors') return Promise.resolve(new Response(JSON.stringify({ data: list, summary: { connected: 1, attention: 1, needs_setup: 2, disabled: 0 } })))
    return Promise.resolve(new Response(JSON.stringify({ data: url.endsWith('/airrm') ? { ...detail, ...list[3], needs: ['AirRM API erişimi'], env: null, checks: [] } : current })))
  }))
})
afterEach(() => vi.unstubAllGlobals())

const wrap = (key: string | null, admin = true) =>
  render(
    <AuthContext.Provider value={auth(admin)}>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ConnectorsPage connectorKey={key} />
      </QueryClientProvider>
    </AuthContext.Provider>,
  )

describe('bağlantı listesi', () => {
  it('özet, gruplar, durum rozetleri, kapsam etiketleri ve MCP boş durumu', async () => {
    wrap(null)
    expect(await screen.findByRole('link', { name: 'APM: Bağlı' })).toHaveAttribute('href', '#/connectors/apm')
    expect(screen.getByRole('link', { name: 'IBS: Dikkat' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'AirRM: Kurulum gerekli' })).toBeInTheDocument()
    expect(within(screen.getByRole('link', { name: 'APM: Bağlı' })).getByText('olaylar')).toBeInTheDocument()
    expect(within(screen.getByRole('link', { name: 'E-posta: Kurulum gerekli' })).getByText('yazma · onaylı')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Teslim kanalları' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Kanallar' }))
    expect(screen.queryByRole('link', { name: 'APM: Bağlı' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: 'MCP' }))
    expect(screen.getByText(/A6'da gelecek/)).toBeInTheDocument()
  })
})

describe('bağlantı detayı', () => {
  it('admin: kontrol geçmişi, araçlar, kullananlar, koruma, .env adları; kapatırken etkilenen agent sayısı ve gerekçe', async () => {
    wrap('apm')
    expect(await screen.findByLabelText('Son 24 saat kontroller')).toBeInTheDocument()
    expect(screen.getByText(/\[gizli\]/)).toBeInTheDocument()
    expect(screen.getByText('query_apm')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sabah Özeti' })).toHaveAttribute('href', '#/agents/5')
    expect(screen.getByText('İletişim bilgileri')).toBeInTheDocument()
    expect(screen.getByText(/APM_CLIENT_SECRET/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Kapat' }))
    const dialog = screen.getByRole('dialog', { name: 'APM kapatılsın mı?' })
    expect(within(dialog).getByText('3 agent')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Bağlantıyı kapat' })).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/Gerekçe/), { target: { value: 'Bakım' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Bağlantıyı kapat' }))
    await waitFor(() => expect(posts).toEqual([{ url: '/api/connectors/apm/disable', body: { reason: 'Bakım' } }]))
    expect(await screen.findByText(/Şirket genelinde kapalı: Bakım/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aç' })).toBeInTheDocument()
  })

  it('kullanıcı: admin düğmeleri ve .env yok; kurulum gerekenlerde IT metni', async () => {
    wrap('apm', false)
    await screen.findByText('query_apm')
    expect(screen.queryByRole('button', { name: 'Kapat' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Şimdi kontrol et' })).not.toBeInTheDocument()

    const text = setupText({ label: 'AirRM', description: 'Gelir yönetimi.', needs: ['AirRM API erişimi'] })
    expect(text).toContain('- AirRM API erişimi')
    expect(text).toContain('.env')
  })
})
