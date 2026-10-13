import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AgentCard, AgentCatalog, AgentDetail } from '../api'
import { AuthContext, type AuthState } from '../auth/useAuth'
import { AgentCardView } from '../pages/AgentsPage'
import { Config } from './Config'
import { Evaluation } from './Evaluation'
import { buildCron, parseSchedule } from './cron'
import { diffLines, diffSets } from './diff'
import { cardStrip, needsData } from './format'

const card = (o: Partial<AgentCard> = {}): AgentCard => ({
  id: 3, slug: 'x', name: 'Sabah Özeti', description: 'Dünkü operasyonu özetler.', status: 'live', icon: 'SÖ', tools: [], systems: ['APM', 'IBS'],
  owner: { id: 1, name: 'Selin Ak', title: 'OCC Müdürü' }, department: 'Operasyon', department_id: 1, trigger: 'Her gün 07:30', mine: true, created_at: null,
  runs_7d: 7, runs_30d: 30, useful_pct: 98, approved_actions_30d: 0, last_run_at: null, ...o,
})

const auth = (admin = false): AuthState => ({
  config: { driver: 'password', locale: 'tr' } as AuthState['config'],
  user: { id: 1, name: 'Selin', email: 's@x', role: admin ? 'admin' : 'builder', abilities: { build: true, admin } } as unknown as AuthState['user'],
  setUser: () => {},
  logout: async () => {},
  setTheme: () => {},
    locale: 'tr',
    setLanguage: () => {},
})

const wrap = (node: ReactNode, admin = false) =>
  render(
    <AuthContext.Provider value={auth(admin)}>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{node}</QueryClientProvider>
    </AuthContext.Provider>,
  )

describe('agent kartı', () => {
  it('durum rozeti, departman ve sahip, sistem etiketleri, alt şerit ve üç bağlantı', () => {
    wrap(<AgentCardView agent={card()} />)
    const c = screen.getByRole('article', { name: 'Sabah Özeti' })
    expect(within(c).getByText('Canlı')).toBeInTheDocument()
    expect(within(c).getByText('Operasyon · sahibi OCC Müdürü')).toBeInTheDocument()
    expect(within(within(c).getByRole('list', { name: 'Sistemler' })).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['APM', 'IBS'])
    expect(within(c).getByText('7 çalışma/hafta · %98 faydalı')).toBeInTheDocument()
    expect(within(c).getByRole('link', { name: 'Sabah Özeti: sohbet' })).toHaveAttribute('href', '#/agents/3/chat')
    expect(within(c).getByRole('link', { name: 'Sabah Özeti: izleme' })).toHaveAttribute('href', '#/agents/3')
    expect(within(c).getByRole('link', { name: 'Sabah Özeti: izinler' })).toHaveAttribute('href', '#/agents/3/config')
  })

  it('% faydalı geri bildirim yoksa gizli; onay sayısı; hiç çalışmadıysa; pilot ve şablon rozetleri', () => {
    expect(cardStrip(card({ useful_pct: null }))).toBe('7 çalışma/hafta')
    expect(cardStrip(card({ useful_pct: null, score_avg: 84 }))).toBe('7 çalışma/hafta · puan 84')
    expect(cardStrip(card({ runs_30d: 0, runs_7d: 0, approved_actions_30d: 22 }))).toBe('22 onay')
    expect(cardStrip(card({ runs_30d: 0, runs_7d: 0 }))).toBe('henüz çalışmadı')
    const install = vi.fn()
    wrap(
      <>
        <AgentCardView agent={card({ id: 4, name: 'Pilot Agent', status: 'pilot' })} />
        <AgentCardView agent={card({ id: 5, name: 'Şablon', status: 'template' })} onInstall={install} />
      </>,
    )
    expect(within(screen.getByRole('article', { name: 'Pilot Agent' })).getByText('Pilot')).toBeInTheDocument()
    const template = screen.getByRole('article', { name: 'Şablon' })
    expect(within(template).getByText('1 tıkla kur')).toBeInTheDocument()
    fireEvent.click(within(template).getByRole('button', { name: 'Kur' }))
    expect(install).toHaveBeenCalled()
  })
})

