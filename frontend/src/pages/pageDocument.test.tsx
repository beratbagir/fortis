import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PageDetail } from '../api'
import { PagePanel } from '../components/PageCard'
import { buildPageDocument, pageCsp, safeJson } from './pageDocument'

const data = { 'q:q_1': { source: 'IBS', purpose: 'Seferler', at: '2026-10-12T06:30:00Z', columns: ['remark'], rows: [['</script><script>alert(1)</script>']], total_rows: 1, truncated: false } }

describe('sayfa belgesi', () => {
  it('CSP ağı tamamen kapatır (hiç adres yok) ve model içeriğinden önce gelir', () => {
    const doc = buildPageDocument({ html: '<p>MODEL</p>', data, theme: 'dark', chartJs: 'window.Chart = function(){}' })
    const csp = pageCsp()
    expect(csp).toContain("default-src 'none'")
    expect(csp).toContain("script-src 'unsafe-inline';")
    expect(csp).toContain("connect-src 'none'")
    expect(csp).toContain("font-src 'none'")
    expect(csp).not.toMatch(/https?:|unsafe-eval/)
    expect(doc.indexOf('Content-Security-Policy')).toBeLessThan(doc.indexOf('MODEL'))
    expect(doc).toContain('data-theme="dark"')
    // Kütüphane satır içi: dış <script src> yok.
    expect(doc).not.toMatch(/<script src=/)
    expect(doc).toContain('window.Chart = function(){}')
  })

  it('veri ve kütüphane </script> ile kabuktan kaçamaz', () => {
    const doc = buildPageDocument({ html: '', data, theme: 'light', chartJs: 'var x = "</script>"' })
    expect(doc).not.toContain('</script><script>alert(1)')
    expect(doc).toContain('var x = "<\\/script>"')
    expect(JSON.parse(safeJson(data))).toEqual(data)
  })
})

const detail: PageDetail = {
  id: 'art_1',
  conversation_id: 1,
  kind: 'page',
  title: 'Pano',
  description: 'Açıklama',
  version: 1,
  latest_version: 1,
  content: '<p>x</p>',
  sources: ['q:q_1'],
  data,
  data_at: '2026-10-12T06:30:00Z',
  warning: null,
  versions: [],
}

beforeEach(() =>
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string) => Promise.resolve(url.includes('/vendor/') ? new Response('window.Chart = function(){}') : new Response(JSON.stringify({ data: detail })))),
  ),
)
afterEach(() => vi.unstubAllGlobals())

describe('sayfa paneli', () => {
  it('sadece kendi çerçevesinden gelen ask işlenir ve onaysız gönderilmez', async () => {
    const onSend = vi.fn()
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <PagePanel id="art_1" version={1} onSend={onSend} onRevise={() => {}} onClose={() => {}} />
      </QueryClientProvider>,
    )
    const frame = (await screen.findByTitle('Pano')) as HTMLIFrameElement
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts')
    expect(screen.getByText(/Kaynak: IBS · Seferler/)).toBeInTheDocument()
    const post = (data: unknown, source: MessageEventSource | null) => act(() => void window.dispatchEvent(new MessageEvent('message', { data, source })))

    post({ type: 'ask', text: 'Başka kaynak' }, window)
    post({ type: 'navigate', url: 'https://evil.example' }, frame.contentWindow)
    expect(screen.queryByRole('dialog', { name: 'Sayfadan gelen soru' })).toBeNull()

    post({ type: 'ask', text: 'AYT rötarlarını ayrıntılandır' }, frame.contentWindow)
    const ask = screen.getByRole('dialog', { name: 'Sayfadan gelen soru' })
    expect(onSend).not.toHaveBeenCalled()
    fireEvent.click(within(ask).getByRole('button', { name: 'Sohbette sor' }))
    expect(onSend).toHaveBeenCalledWith('AYT rötarlarını ayrıntılandır')
  })
})
