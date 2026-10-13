import { fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactElement, ReactNode } from 'react'
import { cloneElement } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { ChartSpec, Message } from '../api'
import { AnswerView } from './AnswerView'
import { liveText, copyText } from './answerText'
import { chartCsv, endLabelOffsets, endLabelText, formatDate, formatValue, niceTicks, seriesColors } from './chartFormat'

// jsdom'da boyut yok: ResponsiveContainer yerine sabit boyutlu çizim.
vi.mock('recharts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('recharts')>()
  return { ...actual, ResponsiveContainer: ({ children }: { children: ReactNode }) => cloneElement(children as ReactElement<{ width: number; height: number }>, { width: 600, height: 300 }) }
})

const line: ChartSpec = {
  type: 'line',
  title: 'Günlük OTP, son 7 gün',
  source: 'q:q_otp',
  x: { field: 'date', type: 'date', label: 'Tarih' },
  y: { unit: '%', label: 'OTP', min: 0 },
  series: [{ name: 'OTP', field: 'otp' }],
  data: ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'].map((date, i) => ({ date, otp: [82.4, 79.1, 85, 88.2, 76.5, 81, 84.3][i] })),
  reference: { y: 85, label: 'Hedef' },
  derived: false,
  note: 'Türkiye saatine göre günlük.',
}

const bar: ChartSpec = {
  type: 'bar',
  title: 'Meydanlara göre iptal',
  source: 'q:q_iptal',
  x: { field: 'airport', type: 'category', label: 'Meydan' },
  y: { unit: 'adet', label: null, min: null },
  series: [
    { name: 'Tarifeli', field: 'scheduled' },
    { name: 'Charter', field: 'charter' },
  ],
  data: [
    { airport: 'AYT', scheduled: 4, charter: 2 },
    { airport: 'SAW', scheduled: 1250, charter: 0 },
  ],
  reference: null,
  derived: false,
  note: null,
}

const message = (text: string, charts: Message['answer'] extends infer A ? (A extends { charts?: infer C } ? C : never) : never): Message =>
  ({
    id: 1,
    role: 'assistant',
    text,
    status: 'done',
    flow_cards: [],
    created_at: '',
    answer: {
      trace: [],
      queries: [
        { tool: 'query_apm', source: 'APM', purpose: 'Günlük OTP', query_id: 'q_otp', rows: 7, total_rows: 7, masked: false },
        { tool: 'query_warehouse', source: 'IBS', purpose: 'İptaller', query_id: 'q_iptal', rows: 2, total_rows: 2, masked: false },
      ],
      models: [],
      auto: true,
      duration_ms: 1,
      steps: 0,
      writes: [],
      citation_mismatch: [],
      charts,
    },
  }) as unknown as Message

const block = (spec: ChartSpec) => `:::chart\n${JSON.stringify(spec)}\n:::`

const renderAnswer = (text: string, charts: (ChartSpec | { error: string; title?: string })[]) => render(<AnswerView message={message(text, charts)} onSend={() => {}} busy={false} />)

