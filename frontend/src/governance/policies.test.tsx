import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuditLogPage, PoliciesData } from '../api'
import { GovernanceAuditView } from './AuditLog'
import { GovernancePoliciesView } from './Policies'

const policies: PoliciesData = {
  ai_data_access: 'masked',
  ai_allow_employee_names: true,
  allowed_models: ['claude-sonnet-5-5', 'claude-opus-5-5'],
  default_model: 'claude-sonnet-5-5',
  data_question_model: 'claude-opus-5-5',
  fast_model: 'claude-sonnet-5-5',
  page_model: 'claude-sonnet-5-5',
  budget_blocks_flow_ai_steps: false,
  allow_external_recipients: false,
  allowed_recipient_domains: ['freebird.com.tr'],
  max_tool_calls: 20,
  max_cost_usd: 2,
  max_seconds: 600,
  model_catalog: [
    { id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5', strength: 'Dengeli', input_per_mtok: 2, output_per_mtok: 10 },
    { id: 'claude-opus-5-5', label: 'Claude Opus 5.5', strength: 'Derin analiz', input_per_mtok: 4, output_per_mtok: 20 },
  ],
  max_rows: 200,
  max_chars: 40000,
  approval_policy: [{ tool: 'send_email', label: 'E-posta gönder', mode: 'always' }],
  company_budget: { monthly_limit_usd: 50, warn_at_percent: 80, hard_stop: true },
}

const auditPage = (cursor: string | null): AuditLogPage => ({
  data: cursor
    ? [{ id: 1, at: '2026-10-11T09:00:00+00:00', actor_type: 'system', actor: 'Sistem', action: 'auth.login_failed', subject: null, system: null, result: 'failed', cost_usd: null, meta: null }]
    : [
        {
          id: 2,
          at: '2026-10-12T09:00:00+00:00',
          actor_type: 'user',
          actor: 'Kerem',
          action: 'settings.update',
          subject: null,
          system: null,
          result: 'allowed',
          cost_usd: null,
          meta: { setting: 'agents.max_tool_calls', before: 20, after: 8 },
        },
      ],
  next_cursor: cursor ? null : 'abc',
  options: { actions: ['agent.run.start', 'auth.login_failed', 'settings.update'], users: [{ id: 1, name: 'Kerem' }] },
})

let requests: { url: string; method: string; body: unknown }[] = []

beforeEach(() => {
  requests = []
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      requests.push({ url, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null })
      if (url.startsWith('/api/audit-logs')) return Promise.resolve(new Response(JSON.stringify(auditPage(new URL(url, 'http://x').searchParams.get('cursor')))))
      const data = url.startsWith('/api/governance/policies') ? policies : url.startsWith('/api/connectors') ? [] : url.startsWith('/api/governance/budgets') ? null : null
      return Promise.resolve(new Response(JSON.stringify({ data })))
    }),
  )
})
afterEach(() => vi.unstubAllGlobals())

const wrap = (ui: React.ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>)

describe('yönetişim politikalar', () => {
  it('değişiklikler önceki → yeni olarak onaylatılır ve sadece değişen alanlar gönderilir', async () => {
    wrap(<GovernancePoliciesView />)
    const agents = await screen.findByRole('region', { name: 'Agentlar' })
    expect(within(agents).getByText(/her zaman onay/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Kaydet' })).toBeDisabled()

    fireEvent.change(within(agents).getByLabelText(/araç çağrısı sınırı/), { target: { value: '8' } })
    fireEvent.change(within(agents).getByLabelText(/Şirket içi alan adları/), { target: { value: 'freebird.com.tr, Gozendigital.com' } })
    fireEvent.click(within(screen.getByRole('region', { name: 'Veri' })).getByRole('radio', { name: /^Kapalı/ }))
    expect(screen.getByText('3 kaydedilmemiş değişiklik')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))
    const dialog = screen.getByRole('dialog', { name: 'Politika değişiklikleri kaydedilsin mi?' })
    expect(dialog).toHaveTextContent('AI veri erişimi: Maskeli → Kapalı')
    expect(dialog).toHaveTextContent('Çalışma başına araç çağrısı sınırı: 20 → 8')
    expect(dialog).toHaveTextContent('Şirket içi alan adları: freebird.com.tr → freebird.com.tr, gozendigital.com')
    expect(requests.some((r) => r.method === 'PUT')).toBe(false)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Onayla ve kaydet' }))
    await waitFor(() => expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({ ai_data_access: 'off', allowed_recipient_domains: ['freebird.com.tr', 'gozendigital.com'], max_tool_calls: 8 }))
    expect(await screen.findByText('Politikalar kaydedildi.')).toBeInTheDocument()
  })

  it('izinli olmayan model seçicilerde görünmez', async () => {
    wrap(<GovernancePoliciesView />)
    const models = await screen.findByRole('region', { name: 'Modeller' })
    fireEvent.click(within(models).getByLabelText(/Claude Opus 5.5/))
    expect(within(within(models).getByLabelText('Genel varsayılan model')).queryByRole('option', { name: 'Claude Opus 5.5' })).toBeNull()
    expect(within(models).getByLabelText('Veri sorusu modeli')).toHaveDisplayValue('Claude Opus 5.5 (izinli değil)')
  })
})

describe('yönetişim denetim kaydı', () => {
  it('filtre sorguya geçer, ayrıntı açılır, daha eski kayıtlar imleçle gelir', async () => {
    wrap(<GovernanceAuditView />)
    expect(await screen.findByRole('cell', { name: 'settings.update' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /sil|düzenle/i })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Kayıt 2 ayrıntısı' }))
    expect(screen.getByText(/"after": 8/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Daha eski kayıtlar' }))
    expect(await screen.findByRole('cell', { name: 'auth.login_failed' })).toBeInTheDocument()
    expect(requests.at(-1)?.url).toContain('cursor=abc')

    fireEvent.change(screen.getByLabelText('Olay'), { target: { value: 'agent.*' } })
    fireEvent.change(screen.getByLabelText('Sonuç'), { target: { value: 'blocked' } })
    await waitFor(() => expect(requests.at(-1)?.url).toBe('/api/audit-logs?action=agent.*&result=blocked'))
    expect(screen.getByRole('link', { name: 'CSV indir' })).toHaveAttribute('href', '/api/audit-logs?action=agent.*&result=blocked&format=csv')
  })
})
