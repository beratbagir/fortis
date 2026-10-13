import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { artifactsApi, type ArtifactCard, type PageDetail } from '../api'
import { buildPageDocument, loadChartJs } from '../pages/pageDocument'
import { useTheme } from '../theme'
import { ChartIcon, CloseIcon, DownloadIcon, ExpandIcon, WarnIcon } from '../ui/icons'

const stamp = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString('tr-TR', { timeZone: 'Europe/Istanbul', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

const slug = (title: string) =>
  title
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase() || 'sayfa'

/**
 * Sayfanın çerçevesi (sandbox="allow-scripts", srcdoc). Üst pencereyle tek kanal postMessage: sadece bu iframe'den
 * gelen {type: "ask"} işlenir; başka kaynaktan veya bilinmeyen türdeki mesaj yok sayılır. "ask" kullanıcı
 * onaylamadan gönderilmez.
 */
function PageFrame({ page, theme, chartJs, onAsk }: { page: PageDetail; theme: 'light' | 'dark'; chartJs: string; onAsk: (text: string) => void }) {
  const frame = useRef<HTMLIFrameElement>(null)

  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      if (!frame.current || event.source !== frame.current.contentWindow) return
      const message = event.data as { type?: unknown; text?: unknown } | null
      if (message?.type === 'ask' && typeof message.text === 'string' && message.text.trim() !== '') {
        onAsk(message.text.trim().slice(0, 500))
      }
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [onAsk])

  return (
    <iframe
      ref={frame}
      title={page.title}
      sandbox="allow-scripts"
      referrerPolicy="no-referrer"
      srcDoc={buildPageDocument({ html: page.content, data: page.data, theme, chartJs })}
      className="block size-full border-0 bg-surface"
    />
  )
}

/** Sohbetteki kart: tıklanınca sayfa yan panelde açılır. */
export function PageCardView({ card, open, onOpen }: { card: ArtifactCard; open: boolean; onOpen: () => void }) {
  return (
    <button
      type="button"
      onClick={onOpen}
      aria-pressed={open}
      aria-label={`Sayfa: ${card.title}`}
      className={`flex w-full max-w-md items-center gap-3 rounded-2xl border bg-surface px-4 py-3 text-left hover:border-line-strong ${open ? 'border-brand ring-2 ring-brand/15' : 'border-line'}`}
    >
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand-fg" aria-hidden>
        <ChartIcon />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-semibold text-ink">{card.title}</span>
        {card.description && <span className="truncate text-xs text-muted">{card.description}</span>}
        <span className="font-mono text-[11px] text-muted">
          Veri: {stamp(card.data_at)} · sürüm {card.version}
        </span>
      </span>
      {card.warning && <WarnIcon className="shrink-0 text-warn" />}
      <span className="text-xs font-semibold text-brand-fg">{open ? 'Açık' : 'Aç'}</span>
    </button>
  )
}

/**
 * Sayfa paneli (sohbetin yanında; telefonda tam ekran): başlık, veri zamanı, kaynaklar, sürüm; tam ekran, yeniden
 * oluştur, HTML indir (veri ve kütüphane gömülü tek dosya).
 */
export function PagePanel({ id, version, onSend, onRevise, onClose }: { id: string; version: number; onSend: (text: string) => void; onRevise: (text: string) => void; onClose: () => void }) {
  const page = useQuery({ queryKey: ['artifact', id, version], queryFn: () => artifactsApi.page(id, version) })
  const chartJs = useQuery({ queryKey: ['vendor', 'chartjs'], queryFn: loadChartJs, staleTime: Infinity, gcTime: Infinity })
  const { resolved: theme } = useTheme()
  const [full, setFull] = useState(false)
  const [asking, setAsking] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const data = page.data

  const download = async () => {
    if (!data) return
    setError(null)
    try {
      const html = buildPageDocument({ html: data.content, data: data.data, theme: 'light', chartJs: await loadChartJs() })
      const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `${slug(data.title)}.html`
      a.click()
      URL.revokeObjectURL(url)
      artifactsApi.downloaded(data.id, data.version).catch(() => undefined)
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const sources = data ? Object.values(data.data).map((q) => [q.source, q.purpose].filter(Boolean).join(' · ')) : []
  const button = 'flex h-8 items-center gap-1 rounded-lg border border-line-strong px-2.5 text-xs font-semibold hover:bg-canvas disabled:opacity-50'

  return (
    <aside
      aria-label={`Sayfa: ${data?.title ?? ''}`}
      className={full ? 'fixed inset-0 z-50 flex flex-col bg-surface' : 'fixed inset-0 z-40 flex flex-col bg-surface lg:static lg:inset-auto lg:z-auto lg:w-[52%] lg:max-w-[1040px] lg:shrink-0 lg:border-l lg:border-line'}
    >
      <div className="flex flex-wrap items-start gap-2 border-b border-line px-4 py-2.5">
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="truncate text-sm font-semibold">{data?.title ?? 'Yükleniyor…'}</span>
          {data?.description && <span className="text-xs text-muted">{data.description}</span>}
          {data && (
            <span className="text-[11px] text-muted">
              <span className="font-mono">Veri: {stamp(data.data_at)}</span>
              {sources.length > 0 && <> · Kaynak: {sources.join('; ')}</>}
              {data.latest_version > 1 && <> · Sürüm {data.version}</>}
            </span>
          )}
        </div>
        <button type="button" onClick={() => setFull(!full)} disabled={!data} className={button}>
          <ExpandIcon /> {full ? 'Panele dön' : 'Tam ekran'}
        </button>
        <button type="button" onClick={() => onRevise(`"${data?.title ?? 'Sayfa'}" sayfasında şunu değiştir: `)} disabled={!data} className={button}>
          Yeniden oluştur
        </button>
        <button type="button" aria-label="HTML indir" title="HTML indir (veri ve kütüphane gömülü, tek dosya)" onClick={download} disabled={!data} className={button}>
          <DownloadIcon /> İndir
        </button>
        <button type="button" onClick={onClose} aria-label="Sayfayı kapat" className="flex size-8 items-center justify-center rounded-lg hover:bg-raised">
          <CloseIcon />
        </button>
      </div>

      {data?.warning && (
        <p role="note" className="m-0 flex items-center gap-2 border-b border-line bg-warn-soft px-4 py-2 text-xs text-warn">
          <WarnIcon className="shrink-0" /> {data.warning}: sayfadaki sayılar kaynak sorgulardan doğrulanamadı.
        </p>
      )}
      {(error || chartJs.isError) && (
        <p role="alert" className="m-0 border-b border-line px-4 py-2 text-xs text-bad">
          {error ?? 'Grafik kütüphanesi yüklenemedi.'}
        </p>
      )}
      {asking && (
        <div role="dialog" aria-label="Sayfadan gelen soru" className="flex flex-wrap items-center gap-2 border-b border-line bg-canvas px-4 py-2 text-[13px]">
          <span className="min-w-0 flex-1">
            Sayfa Fortis'e şunu sormak istiyor: <q className="font-medium">{asking}</q>
          </span>
          <button
            type="button"
            onClick={() => {
              onSend(asking)
              setAsking(null)
            }}
            className="rounded-lg bg-ink px-3 py-1 text-xs font-medium text-bg"
          >
            Sohbette sor
          </button>
          <button type="button" onClick={() => setAsking(null)} className="rounded-lg border border-line px-3 py-1 text-xs hover:bg-raised">
            Vazgeç
          </button>
        </div>
      )}

      <div className="min-h-0 flex-1 bg-canvas">
        {page.isError && <p className="m-4 text-sm text-bad">Sayfa yüklenemedi.</p>}
        {data && chartJs.data !== undefined && <PageFrame key={`${data.id}:${data.version}:${theme}`} page={data} theme={theme} chartJs={chartJs.data} onAsk={setAsking} />}
      </div>
    </aside>
  )
}
