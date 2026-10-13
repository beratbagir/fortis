import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Composer } from '../components/Composer'
import { ChatPanel } from '../editor/ChatPanel'
import { setAutoSend } from './settings'

/** Tarayıcı kayıt API'leri jsdom'da yok; en küçük sahteleri. */
class FakeRecorder {
  static isTypeSupported = (type: string) => type.startsWith('audio/webm')
  state: 'inactive' | 'recording' = 'inactive'
  mimeType = 'audio/webm;codecs=opus'
  ondataavailable: ((event: { data: Blob }) => void) | null = null
  onstop: (() => void) | null = null
  start() {
    this.state = 'recording'
  }
  stop() {
    this.state = 'inactive'
    this.ondataavailable?.({ data: new Blob(['ses'], { type: 'audio/webm' }) })
    this.onstop?.()
  }
}

let voiceEnabled = true
let transcribeCalls = 0
let messageBodies: Record<string, unknown>[] = []

function fakeFetch(url: string, init?: RequestInit): Promise<Response> {
  const json = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status }))
  if (url === '/api/voice/config') return json({ enabled: voiceEnabled, max_seconds: 30 })
  if (url === '/api/voice/transcribe') {
    transcribeCalls++
    return json({ text: 'Eşiği 3 saate indir', duration_s: 2, processing_ms: 900, transcription_id: 7 })
  }
  if (url.endsWith('/messages')) {
    messageBodies.push(JSON.parse(String(init?.body)))
    return Promise.resolve(new Response('event: done\ndata: {"conversation":{"id":1,"title":"x"}}\n\n', { status: 200 }))
  }
  if (url.startsWith('/api/conversations/')) return json({ data: { id: 1, title: 'x', messages: [], usage: { tokens: 0, cost_usd: 0 } } })
  return json({})
}

const wrap = (node: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{node}</QueryClientProvider>)

beforeEach(() => {
  voiceEnabled = true
  transcribeCalls = 0
  messageBodies = []
  vi.stubGlobal('fetch', vi.fn(fakeFetch))
  vi.stubGlobal('MediaRecorder', FakeRecorder)
  Element.prototype.scrollIntoView = vi.fn()
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [{ stop: vi.fn() }] }) },
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  setAutoSend(false)
})