describe('zamanlama ve fark yardımcıları', () => {
  it('cron ↔ seçici', () => {
    expect(parseSchedule('30 7 * * *')).toEqual({ mode: 'daily', day: 1, time: '07:30' })
    expect(parseSchedule('0 8 * * 1')).toEqual({ mode: 'weekly', day: 1, time: '08:00' })
    expect(parseSchedule('0 7 * * 1-5').mode).toBe('weekdays')
    expect(parseSchedule('*/15 * * * *').mode).toBe('custom')
    expect(buildCron({ mode: 'weekly', day: 5, time: '16:00' })).toBe('0 16 * * 5')
    expect(buildCron({ mode: 'weekdays', day: 1, time: '07:05' })).toBe('5 7 * * 1-5')
  })

  it('satır ve küme farkı', () => {
    expect(diffLines('a\nb\nc', 'a\nc\nd')).toEqual([
      { kind: 'same', text: 'a' },
      { kind: 'removed', text: 'b' },
      { kind: 'same', text: 'c' },
      { kind: 'added', text: 'd' },
    ])
    expect(diffSets(['query_apm', 'send_email'], ['query_apm', 'post_teams'])).toEqual({ added: ['post_teams'], removed: ['send_email'] })
  })
})

describe('yapılandırma', () => {
  const catalog: AgentCatalog = {
    tools: [
      { name: 'query_apm', kind: 'read', system: 'APM', label: 'APM sorgusu', description: '' },
      { name: 'send_email', kind: 'write', system: 'E-posta', label: 'E-posta gönder', description: '' },
    ],
    events: [{ name: 'flight.delayed', label: 'Uçuş rötarı', default_min_delay_minutes: 60 }],
    models: [{ id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5' }],
    recipient_domains: ['freebird.com.tr'],
    teams_channels: [],
    departments: [{ id: 1, name: 'Operasyon' }],
    owners: [],
  }
  const agent = {
    ...card(), version: 4, conversation_id: null, can_manage: true, can_chat: true, suggested_prompts: [], recent_runs: [],
    overview: {} as AgentDetail['overview'],
    draft: {
      name: 'Sabah Özeti', description: 'x', icon: 'SÖ', instructions: 'Dünü özetle.', model: 'auto',
      tools: [{ name: 'query_apm' }, { name: 'send_email', constraints: { recipients: ['occ@freebird.com.tr'] } }],
      triggers: [{ type: 'schedule', cron: '30 7 * * *', input_template: 'Özetle' }],
      approval_policy: { approvers: ['owner'], send_email: { mode: 'always' } },
      plan: [], suggested_prompts: [], department_id: 1, owner_id: 1,
    },
  } as AgentDetail
  let saved: unknown = null

  beforeEach(() => {
    saved = null
    vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => {
      if (url === '/api/agents/catalog') return Promise.resolve(new Response(JSON.stringify({ data: catalog })))
      if (url === '/api/agents/3' && init?.method === 'PUT') {
        saved = JSON.parse(String(init.body))
        return Promise.resolve(new Response(JSON.stringify({ data: { ...agent, version: 5 } })))
      }
      return Promise.resolve(new Response('{}', { status: 404 }))
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  it('kaydedilmemiş değişiklik uyarısı, Türkçe zamanlama önizlemesi ve yeni sürüm olarak kaydetme', async () => {
    const onDirty = vi.fn()
    wrap(<Config agent={agent} onDirty={onDirty} />)

    const triggers = await screen.findByRole('region', { name: 'Tetikleyiciler' })
    expect(within(triggers).getByText('· Her gün 07:30')).toBeInTheDocument()
    expect(screen.queryByText('Kaydedilmemiş değişiklikler var.')).not.toBeInTheDocument()

    fireEvent.change(within(triggers).getByLabelText('Sıklık'), { target: { value: 'weekdays' } })
    expect(within(triggers).getByText('· Hafta içi her gün 07:30')).toBeInTheDocument()
    expect(screen.getByText('Kaydedilmemiş değişiklikler var.')).toBeInTheDocument()
    expect(onDirty).toHaveBeenLastCalledWith(true)

    // "Onaysız" seçeneği builder'a sunulmaz.
    expect(within(screen.getByRole('region', { name: 'Onaylar' })).queryByText('Onaysız (sadece admin)')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet (v5)' }))
    await waitFor(() => expect(saved).not.toBeNull())
    expect(saved).toMatchObject({ base_version: 4, triggers: [{ type: 'schedule', cron: '30 7 * * 1-5', input_template: 'Özetle' }] })
  })

  it('vazgeç değişiklikleri geri alır', async () => {
    wrap(<Config agent={agent} onDirty={() => {}} />)
    fireEvent.change(await screen.findByLabelText('Talimatlar'), { target: { value: 'Yeni talimat' } })
    fireEvent.click(screen.getByRole('button', { name: 'Vazgeç' }))
    expect(screen.getByLabelText('Talimatlar')).toHaveValue('Dünü özetle.')
    expect(screen.queryByText('Kaydedilmemiş değişiklikler var.')).not.toBeInTheDocument()
  })
})

describe('değerlendirme', () => {
  let put: unknown = null

  beforeEach(() => {
    put = null
    vi.stubGlobal('fetch', vi.fn((_url: string, init?: RequestInit) => {
      const base = { criteria: ['Özet 200 kelimeyi geçmiyor'], enabled: false, can_edit: true, avg_score_30d: 84, scored_runs_30d: 2, rule_pass_rates: [{ rule: 'Özet 200 kelimeyi geçmiyor', checked: 2, pass_pct: 50 }], recent: [{ run_id: 12, score: 67, started_at: null }] }
      if (init?.method === 'PUT') {
        put = JSON.parse(String(init.body))
        return Promise.resolve(new Response(JSON.stringify({ data: { ...base, ...(put as object) } })))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: base })))
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  it('kural ekler, açar ve kaydeder; özet, kural başına geçme ve son puanlar görünür', async () => {
    wrap(<Evaluation agentId={3} />)

    expect(await screen.findByLabelText('Kural 1')).toHaveValue('Özet 200 kelimeyi geçmiyor')
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Puan özeti' })).getByText('84')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Kural başına geçme' })).getByText('%50')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Son puanlar' })).getByRole('link')).toHaveAttribute('href', '#/agents/3/runs/12')
    expect(screen.getByRole('button', { name: 'Kaydet' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: '+ Kural ekle' }))
    fireEvent.change(screen.getByLabelText('Kural 2'), { target: { value: 'Her rötar nedeni bir rötar koduna dayanıyor' } })
    fireEvent.click(screen.getByLabelText('Her çalışmadan sonra puanla'))

    // Veri gerektiren kuralda uyarı görünür.
    fireEvent.click(screen.getByRole('button', { name: '+ Kural ekle' }))
    fireEvent.change(screen.getByLabelText('Kural 3'), { target: { value: 'Rakamlar kaynak sorgularla tutarlı' } })
    expect(screen.getByRole('note')).toHaveTextContent('sadece çıktının kendi içindeki tutarlılığına bakılır')
    fireEvent.click(screen.getByRole('button', { name: "Kural 3'i kaldır" }))
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
    expect(needsData('Rakamlar veriyle uyuşuyor')).toBe(true)
    expect(needsData('Özet 200 kelimeyi geçmiyor')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(put).toEqual({ criteria: ['Özet 200 kelimeyi geçmiyor', 'Her rötar nedeni bir rötar koduna dayanıyor'], enabled: true }))
    expect(await screen.findByText(/Bundan sonraki çalışmalar puanlanacak/)).toBeInTheDocument()
  })
})
