import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { architectPrompt, type ArchitectAgent, type BuildData } from '../api'
import { BuildPage } from '../pages/BuildPage'
import { stepBadge } from './plan'
import { PlanView } from './PlanView'

const agent = (o: Partial<ArchitectAgent> = {}): ArchitectAgent => ({
  id: 5, slug: 'x', name: 'Sabah Rötar Özeti', description: null, icon: 'SR', status: 'draft', version: 1, updated_at: null, conversation_id: 9,
  instructions: 'x', model: 'auto',
  tools: [
    { name: 'query_apm', kind: 'read', system: 'APM', label: 'APM sorgusu' },
    { name: 'send_email', kind: 'write', system: 'E-posta', label: 'E-posta gönder', constraints: { recipients: ['occ@freebird.com.tr'] } },
  ],
  triggers: [
    { id: 1, type: 'manual', config: {}, input_template: null, enabled: true, kestra_flow_id: null, synced_at: null, sync_error: null },
    { id: 2, type: 'schedule', config: { cron: '0 6 * * *' }, input_template: 'Özetle', enabled: true, kestra_flow_id: null, synced_at: null, sync_error: null },
  ],
  approval_policy: { send_email: { mode: 'always' }, approvers: ['owner'] },
  plan: [
    { type: 'trigger', title: 'Her gün 06:00' },
    { type: 'read', title: 'APM · dünkü uçuşlar', detail: 'salt okunur' },
    { type: 'decide', title: 'Rötar 30 dk üstü mü?', no: 'Dahil etme' },
    { type: 'draft', title: 'Özet e-postası' },
    { type: 'approve', title: 'Sahibi onaylar', detail: 'Fortis Inbox' },
    { type: 'act', title: 'E-postayla gönder' },
  ],
  suggested_prompts: [], reads: ['APM'], writes: ['E-posta'], estimated_cost_usd: null, last_test_run: null, can_go_live: false, has_draft: true,
  ...o,
})

describe('plan görünümü', () => {
  it('adım türleri, karar dalı ve özet kutuları', () => {
    render(<PlanView agent={agent()} />)

    const steps = within(screen.getByRole('list', { name: 'Agent planı' })).getAllByRole('button')
    expect(steps.map((s) => s.getAttribute('data-step'))).toEqual(['trigger', 'read', 'decide', 'draft', 'approve', 'act'])
    expect(within(steps[0]).getByText('TETİKLEYİCİ')).toBeInTheDocument()
    expect(within(steps[1]).getByText('OKU')).toBeInTheDocument()
    expect(within(steps[1]).getByText('AP')).toBeInTheDocument()
    expect(within(steps[4]).getByText('ONAY')).toBeInTheDocument()
    expect(screen.getByText('Hayır')).toBeInTheDocument()
    expect(screen.getByText('Dahil etme')).toBeInTheDocument()

    expect(within(screen.getByRole('region', { name: 'Okuduğu sistemler' })).getByText('APM')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Yazdıkları (onaydan sonra)' })).getByText('E-posta')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Tahmini maliyet' })).getByText('Test sonrası hesaplanır')).toBeInTheDocument()

    // Karta tıklanınca ilgili ayar: tetikleyiciler, gönderim kısıtları.
    fireEvent.click(steps[0])
    expect(screen.getByText('Zamanlama: 0 6 * * * (Europe/Istanbul)')).toBeInTheDocument()
    fireEvent.click(steps[5])
    expect(screen.getByText('E-posta gönder → occ@freebird.com.tr')).toBeInTheDocument()
  })

  it('boş plan ve maliyet', () => {
    render(<PlanView agent={agent({ plan: [], reads: [], writes: [], estimated_cost_usd: 0.123 })} />)
    expect(screen.getByText(/Architect taslağı hazırlayınca/)).toBeInTheDocument()
    expect(screen.getByText('Hiçbir şey yazmaz')).toBeInTheDocument()
    expect(screen.getByText('≈ $0,123 / çalışma')).toBeInTheDocument()
    expect(stepBadge({ type: 'act', title: "Teams'e gönder" })).toBe('TM')
  })
})

describe('oluştur ekranı', () => {
  const build: BuildData = {
    examples: ['Her sabah rötarları özetle'],
    templates: [
      { key: 'morning-brief', name: 'Sabah Operasyon Özeti', description: null, icon: 'SO', systems: ['APM', 'IBS'], trigger: 'Zamanlama: 0 6 * * *', available: true },
      { key: 'hr', name: 'İK Değişiklik Özeti', description: null, icon: 'İK', systems: ['SuccessFactors'], trigger: null, available: false },
    ],
    departments: [{ id: 3, name: 'Operasyon' }],
    default_department_id: 3,
    agents: [],
  }
  let posts: unknown[] = []

  beforeEach(() => {
    posts = []
    window.location.hash = '#/build'
    vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => {
      if (url === '/api/agents' && init?.method === 'POST') {
        posts.push(JSON.parse(String(init.body)))
        return Promise.resolve(new Response(JSON.stringify({ data: { id: 42 } }), { status: 201 }))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: build }), { status: 200 }))
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><BuildPage /></QueryClientProvider>)

  it('örnek metni doldurur, boş taslak açar ve ilk mesajı Architect\'e bırakır', async () => {
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Her sabah rötarları özetle' }))
    expect(screen.getByLabelText("Agent'ın görevi")).toHaveValue('Her sabah rötarları özetle')
    // Sadece kurulabilir şablonlar görünür.
    expect(screen.getByText('Sabah Operasyon Özeti')).toBeInTheDocument()
    expect(screen.queryByText('İK Değişiklik Özeti')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: "Architect'i başlat" }))
    await waitFor(() => expect(window.location.hash).toBe('#/build/agents/42'))
    expect(posts).toEqual([{ mode: 'blank', department_id: 3 }])
    expect(architectPrompt.take(42)).toBe('Her sabah rötarları özetle')
  })

  it('şablon kartı şablondan taslak açar', async () => {
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: /Sabah Operasyon Özeti/ }))
    await waitFor(() => expect(posts).toEqual([{ mode: 'template', template_key: 'morning-brief', department_id: 3 }]))
  })
})
