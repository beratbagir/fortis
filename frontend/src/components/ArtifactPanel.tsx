import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { artifactsApi, type ArtifactCard, type ArtifactKind } from '../api'
import { CloseIcon } from '../ui/icons'

/**
 * Önizleme Fortis'ten yalıtılmıştır: iframe sandbox'ında allow-same-origin yok (Fortis'in çerezine ve API'sine
 * erişemez) ve CSP ile sadece bilinen CDN'lerden kod yüklenebilir, dışarıya istek atılamaz.
 */
const CDNS = 'https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com'
const CSP = [
  "default-src 'none'",
  `script-src 'unsafe-inline' 'unsafe-eval' ${CDNS}`,
  `style-src 'unsafe-inline' ${CDNS} https://fonts.googleapis.com`,
  `font-src data: ${CDNS} https://fonts.gstatic.com`,
  `img-src data: blob: ${CDNS}`,
  "media-src data: blob:",
  "connect-src 'none'",
  "form-action 'none'",
].join('; ')
const CSP_META = `<meta http-equiv="Content-Security-Policy" content="${CSP}">`

const KIND_LABELS: Record<ArtifactKind, string> = { html: 'Arayüz', svg: 'Görsel', mermaid: 'Diyagram', markdown: 'Belge' }

/** İçeriği iframe'e verilecek tam belgeye çevirir; CSP her zaman <head>'in başına girer. */
function buildDocument(kind: ArtifactKind, content: string): string {
  if (kind === 'svg') {
    return `<!doctype html><html><head>${CSP_META}<meta charset="utf-8"><style>html,body{margin:0;height:100%;display:flex;align-items:center;justify-content:center;background:white}svg{max-width:100%;max-height:100%}</style></head><body>${content}</body></html>`
  }
  if (kind === 'mermaid') {
    const escaped = content.replace(/&/g, '&amp;').replace(/</g, '&lt;')
    return `<!doctype html><html><head>${CSP_META}<meta charset="utf-8"><style>body{margin:0;padding:24px;font-family:sans-serif;background:white}</style><script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script></head><body><pre class="mermaid">${escaped}</pre><script>mermaid.initialize({startOnLoad:true,securityLevel:'strict'})</script></body></html>`
  }
  const head = content.match(/<head[^>]*>/i)
  if (head) return content.replace(head[0], `${head[0]}${CSP_META}`)
  const html = content.match(/<html[^>]*>/i)
  if (html) return content.replace(html[0], `${html[0]}<head>${CSP_META}</head>`)
  return `<!doctype html><html><head>${CSP_META}<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>${content}</body></html>`
}

const EXTENSIONS: Record<ArtifactKind, [string, string]> = {
  html: ['html', 'text/html'],
  svg: ['svg', 'image/svg+xml'],
  mermaid: ['mmd', 'text/plain'],
  markdown: ['md', 'text/markdown'],
}

/** Sohbetteki kart: tıklanınca panelde açar. */
export function ArtifactCardView({ card, open, onOpen }: { card: ArtifactCard; open: boolean; onOpen: () => void }) {
  return (
    <button
      type="button"
      onClick={onOpen}
      aria-pressed={open}
      className={`flex w-full max-w-md items-center gap-3 rounded-2xl border bg-surface px-4 py-3 text-left hover:border-line-strong ${open ? 'border-brand ring-2 ring-brand/15' : 'border-line'}`}
    >
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-lg" aria-hidden>
        {card.kind === 'html' ? '🖥' : card.kind === 'markdown' ? '📄' : card.kind === 'mermaid' ? '🔀' : '🎨'}
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-semibold text-ink">{card.title}</span>
        <span className="text-xs text-muted">
          {KIND_LABELS[card.kind]} · sürüm {card.version}
        </span>
      </span>
      <span className="text-xs font-semibold text-brand-fg">{open ? 'Açık' : 'Aç'}</span>
    </button>
  )
}

