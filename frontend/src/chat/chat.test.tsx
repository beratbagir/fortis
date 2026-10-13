import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AnswerQuery, Message, TraceStep } from '../api'
import { TableCard } from './AnswerParts'
import { extractActions, numberCitations, splitBlocks } from './answerText'
import { AnswerView } from './AnswerView'
import { greeting, groupOf } from './chatUtils'
import { HomeView } from './HomeView'
import { LiveTrace } from './LiveTrace'
import { ModelPicker } from './Pickers'
import { QuestionCard } from './QuestionCard'

const json = (body: unknown) => Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }))
const wrap = (node: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{node}</QueryClientProvider>)

let routes: Record<string, unknown> = {}
beforeEach(() => {
  routes = {}
  vi.stubGlobal('fetch', vi.fn((url: string) => json(routes[url] ?? { data: null })))
})
afterEach(() => vi.unstubAllGlobals())

const query = (id: string, extra: Partial<AnswerQuery> = {}): AnswerQuery => ({ tool: 'query_warehouse', source: 'IBS', purpose: 'Dünkü uçuşlar', query_id: id, rows: 212, total_rows: 212, masked: false, ...extra })

describe('Home', () => {
  it('selamlama Türkiye saatine göre değişir', () => {
    expect(greeting(new Date('2026-10-13T04:30:00Z'))).toBe('Günaydın') // 07:30 TSİ
    expect(greeting(new Date('2026-10-13T10:00:00Z'))).toBe('İyi günler') // 13:00
    expect(greeting(new Date('2026-10-13T17:00:00Z'))).toBe('İyi akşamlar') // 20:00
    expect(greeting(new Date('2026-10-13T00:30:00Z'))).toBe('İyi akşamlar') // 03:30
  })

  it('bağlı olmayan çip soluk; bekleyen yokken boş durum', async () => {
    routes['/api/home'] = { data: { greeting_name: 'Kerem', suggestions: [{ type: 'question', text: 'Dün en çok rötar yapan 5 uçuş hangileri?' }], waiting_total: 0, waiting: [], activity: [] } }
    routes['/api/connectors/status'] = { data: [
      { key: 'ibs', label: 'IBS', abbr: 'IB', status: 'connected', detail: null },
      { key: 'outlook', label: 'Outlook', abbr: 'OL', status: 'not_configured', detail: null },
    ] }
    const onSend = vi.fn()
    wrap(<HomeView composer={<div />} onSend={onSend} busy={false} />)

    expect(await screen.findByRole('heading', { level: 1, name: /, Kerem/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Outlook: kurulum bekliyor' })).toHaveClass('opacity-50')
    expect(screen.getByRole('link', { name: 'IBS: bağlı' })).not.toHaveClass('opacity-50')
    expect(screen.getByText('Bekleyen işin yok')).toBeInTheDocument()
    expect(screen.getByText('Bugün henüz çalışan yok')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Dün en çok rötar/ }))
    expect(onSend).toHaveBeenCalledWith('Dün en çok rötar yapan 5 uçuş hangileri?')
  })

  it('sohbetler tarihe göre gruplanır', () => {
    const now = new Date(2026, 9, 13, 12)
    expect(groupOf(new Date(2026, 9, 13, 8).toISOString(), now)).toBe('Bugün')
    expect(groupOf(new Date(2026, 9, 12, 23).toISOString(), now)).toBe('Dün')
    expect(groupOf(new Date(2026, 9, 9).toISOString(), now)).toBe('Bu hafta')
    expect(groupOf(new Date(2026, 8, 1).toISOString(), now)).toBe('Daha eski')
  })
})

describe('Model seçici', () => {
  it('sadece izinli modeller, Auto seçili, politika notu', async () => {
    routes['/api/settings/ai'] = { data: { models: [{ id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5', strength: 'Dengeli, çoğu iş için varsayılan' }, { id: 'claude-opus-5-5', label: 'Claude Opus 5.5', strength: 'Çok adımlı derin analiz' }], policy_note: 'İzinli modeller yöneticin tarafından belirlenir · Şirket verisi AI\'a gönderilmez' } }
    const onChange = vi.fn()
    wrap(<ModelPicker value="auto" onChange={onChange} />)

    fireEvent.click(screen.getByRole('button', { name: 'Model: Auto' }))
    const panel = await screen.findByRole('dialog', { name: 'Model seçimi' })
    expect(await within(panel).findByText('Claude Opus 5.5')).toBeInTheDocument()
    expect(within(panel).getByText('Seçili')).toBeInTheDocument()
    expect(within(panel).queryByText(/OpenAI|Google|Meta|Mistral/)).not.toBeInTheDocument()
    expect(within(panel).getByText(/Şirket verisi AI'a gönderilmez/)).toBeInTheDocument()
    expect(within(panel).queryByText(/AB bölgesi|EU region/)).not.toBeInTheDocument()

    fireEvent.click(within(panel).getByRole('button', { name: /Claude Opus 5.5/ }))
    expect(onChange).toHaveBeenCalledWith('claude-opus-5-5')
  })
})

describe('Canlı iz', () => {
  const steps: TraceStep[] = [
    { id: 's1', kind: 'scope', status: 'done', label: 'İzin kapsamı', detail: 'Ayşe · User — 3 bağlı kaynaktan 2\'si kapsamda', sources: [{ key: 'ibs', label: 'IBS', in_scope: true }, { key: 'successfactors', label: 'SuccessFactors', in_scope: false }] },
    { id: 's2', kind: 'model', status: 'done', label: 'Model seçildi · Auto → Claude Sonnet 5.5', detail: 'Genel varsayılan model' },
    { id: 'tu1', kind: 'query', status: 'done', tool: 'query_apm', purpose: 'Dünkü rötarlar', source: 'APM', rows: 48, total_rows: 48, masked: true },
    { id: 's4', kind: 'ask', status: 'waiting', label: 'Bekliyor · Cevabın gerekiyor', detail: '"Kötü performans" birden fazla şekilde tanımlanabilir.' },
  ]

  it('adımlar SSE olaylarından; sorgu metni gelmediyse gösterilmez; bitince daralır', () => {
    const { rerender } = render(<LiveTrace steps={steps} running />)

    expect(screen.getByText('Düşünüyor')).toBeInTheDocument()
    expect(screen.getByText('APM sorgulanıyor · Dünkü rötarlar')).toBeInTheDocument()
    expect(screen.getByText('48 satır döndü · Kişisel bilgiler gizlendi')).toBeInTheDocument()
    expect(screen.getByText('SuccessFactors')).toHaveClass('line-through')
    expect(screen.getByText('Bekliyor · Cevabın gerekiyor')).toBeInTheDocument()
    expect(screen.queryByText(/SELECT|\/api\/legs/)).not.toBeInTheDocument()

    rerender(<LiveTrace steps={steps} running={false} durationMs={31400} onAudit={() => {}} />)
    expect(screen.getByText('31,4 sn düşündü')).toBeInTheDocument()
    expect(screen.queryByText('APM sorgulanıyor · Dünkü rötarlar')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Denetim kaydı' })).toBeInTheDocument()
  })

  it('admin ve builder sorgu metnini görür', () => {
    render(<LiveTrace steps={[{ id: 'tu1', kind: 'query', status: 'done', tool: 'query_warehouse', purpose: 'Seferler', rows: 2, query: { sql: 'SELECT flight_no FROM ires_dim_flight LIMIT 5' } }]} running />)
    expect(screen.getByText('SELECT flight_no FROM ires_dim_flight LIMIT 5')).toBeInTheDocument()
  })
})

describe('Soru kartı', () => {
  const questions = [
    { question: '"Kötü performans" nasıl tanımlansın?', context: 'Dün 212 uçuş buldum.', multi_select: true, allow_other: true, options: [{ label: 'Yük faktörü %82 hedefinin altında', source: 'Tanım' }, { label: 'Rötar oranı ortalamanın üstünde', source: 'APM' }] },
    { question: 'Kapsam?', context: null, multi_select: false, allow_other: false, options: [{ label: 'Tarifeli', source: null }, { label: 'Tümü', source: null }] },
  ]

  it('çoklu ve tekli seçim, "Başka bir şey", Devam', () => {
    const onSubmit = vi.fn()
    render(<QuestionCard questions={questions} onSubmit={onSubmit} />)

    expect(screen.getByText('1 / 2')).toBeInTheDocument()
    expect(screen.getAllByRole('checkbox')).toHaveLength(2)
    fireEvent.click(screen.getByLabelText(/Yük faktörü/))
    fireEvent.click(screen.getByLabelText(/Rötar oranı/))
    fireEvent.change(screen.getByLabelText('Başka bir şey'), { target: { value: 'charter hariç' } })
    fireEvent.click(screen.getByRole('button', { name: 'Devam' }))

    expect(screen.getByText('2 / 2')).toBeInTheDocument()
    expect(screen.getAllByRole('radio')).toHaveLength(2)
    expect(screen.getByRole('button', { name: 'Devam' })).toBeDisabled()
    fireEvent.click(screen.getByLabelText('Tümü'))
    fireEvent.click(screen.getByLabelText('Tarifeli'))
    fireEvent.click(screen.getByRole('button', { name: 'Devam' }))

    expect(onSubmit).toHaveBeenCalledWith({
      answers: [
        { question: questions[0].question, selected: ['Yük faktörü %82 hedefinin altında', 'Rötar oranı ortalamanın üstünde'], other: 'charter hariç' },
        { question: 'Kapsam?', selected: ['Tarifeli'], other: null },
      ],
    })
  })

  it('Atla', () => {
    const onSubmit = vi.fn()
    render(<QuestionCard questions={questions} onSubmit={onSubmit} />)
    fireEvent.click(screen.getByRole('button', { name: 'Atla' }))
    expect(onSubmit).toHaveBeenCalledWith({ skipped: true })
  })
})

describe('Tablo kartı', () => {
  const rows = Array.from({ length: 12 }, (_, i) => [`FH${1000 + i}`, `${i * 10}`, i === 0 ? '-12,6' : '1,0'])
  const block = { type: 'table' as const, title: 'Rötarlar · 11 Eki 2026', header: ['Sefer', 'Rötar (dk)', 'Fark'], align: ['left', 'right', 'right'] as ('left' | 'right')[], rows }

  it('kopyala TSV, CSV tarayıcıda indirilir, 10 satırdan sonrası "Tümünü göster"', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.assign(navigator, { clipboard: { writeText } })
    const createObjectURL = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    render(<TableCard block={block} />)

    expect(screen.getByText('Rötarlar · 11 Eki 2026')).toBeInTheDocument()
    expect(screen.getAllByRole('row')).toHaveLength(11)
    expect(screen.getByText('-12,6')).toHaveClass('text-bad')
    expect(screen.getByText('FH1000').closest('td')).toHaveClass('font-mono')

    fireEvent.click(screen.getByRole('button', { name: 'Tabloyu kopyala' }))
    expect(writeText).toHaveBeenCalledWith(['Sefer\tRötar (dk)\tFark', ...rows.map((r) => r.join('\t'))].join('\n'))

    fireEvent.click(screen.getByRole('button', { name: 'CSV indir' }))
    expect(createObjectURL).toHaveBeenCalled()
    expect(click).toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Tümünü göster (12)' }))
    expect(screen.getAllByRole('row')).toHaveLength(13)
    click.mockRestore()
  })

  it('tablonun üstündeki italik satır başlık olur', () => {
    const blocks = splitBlocks('Sonuç.\n\n*Rotalar · 2 Eki*\n\n| Rota | LF |\n|---|---:|\n| AYT–HAJ | %68,4 |\n\nSonra.')
    expect(blocks.map((b) => b.type)).toEqual(['markdown', 'table', 'markdown'])
    expect(blocks[1]).toMatchObject({ title: 'Rotalar · 2 Eki', header: ['Rota', 'LF'], align: ['left', 'right'], rows: [['AYT–HAJ', '%68,4']] })
  })
})

describe('Kaynak numaraları ve aksiyonlar', () => {
  it('[^q:…] → [1]; aynı sorgu aynı numara; bilinmeyen kimlik gösterilmez', () => {
    const { text, numbers } = numberCitations('A [^q:q_a]. B [^q:q_b]. C [^q:q_a]. D [^q:q_yok].', [query('q_a'), query('q_b')])
    expect(text).toBe('A[1](#cite-q_a). B[2](#cite-q_b). C[1](#cite-q_a). D.')
    expect([...numbers.entries()]).toEqual([['q_a', 1], ['q_b', 2]])
  })

  it(':::actions bloğu kartlara dönüşür, bozuk blok yok sayılır', () => {
    const { text, actions } = extractActions('Cevap.\n\n:::actions\n- title: AYT–HAJ iptallerini incele | kind: ask\n- title: Özeti Teams\'e gönder | kind: write | system: Teams\n- bozuk satır\n- title: Her sabah al | kind: automate\n:::')
    expect(text).toBe('Cevap.')
    expect(actions).toEqual([
      { title: 'AYT–HAJ iptallerini incele', kind: 'ask', system: null },
      { title: "Özeti Teams'e gönder", kind: 'write', system: 'Teams' },
      { title: 'Her sabah al', kind: 'automate', system: null },
    ])
    expect(extractActions('Cevap.\n:::actions\n- yanlış\n:::').actions).toEqual([])
    expect(extractActions('Kapanmamış :::actions\n- title: x | kind: ask').actions).toEqual([])
  })

  it('cevapta numaralar, aksiyon kartları ve bilgi satırı', () => {
    const message: Message = {
      id: 9, conversation_id: 1, role: 'assistant', stop_reason: 'end_turn', flow_cards: [], created_at: '',
      text: 'En büyük açık **AYT–HAJ** [^q:q_a]. Kanıt [^q:q_a] ve [^q:q_uydurma].\n\n:::actions\n- title: Özeti Teams\'e gönder | kind: write | system: Teams\n:::',
      answer: { trace: [], queries: [query('q_a')], models: ['claude-sonnet-5-5'], model_labels: ['Claude Sonnet 5.5'], auto: true, duration_ms: 1000, steps: 3, writes: [], citation_mismatch: ['q_uydurma'] },
    }
    const onSend = vi.fn()
    wrap(<AnswerView message={message} onSend={onSend} busy={false} />)

    expect(screen.getAllByRole('button', { name: /^Kaynak 1:/ })).toHaveLength(2)
    expect(screen.queryByRole('button', { name: /^Kaynak 2:/ })).not.toBeInTheDocument()
    expect(screen.queryByText(/q_uydurma|\[\^q/)).not.toBeInTheDocument()
    expect(screen.queryByText(':::actions')).not.toBeInTheDocument()
    expect(screen.getByText('Teams · onay gerekir')).toBeInTheDocument()
    expect(screen.getByText('Auto · Claude Sonnet 5.5')).toBeInTheDocument()
    expect(screen.getByText('Salt okunur · hiçbir sistem değiştirilmedi')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Özeti Teams'e gönder/ }))
    expect(onSend).toHaveBeenCalledWith("Özeti Teams'e gönder")

    fireEvent.click(screen.getAllByRole('button', { name: /^Kaynak 1:/ })[0])
    expect(screen.getByRole('tooltip')).toHaveTextContent('IBS · Dünkü uçuşlar')
  })
})
