import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { InboxItem, InboxItemDetail } from '../api'
import { inboxGroup, initials, recipientAllowed } from '../inbox/inboxUtils'
import { InboxPage } from './InboxPage'

const now = new Date().toISOString()
const yesterday = new Date(Date.now() - 86_400_000).toISOString()

const item = (o: Partial<InboxItem>): InboxItem => ({
  id: 1, type: 'approval', title: 'x', body: null, source_label: null, icon: null, status: 'open', actionable: true, subject_type: 'agent_action',
  snoozed_until: null, read: false, created_at: now, ...o,
})

const items = [
  item({ id: 1, title: 'Sabah özeti e-postası', body: 'occ@freebird.com.tr adresine gönderilecek', icon: 'SO', source_label: 'Sabah Operasyon Özeti' }),
  item({ id: 2, type: 'question', title: 'Hangi istasyonlar?', subject_type: 'agent_run' }),
  item({ id: 3, type: 'update', title: 'Rapor hazır', actionable: false, read: true, created_at: yesterday }),
]

const approval: InboxItemDetail = {
  ...items[0],
  detail: {
    kind: 'action',
    action: { id: 9, type: 'send_email', status: 'pending', payload: { to: ['occ@freebird.com.tr'], subject: 'Günlük özet', body_markdown: '**12** rötar' }, reason: 'Her sabah özet', result: null },
    run: { id: 4, agent: { id: 1, name: 'Sabah Operasyon Özeti', status: 'live', icon: 'SO' }, status: 'waiting_approval', is_test: false, started_at: now, summary: null, output: null, conversation_id: 77, cost_usd: 0.02 },
    what_happens: ['E-posta occ@freebird.com.tr adresine gönderilir.', 'Bu işlem senin adınla denetim kaydına yazılır.'],
    can_decide: true,
    editable_recipients: true,
    recipient_domains: ['freebird.com.tr'],
    allowed_recipients: [],
  },
}

let calls: { url: string; method: string; body: unknown }[] = []
let current: InboxItemDetail = approval
const executed: InboxItemDetail = { ...approval, status: 'done', detail: { ...approval.detail, action: { ...(approval.detail as { action: object }).action, status: 'executed' } } as InboxItemDetail['detail'] }

beforeEach(() => {
  calls = []
  current = approval
  vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'
    calls.push({ url, method, body: init?.body ? JSON.parse(String(init.body)) : null })
    let body: unknown
    if (url.startsWith('/api/inbox?')) body = { data: items, counts: { all: 3, action: 2, mentions: 0, updates: 1, done: 0 } }
    else if (url === '/api/inbox/bulk') body = { data: { updated: 2 } }
    else if (url === '/api/inbox/1/approve') body = { data: (current = executed) }
    else body = { data: current }
    return Promise.resolve(new Response(JSON.stringify(body), { status: 200 }))
  }))
})

afterEach(() => vi.unstubAllGlobals())

const wrap = (itemId: number | null) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <InboxPage itemId={itemId} />
    </QueryClientProvider>,
  )

describe('inbox yardımcıları', () => {
  it('gruplar, baş harfler ve alıcı kuralı', () => {
    const base = new Date('2026-10-13T10:00:00')
    expect(inboxGroup('2026-10-13T08:00:00', base)).toBe('Bugün')
    expect(inboxGroup('2026-10-12T23:00:00', base)).toBe('Dün')
    expect(inboxGroup('2026-10-01T08:00:00', base)).toBe('Daha eski')
    expect(initials({ icon: null, title: 'Sabah özeti' })).toBe('SÖ')
    expect(initials({ icon: 'ro', title: 'x' })).toBe('RO')
    expect(recipientAllowed('ops@freebird.com.tr', [], ['freebird.com.tr'])).toBe(true)
    expect(recipientAllowed('biri@gmail.com', [], ['freebird.com.tr'])).toBe(false)
    expect(recipientAllowed('ops@freebird.com.tr', ['occ@freebird.com.tr'], ['freebird.com.tr'])).toBe(false)
  })
})

describe('inbox listesi', () => {
  it('sekmeler sayaçlarla, satırlar tür etiketi ve doğru düğmeyle, gruplara ayrılmış', async () => {
    wrap(null)

    const row = (await screen.findByText('Sabah özeti e-postası')).closest('li')!
    expect(within(row).getByText('Onay')).toBeInTheDocument()
    expect(within(row).getByText('SO')).toBeInTheDocument()
    expect(within(row).getByRole('img', { name: 'Okunmadı' })).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: 'İncele' })).toHaveAttribute('href', '#/inbox/1')
    expect(within(screen.getByText('Hangi istasyonlar?').closest('li')!).getByRole('link', { name: 'Cevapla' })).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Dün' })).getByText('Rapor hazır')).toBeInTheDocument()
    expect(within(screen.getByRole('tab', { name: /Aksiyon gerekiyor/ })).getByText('2')).toBeInTheDocument()
  })

  it('"Hepsini onayla" sadece seçilenlerin hepsi onaysa görünür ve toplu istek atar', async () => {
    wrap(null)
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Sabah özeti e-postası seç' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Hangi istasyonlar? seç' }))
    const bar = screen.getByRole('toolbar', { name: 'Toplu işlemler' })
    expect(within(bar).queryByText('Hepsini onayla')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Hangi istasyonlar? seç' }))
    fireEvent.click(within(bar).getByText('Hepsini onayla'))
    await waitFor(() => expect(calls.some((c) => c.url === '/api/inbox/bulk')).toBe(true))
    expect(calls.find((c) => c.url === '/api/inbox/bulk')!.body).toEqual({ ids: [1], op: 'approve' })
  })
})

describe('onay detayı', () => {
  it('önizleme, ne olur, Agent\'a sor bağlantısı; onaylayınca tek istek', async () => {
    wrap(1)

    const preview = await screen.findByRole('region', { name: 'Önizleme' })
    expect(within(preview).getByText('occ@freebird.com.tr')).toBeInTheDocument()
    expect(within(preview).getByText('Günlük özet')).toBeInTheDocument()
    expect(screen.getByText('Bu işlem senin adınla denetim kaydına yazılır.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: "Agent'a sor" })).toHaveAttribute('href', '#/chat/77')

    fireEvent.click(screen.getByRole('button', { name: 'Onayla ve gönder' }))
    await screen.findByText('Gönderildi', { exact: false })
    expect(calls.filter((c) => c.url === '/api/inbox/1/approve')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Onayla ve gönder' })).not.toBeInTheDocument()
  })

  it('taslakta izinli olmayan alıcı kaydedilemez; ret gerekçesiyle gider', async () => {
    wrap(1)
    fireEvent.click(await screen.findByRole('button', { name: 'Taslağı düzenle' }))
    fireEvent.change(screen.getByLabelText('Alıcı'), { target: { value: 'dis@gmail.com' } })
    expect(screen.getByText('İzinli olmayan alıcı: dis@gmail.com')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Kaydet' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Vazgeç' }))

    fireEvent.click(screen.getByRole('button', { name: 'Reddet' }))
    fireEvent.change(screen.getByLabelText(/Ret gerekçesi/), { target: { value: 'Konu yanlış' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reddet' }))
    await waitFor(() => expect(calls.find((c) => c.url === '/api/inbox/1/reject')?.body).toEqual({ reason: 'Konu yanlış' }))
  })
})
