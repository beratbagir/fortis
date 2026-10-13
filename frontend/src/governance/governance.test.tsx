import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { GovernanceOverview } from '../api'
import { GovernanceOverviewView } from './Overview'

const overview: GovernanceOverview = {
  live: [
    { kind: 'agent', id: '31', name: 'Sabah Özeti', link: '#/agents/7/runs/31', started_by: 'Selin', status: 'running', is_test: true, started_at: null, seconds: 95, tool_calls: 21, cost_usd: 0.42 },
    { kind: 'flow', id: 'exec-1', name: 'Sigorta bildirimi', link: '#/runs/exec-1', started_by: 'Zamanlama', status: 'paused', is_test: false, started_at: null, seconds: 4000, tool_calls: null, cost_usd: 0 },
  ],
  month: { label: 'Ekim 2026', spent_usd: 12.5, budget_usd: 50, ratio: 0.25, previous_same_period_usd: 10, change_pct: 25, questions: 340, agent_runs: 61, actions_approved: 41, actions_rejected: 3 },
  alerts: [
    { id: 9, type: 'agent_limit', severity: 'critical', message: 'Sabah Özeti çalışması #31 (test): Araç çağrısı sınırı (20) aşıldı', link: '#/agents/7/runs/31', created_at: null, updated_at: null, resolved_at: null, resolved_by: null },
    { id: 10, type: 'approval_overdue', severity: 'warning', message: '"E-posta onayı" onayı 26 saattir bekliyor', link: null, created_at: null, updated_at: null, resolved_at: null, resolved_by: null },
  ],
  quality: { eval: { run_at: null, questions: 4, passed: 4, correct_tool_ratio: 1, cost_usd: 0.5 }, agent_score_avg: 84, scored_runs: 12, agents: [{ id: 7, name: 'Sabah Özeti', score_avg: 71, runs: 5 }] },
}

let posts: { url: string; body: unknown }[] = []

beforeEach(() => {
  posts = []
  vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => {
    if (init?.method === 'POST') posts.push({ url, body: init.body ? JSON.parse(String(init.body)) : null })
    return Promise.resolve(new Response(JSON.stringify({ data: url.endsWith('/overview') ? overview : { live: [] } })))
  }))
})
afterEach(() => vi.unstubAllGlobals())

const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><GovernanceOverviewView /></QueryClientProvider>)

describe('yönetişim genel bakış', () => {
  it('bu ay, canlı çalışmalar, dikkat gerekenler ve kalite', async () => {
    wrap()
    expect(await screen.findByRole('region', { name: 'Harcama · Ekim 2026' })).toHaveTextContent('$12,50')
    expect(screen.getByRole('region', { name: 'Geçen aya göre' })).toHaveTextContent('+%25')
    expect(screen.getByRole('region', { name: 'Aksiyonlar · bu ay' })).toHaveTextContent('41 / 3')

    const table = screen.getByRole('region', { name: /Canlı çalışmalar/ })
    expect(within(table).getByRole('link', { name: 'Sabah Özeti' })).toHaveAttribute('href', '#/agents/7/runs/31')
    expect(within(table).getByText('Agent · Selin · test')).toBeInTheDocument()
    expect(within(table).getByText('1 dk 35 sn')).toBeInTheDocument()
    expect(within(table).getByText('1 sa 6 dk')).toBeInTheDocument()
    expect(within(table).getByText('Onay bekliyor')).toBeInTheDocument()

    const attention = screen.getByRole('region', { name: /Dikkat gerekenler/ })
    expect(within(attention).getByRole('img', { name: 'Kritik' })).toBeInTheDocument()
    expect(within(attention).getAllByRole('link', { name: 'Git' })).toHaveLength(1)
    expect(screen.getByText('4/4')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Sabah Özeti\s*71/ })).toHaveAttribute('href', '#/agents/7/evaluation')
  })

  it('durdurma gerekçe ister; Çözüldü uyarıyı kapatır', async () => {
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Sabah Özeti çalışmasını durdur' }))
    const dialog = screen.getByRole('dialog', { name: 'Sabah Özeti durdurulsun mu?' })
    expect(within(dialog).getByRole('button', { name: 'Çalışmayı durdur' })).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/Gerekçe/), { target: { value: 'Döngüye girdi' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Çalışmayı durdur' }))
    await waitFor(() => expect(posts[0]).toEqual({ url: '/api/governance/runs/31/stop', body: { kind: 'agent', reason: 'Döngüye girdi' } }))
    expect(await screen.findByText('Sabah Özeti durduruldu.')).toBeInTheDocument()

    fireEvent.click(within(screen.getByRole('region', { name: /Dikkat gerekenler/ })).getAllByRole('button', { name: 'Çözüldü' })[0])
    await waitFor(() => expect(posts[1]).toEqual({ url: '/api/governance/alerts/9/resolve', body: null }))
  })
})