describe('sohbet grafikleri', () => {
  it('line ve bar çiziliyor; ham JSON gösterilmiyor; tek seride legend yok, 2+ seride var', () => {
    const { container } = renderAnswer(`OTP hafta ortası yükseldi [^q:q_otp].\n\n${block(line)}\n\nİptaller SAW'da yoğun.\n\n${block(bar)}`, [line, bar])

    expect(screen.queryByText(/"type"/)).toBeNull()
    const otp = screen.getByRole('figure', { name: 'Günlük OTP, son 7 gün' })
    expect(within(otp).getByRole('img').getAttribute('aria-label')).toMatch(/Çizgi grafik, 7 nokta.*en yüksek 8 Eki %88,2.*en düşük 9 Eki %76,5/)
    expect(otp.querySelector('.recharts-legend-wrapper')).toBeNull()
    expect(otp.querySelector('.recharts-line-curve')).not.toBeNull()
    expect(within(otp).getByText('Kaynak [1]: APM · Günlük OTP')).toBeInTheDocument()
    expect(within(otp).getByText('Türkiye saatine göre günlük.')).toBeInTheDocument()

    const cancel = screen.getByRole('figure', { name: 'Meydanlara göre iptal' })
    expect(cancel.querySelector('.recharts-legend-wrapper')).not.toBeNull()
    expect(cancel.querySelectorAll('.recharts-bar-rectangle').length).toBeGreaterThan(0)
    expect(container.textContent).toContain("İptaller SAW'da yoğun.")
  })

  it('hbar ve stacked_bar çiziliyor', () => {
    const hbar = { ...bar, type: 'hbar' as const, title: 'Yatay' }
    const stacked = { ...bar, type: 'stacked_bar' as const, title: 'Yığılmış' }
    renderAnswer(`${block(hbar)}\n\n${block(stacked)}`, [hbar, stacked])
    for (const name of ['Yatay', 'Yığılmış']) {
      expect(screen.getByRole('figure', { name }).querySelectorAll('.recharts-bar-rectangle').length).toBeGreaterThan(0)
    }
  })

  it('Grafik/Tablo geçişi Türkçe biçimle tablo gösterir', () => {
    renderAnswer(block(bar), [bar])
    const card = screen.getByRole('figure', { name: 'Meydanlara göre iptal' })
    fireEvent.click(within(card).getByRole('button', { name: 'Tablo' }))
    const table = within(card).getByRole('table')
    expect(within(table).getByText('1.250 adet')).toBeInTheDocument()
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((h) => h.textContent),
    ).toEqual(['Meydan', 'Tarifeli', 'Charter'])
  })

  it('geçersiz veya doğrulanamayan grafikte veri yok, not var', () => {
    renderAnswer(`${block(line)}\n\n:::chart\n{bozuk}\n:::`, [{ error: 'Grafik kaynağı doğrulanamadı', title: 'Günlük OTP' }, { error: 'Grafik gösterilemedi' }])
    expect(screen.getByText('Grafik kaynağı doğrulanamadı · Günlük OTP')).toBeInTheDocument()
    expect(screen.getByText('Grafik gösterilemedi')).toBeInTheDocument()
    expect(screen.queryByRole('figure')).toBeNull()
  })

  it('seri gizlenince diğer serilerin rengi değişmiyor', () => {
    renderAnswer(block(bar), [bar])
    const card = screen.getByRole('figure', { name: 'Meydanlara göre iptal' })
    const charterFill = () => card.querySelector('.recharts-bar:last-of-type .recharts-bar-rectangle path')?.getAttribute('fill')
    expect(charterFill()).toBe('var(--series-2)')
    fireEvent.click(within(card).getByText('Tarifeli'))
    expect(charterFill()).toBe('var(--series-2)')
    expect(seriesColors(bar).get('Charter')).toBe('var(--series-2)')
    expect(seriesColors(line).get('OTP')).toBe('var(--brand)')
  })

  it('CSV, Türkçe sayı ve tarih biçimi', () => {
    expect(chartCsv(bar)).toBe('Meydan,Tarifeli (adet),Charter (adet)\nAYT,4,2\nSAW,1250,0')
    expect(formatValue(82.4, '%')).toBe('%82,4')
    expect(formatValue(1250, 'adet')).toBe('1.250 adet')
    expect(formatValue(35, 'dk')).toBe('35 dk')
    expect(formatValue(1204.5, '₺')).toBe('1.204,5 ₺')
    expect(formatDate('2026-09-14')).toBe('14 Eyl')
    expect(formatDate('2026-09-14T06:30')).toBe('14 Eyl 06:30')
    // Eksen: yuvarlak adımlar, 0'dan.
    expect(niceTicks(line)).toEqual([0, 20, 40, 60, 80, 100])
    expect(niceTicks(bar)).toEqual([0, 250, 500, 750, 1000, 1250])
    expect(niceTicks({ ...bar, type: 'stacked_bar' }, true)).toEqual([0, 250, 500, 750, 1000, 1250])
  })

  it('çizgi sonu etiketleri üst üste binmez, uzun ad kısalır', () => {
    const close: ChartSpec = {
      ...line,
      y: { unit: 'adet', label: null, min: 0 },
      series: [
        { name: 'Check-in', field: 'checkin' },
        { name: 'Uçağa biniş', field: 'boarded' },
      ],
      data: [{ date: '2026-10-12', checkin: 8150, boarded: 7900 }],
    }
    const offsets = endLabelOffsets(close, new Set(), [0, 12500], 200)
    const y = (name: string, value: number) => (1 - value / 12500) * 200 + offsets.get(name)!
    expect(y('Uçağa biniş', 7900) - y('Check-in', 8150)).toBeGreaterThanOrEqual(13)
    // Gizlenen seri yer kaplamaz.
    expect(endLabelOffsets(close, new Set(['Check-in']), [0, 12500], 200).get('Uçağa biniş')).toBe(0)
    expect(endLabelText('Uçağa binen yolcu sayısı (tarifeli)')).toBe('Uçağa binen yolcu…')
  })

  it('akan metinde ve kopyalamada blok gizlenir', () => {
    const json = JSON.stringify(line)
    expect(liveText(`Önce yorum.\n\n:::chart\n${json.slice(0, 40)}`)).toBe('Önce yorum.\n\n\n\n_Grafik hazırlanıyor…_')
    expect(liveText(`A\n\n:::chart\n${json}\n:::\n\nB`)).toContain('_Grafik hazırlanıyor…_')
    expect(liveText(`A\n\n:::chart\n${json}\n:::\n\nB`)).not.toContain('"type"')
    expect(copyText(`Yorum [^q:q_otp].\n\n:::chart\n${json}\n:::\n\nSon.`)).toBe('Yorum.\n\nSon.')
  })
})