export function ArtifactPanel({ id, version, onVersion, onClose }: { id: string; version: number | null; onVersion: (v: number) => void; onClose: () => void }) {
  const artifact = useQuery({ queryKey: ['artifact', id, version], queryFn: () => artifactsApi.get(id, version ?? undefined) })
  const [tab, setTab] = useState<'preview' | 'code'>('preview')
  const [copied, setCopied] = useState(false)
  const data = artifact.data

  const download = () => {
    if (!data) return
    const [ext, type] = EXTENSIONS[data.kind]
    const url = URL.createObjectURL(new Blob([data.content], { type }))
    const a = document.createElement('a')
    a.href = url
    a.download = `${data.title.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '') || 'artifact'}.${ext}`
    a.click()
    URL.revokeObjectURL(url)
  }

  const copy = async () => {
    if (!data) return
    await navigator.clipboard.writeText(data.content)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1500)
  }

  return (
    <aside
      aria-label="Önizleme"
      className="fixed inset-0 z-40 flex flex-col bg-surface lg:static lg:inset-auto lg:z-auto lg:w-[48%] lg:max-w-[920px] lg:shrink-0 lg:border-l lg:border-line"
    >
      <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
        <div className="flex min-w-0 flex-1 flex-col">
          <span className="truncate text-sm font-semibold">{data?.title ?? 'Yükleniyor…'}</span>
          {data && <span className="text-xs text-muted">{KIND_LABELS[data.kind]}</span>}
        </div>
        {data && data.versions.length > 1 && (
          <label className="flex items-center gap-1 text-xs text-muted">
            <span className="sr-only">Sürüm</span>
            <select value={data.version} onChange={(e) => onVersion(Number(e.target.value))} className="h-8 rounded-lg border border-line-strong bg-surface px-1.5 text-xs text-ink">
              {data.versions.map((v) => (
                <option key={v.number} value={v.number}>
                  Sürüm {v.number}
                  {v.number === data.latest_version ? ' (son)' : ''}
                </option>
              ))}
            </select>
          </label>
        )}
        <div role="tablist" aria-label="Görünüm" className="flex gap-1 rounded-lg bg-raised p-0.5">
          {(['preview', 'code'] as const).map((key) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={tab === key}
              onClick={() => setTab(key)}
              className={`h-7 rounded-md px-2.5 text-xs font-semibold ${tab === key ? 'bg-surface text-ink shadow-sm' : 'text-muted'}`}
            >
              {key === 'preview' ? 'Önizleme' : 'Kod'}
            </button>
          ))}
        </div>
        <button type="button" onClick={copy} disabled={!data} className="h-8 rounded-lg border border-line-strong px-2.5 text-xs font-semibold hover:bg-canvas">
          {copied ? 'Kopyalandı' : 'Kopyala'}
        </button>
        <button type="button" onClick={download} disabled={!data} className="h-8 rounded-lg border border-line-strong px-2.5 text-xs font-semibold hover:bg-canvas">
          İndir
        </button>
        <button type="button" onClick={onClose} aria-label="Önizlemeyi kapat" className="flex size-8 items-center justify-center rounded-lg hover:bg-raised">
          <CloseIcon />
        </button>
      </div>

      <div className="min-h-0 flex-1 bg-canvas">
        {artifact.error && <p className="m-4 text-sm text-bad">{(artifact.error as Error).message}</p>}
        {data && tab === 'code' && (
          <pre className="m-0 h-full overflow-auto bg-surface p-4 font-mono text-xs leading-relaxed text-ink">
            <code>{data.content}</code>
          </pre>
        )}
        {data && tab === 'preview' && data.kind === 'markdown' && (
          <div className="prose prose-slate h-full max-w-none overflow-auto bg-surface px-6 py-5 text-sm">
            <Markdown remarkPlugins={[remarkGfm]}>{data.content}</Markdown>
          </div>
        )}
        {data && tab === 'preview' && data.kind !== 'markdown' && (
          <iframe
            key={`${data.id}:${data.version}`}
            title={data.title}
            sandbox="allow-scripts allow-modals allow-popups"
            srcDoc={buildDocument(data.kind, data.content)}
            className="size-full border-0 bg-surface"
          />
        )}
      </div>
    </aside>
  )
}
