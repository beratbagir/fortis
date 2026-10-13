import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { BudgetsData, UsageReportData } from '../api'
import { Budgets } from './Budgets'
import { GovernanceUsageView } from './Usage'

const empty = { chat: 0, agent: 0, title: 0, evaluation: 0, eval: 0, flow: 0 }

const report = (credit: number | null): UsageReportData => ({
  range: { from: '2026-10-01', to: '2026-10-13' },
  totals: { calls: 40, cost_usd: 20, input_tokens: 120000, output_tokens: 8000 },
  daily: [{ date: '2026-10-12', ...empty, chat: 12, agent: 8 }],
  by_type: [
    { type: 'chat', label: 'Sohbet', calls: 30, cost_usd: 12 },
    { type: 'agent', label: 'Agent', calls: 10, cost_usd: 8 },
  ],
  by_department: [{ id: 1, name: 'Operasyon', calls: 40, cost_usd: 20 }],
  by_model: [{ model: 'claude-sonnet-5-5', label: 'Sonnet 5.5', calls: 40, cost_usd: 20 }],
  top_agents: [{ id: 7, name: 'Sabah Özeti', runs: 3, cost_usd: 8 }],
  top_users: [{ id: 2, name: 'Selin', department: 'Operasyon', calls: 30, cost_usd: 12 }],
  rows_total: 40,
  rows: [{ id: 99, at: '2026-10-12T09:00:00+00:00', user: 'Selin', department: 'Operasyon', type: 'chat', purpose: null, model: 'claude-sonnet-5-5', input_tokens: 3000, output_tokens: 200, cost_usd: 0.5 }],
  options: {
    departments: [{ id: 1, name: 'Operasyon' }],
    users: [{ id: 2, name: 'Selin' }],
    models: [{ id: 'claude-sonnet-5-5', label: 'Sonnet 5.5' }],
    types: [
      { id: 'chat', label: 'Sohbet' },
      { id: 'agent', label: 'Agent' },
    ],
  },
  credit_usd_value: credit,
})

const budgets: BudgetsData = {
  month: 'Ekim 2026',
  org: { id: 1, monthly_limit_usd: null, warn_at_percent: 80, hard_stop: false, spent_usd: 20, ratio: null },
  departments: [{ id: 1, name: 'Operasyon', budget: { id: 2, monthly_limit_usd: 25, warn_at_percent: 80, hard_stop: true, spent_usd: 20, ratio: 0.8 }, spent_usd: 20 }],
  users: [],
  credit_usd_value: null,
  blocks_flow_ai_steps: false,
}

let requests: { url: string; method: string; body: unknown }[] = []
let credit: number | null = null

beforeEach(() => {
  requests = []
  credit = null
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      requests.push({ url, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null })
      const data = url.startsWith('/api/governance/budgets') ? budgets : report(credit)
      return Promise.resolve(new Response(JSON.stringify({ data })))
    }),
  )
})
afterEach(() => vi.unstubAllGlobals())

const wrap = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <GovernanceUsageView />
    </QueryClientProvider>,
  )

describe('yönetişim kullanım', () => {
  it('toplamlar, kırılımlar ve satırlar; filtre sorguya ve CSV bağlantısına geçer', async () => {
    wrap()
    expect(await screen.findByRole('region', { name: 'Toplam maliyet' })).toHaveTextContent('$20,00')
    expect(screen.getByRole('region', { name: 'AI çağrısı' })).toHaveTextContent('çağrı başına $0,50')
    expect(within(screen.getByRole('region', { name: 'En çok harcayan 10 agent' })).getByRole('link', { name: 'Sabah Özeti' })).toHaveAttribute('href', '#/agents/7')
    expect(screen.getByRole('region', { name: 'Modele göre' })).toHaveTextContent('Sonnet 5.5')
    expect(screen.getByText(/son 1 \/ 40/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Departman'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Tür'), { target: { value: 'agent' } })
    await waitFor(() => expect(requests.at(-1)?.url).toMatch(/department_id=1&type=agent$/))
    expect(screen.getByRole('link', { name: 'CSV indir' }).getAttribute('href')).toMatch(/department_id=1&type=agent&format=csv$/)
  })

  it('kredi değeri tanımlıysa rakamlar kredi olarak da gösterilir', async () => {
    credit = 0.02
    wrap()
    expect(await screen.findByRole('region', { name: 'Toplam maliyet' })).toHaveTextContent('≈ 1.000 kredi')
  })

  it('bütçe değişikliği onaydan sonra kaydedilir', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <Budgets />
      </QueryClientProvider>,
    )
    const section = await screen.findByRole('region', { name: /Bütçeler/ })
    expect(within(section).getByText('%80')).toBeInTheDocument()
    expect(within(section).getByRole('button', { name: 'Bütçeleri kaydet' })).toBeDisabled()

    fireEvent.change(within(section).getByLabelText('Şirket aylık limit'), { target: { value: '500' } })
    fireEvent.click(within(section).getByLabelText('Şirket dolunca durdur'))
    fireEvent.change(within(section).getByLabelText('Operasyon aylık limit'), { target: { value: '' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Bütçeleri kaydet' }))

    const dialog = screen.getByRole('dialog', { name: 'Bütçe değişiklikleri kaydedilsin mi?' })
    expect(dialog).toHaveTextContent('Şirket: $500,00 / ay, uyarı %80, dolunca durdur')
    expect(dialog).toHaveTextContent('Operasyon: bütçe kaldırılacak')
    expect(requests.some((r) => r.method === 'PUT')).toBe(false)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Onayla ve kaydet' }))
    await waitFor(() =>
      expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({
        budgets: [
          { scope: 'org', monthly_limit_usd: 500, warn_at_percent: 80, hard_stop: true },
          { scope: 'department', scope_id: 1, monthly_limit_usd: null, warn_at_percent: 80, hard_stop: true },
        ],
        credit_usd_value: null,
      }),
    )
    expect(await screen.findByText('Bütçeler kaydedildi.')).toBeInTheDocument()
  })
})