describe('sesli komut', () => {
  it('durumlar sırayla değişir: hazır → kayıt → çevriliyor → hazır; metin kutuya yazılır ama gönderilmez', async () => {
    const onSend = vi.fn()
    wrap(<Composer disabled={false} onSend={onSend} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Sesli komut' }))
    const stop = await screen.findByRole('button', { name: 'Kaydı durdur' })
    expect(screen.getByText('0:00 / 0:30')).toBeInTheDocument()

    let resolveTranscribe: (r: Response) => void = () => {}
    vi.mocked(fetch).mockImplementationOnce(() => new Promise((resolve) => (resolveTranscribe = resolve)))
    fireEvent.click(stop)
    expect(await screen.findByText('Metne çevriliyor…')).toBeInTheDocument()

    resolveTranscribe(new Response(JSON.stringify({ text: 'Eşiği 3 saate indir', transcription_id: 7 }), { status: 200 }))
    expect(await screen.findByRole('button', { name: 'Sesli komut' })).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toHaveValue('Eşiği 3 saate indir')
    expect(screen.getByText('🎤 Sesten çevrildi, kontrol et')).toBeInTheDocument()
    expect(onSend).not.toHaveBeenCalled()

    // Kullanıcı düzeltip gönderir
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Eşiği 3 saate indir, Teams de' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gönder' }))
    expect(onSend).toHaveBeenCalledWith('Eşiği 3 saate indir, Teams de', { voice: { transcription_id: 7, edited: true } })
  })

  it('Esc kaydı iptal eder, istek gitmez', async () => {
    wrap(<Composer disabled={false} onSend={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Sesli komut' }))
    await screen.findByRole('button', { name: 'Kaydı durdur' })
    fireEvent.keyDown(window, { key: 'Escape' })

    expect(await screen.findByRole('button', { name: 'Sesli komut' })).toBeInTheDocument()
    expect(transcribeCalls).toBe(0)
    expect(screen.getByRole('textbox')).toHaveValue('')
  })

  it('bas-konuş: metin kutusu dışındayken boşluk basılı tutulur, bırakınca çevrilir', async () => {
    wrap(<Composer disabled={false} onSend={vi.fn()} />)
    await screen.findByRole('button', { name: 'Sesli komut' })
    screen.getByRole('textbox').blur()

    fireEvent.keyDown(document.body, { code: 'Space', key: ' ' })
    await screen.findByRole('button', { name: 'Kaydı durdur' })
    fireEvent.keyUp(document.body, { code: 'Space', key: ' ' })

    await waitFor(() => expect(screen.getByRole('textbox')).toHaveValue('Eşiği 3 saate indir'))
    expect(transcribeCalls).toBe(1)
  })

  it('metin kutusu odaktayken boşluk kayıt başlatmaz', async () => {
    wrap(<Composer disabled={false} onSend={vi.fn()} />)
    await screen.findByRole('button', { name: 'Sesli komut' })

    fireEvent.keyDown(screen.getByRole('textbox'), { code: 'Space', key: ' ' })
    expect(screen.queryByRole('button', { name: 'Kaydı durdur' })).not.toBeInTheDocument()
  })

  it('otomatik gönder açıksa 2 sn sonra gönderir; bir tuşa basılırsa iptal olur', { timeout: 10_000 }, async () => {
    setAutoSend(true)
    const onSend = vi.fn()
    wrap(<Composer disabled={false} onSend={onSend} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Sesli komut' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Kaydı durdur' }))
    await screen.findByText(/2 sn içinde gönderilecek/)

    expect(onSend).not.toHaveBeenCalled()
    await waitFor(() => expect(onSend).toHaveBeenCalledWith('Eşiği 3 saate indir', { voice: { transcription_id: 7, edited: false } }), { timeout: 3000 })

    fireEvent.click(screen.getByRole('button', { name: 'Sesli komut' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Kaydı durdur' }))
    await screen.findByText(/2 sn içinde gönderilecek/)
    fireEvent.keyDown(window, { key: 'a' })
    await new Promise((r) => setTimeout(r, 2200))
    expect(onSend).toHaveBeenCalledTimes(1)
  })

  it('VOICE_ENABLED=false iken buton görünmez', async () => {
    voiceEnabled = false
    wrap(<Composer disabled={false} onSend={vi.fn()} />)

    await waitFor(() => expect(fetch).toHaveBeenCalledWith('/api/voice/config', expect.anything()))
    await new Promise((r) => setTimeout(r, 0))
    expect(screen.queryByRole('button', { name: 'Sesli komut' })).not.toBeInTheDocument()
    expect(screen.queryByText('Sesli komutu otomatik gönder')).not.toBeInTheDocument()
  })

  it('editör: seçili blok çip olarak görünür ve mesajla gider; ✕ ile kaldırılır', async () => {
    wrap(
      <ChatPanel
        conversationId={1}
        previewId={null}
        onPreview={vi.fn()}
        onApply={vi.fn()}
        onReject={vi.fn()}
        outbox={null}
        active
        onActivate={vi.fn()}
        selection={{ id: 'get_delays', title: 'Rötarlı uçuşları getir' }}
      />,
    )

    expect(screen.getByText('Seçili: Rötarlı uçuşları getir')).toBeInTheDocument()
    fireEvent.change(screen.getByRole('textbox', { name: 'Mesaj' }), { target: { value: 'Bunu 180 dakika yap' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gönder' }))
    await waitFor(() => expect(messageBodies).toHaveLength(1))
    expect(messageBodies[0]).toEqual({ content: 'Bunu 180 dakika yap', context: { selected_task_id: 'get_delays' } })

    fireEvent.click(screen.getByRole('button', { name: 'Seçili blok bağlamını kaldır' }))
    expect(screen.queryByText(/Seçili:/)).not.toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Gönder' })).toBeEnabled())
    fireEvent.change(screen.getByRole('textbox', { name: 'Mesaj' }), { target: { value: 'Teams de ekle' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gönder' }))
    await waitFor(() => expect(messageBodies).toHaveLength(2))
    expect(messageBodies[1]).toEqual({ content: 'Teams de ekle' })
  })
})
