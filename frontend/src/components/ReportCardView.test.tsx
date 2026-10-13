import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ReportCard } from '../api'
import { ReportCardView } from './ReportCardView'

const card = (overrides: Partial<ReportCard> = {}): ReportCard => ({
  type: 'report', id: 'rpt_1', title: 'Dünkü iptal edilen uçuşlar', format: 'xlsx', status: 'ready', row_count: 3, file_size: 12_288,
  error: null, expires_at: null, retention_days: 7, conversation_id: 5, ...overrides,
})

const renderCard = (c: ReportCard, open?: (url: string) => void) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ReportCardView card={c} conversationId={5} open={open} />
    </QueryClientProvider>,
  )

describe('rapor kartı', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('hazır rapor: satır, boyut ve saklama süresi; İndir önce imzalı bağlantıyı alır', async () => {
    const fetchMock = vi.fn((url: string) =>
      Promise.resolve(new Response(JSON.stringify({ data: url.endsWith('download-link') ? { url: '/api/reports/rpt_1/download?signature=x', expires_at: '' } : card() }), { status: 200 })),
    )
    const assign = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderCard(card(), assign)

    expect(screen.getByText('3 satır · 12 KB · 7 gün saklanır')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'İndir' }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/api/reports/rpt_1/download?signature=x'))
    expect(fetchMock).toHaveBeenCalledWith('/api/reports/rpt_1/download-link', expect.objectContaining({ method: 'POST', body: JSON.stringify({ conversation_id: 5 }) }))
  })

  it('hata ve süresi dolmuş durumları yeniden oluşturma düğmesi gösterir', () => {
    vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise(() => {})))
    const { unmount } = renderCard(card({ status: 'failed', error: 'Tarih aralığını daraltın.' }))
    expect(screen.getByText('Tarih aralığını daraltın.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tekrar dene' })).toBeInTheDocument()
    unmount()

    renderCard(card({ status: 'expired' }))
    expect(screen.getByRole('button', { name: 'Yeniden oluştur' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'İndir' })).not.toBeInTheDocument()
  })
})
