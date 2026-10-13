import { useContext, useState, type ReactNode } from 'react'
import Markdown, { type Components } from 'react-markdown'
import remarkGfm from 'remark-gfm'
import type { AnswerMeta, AnswerQuery } from '../api'
import { Dialog } from '../ui/Dialog'
import { CopyIcon, DownloadIcon, ExpandIcon, LockIcon } from '../ui/icons'
import { toCsv, toTsv, type AnswerBlock, type SuggestedAction } from './answerText'
import { CitationContext, queryText } from './chatUtils'


const count = (n: number) => n.toLocaleString('tr-TR')

/** [1] gibi kaynak numarası; tıklanınca/odaklanınca kaynak penceresi. */
function Citation({ id }: { id: string }) {
  const { queries, numbers } = useContext(CitationContext)
  const [open, setOpen] = useState(false)
  const query = queries.get(id)
  const n = numbers.get(id)
  if (!query || !n) return null

  return (
    <span className="relative inline-block">
      <button
        type="button"
        aria-label={`Kaynak ${n}: ${query.source}, ${query.purpose}`}
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        onBlur={() => setOpen(false)}
        className="mx-0.5 align-super font-mono text-[10px] text-brand-fg hover:underline"
      >
        [{n}]
      </button>
      {open && (
        <span role="tooltip" className="absolute bottom-full left-0 z-20 mb-1 block w-72 rounded-lg border border-line bg-surface p-3 text-left text-xs leading-relaxed text-ink-2 shadow-[0_8px_24px_var(--shadow)]">
          <span className="block font-medium text-ink">
            {query.source} · {query.purpose}
          </span>
          <span className="block text-muted">
            {count(query.rows)} satır{query.total_rows > query.rows ? ` (toplam ${count(query.total_rows)})` : ''}
            {query.masked ? ' · Kişisel bilgiler gizlendi' : ''}
            {query.at ? ` · ${new Date(query.at).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })}` : ''}
          </span>
          {query.query && <span className="mt-1.5 block truncate font-mono text-[11px] text-faint">{queryText(query.query)}</span>}
        </span>
      )}
    </span>
  )
}

const MARKDOWN: Components = {
  a: ({ href, children }) => (href?.startsWith('#cite-') ? <Citation id={href.slice(6)} /> : <a href={href} target="_blank" rel="noreferrer">{children}</a>),
  table: ({ children }) => (
    <div className="table-scroll">
      <table>{children}</table>
    </div>
  ),
}

/** Cevap metni: kaynak numaraları ve kayan tablolarla. */
export function AnswerMarkdown({ text }: { text: string }) {
  return (
    <Markdown remarkPlugins={[remarkGfm]} components={MARKDOWN}>
      {text}
    </Markdown>
  )
}

export function InlineMarkdown({ text }: { text: string }) {
  return (
    <Markdown remarkPlugins={[remarkGfm]} components={{ ...MARKDOWN, p: ({ children }) => <>{children}</> }}>
      {text}
    </Markdown>
  )
}

const ROW_LIMIT = 10
const isCode = (value: string) => /^[A-Z0-9]{2,4}([–-][A-Z0-9]{2,4})?$|^[A-Z]{2}\d{3,4}$|^TC-[A-Z]{3}$/.test(value.trim())
const isNegative = (value: string) => /^[-−]\s?\d/.test(value.trim().replace(/^%/, ''))

/** Kanıt tablosu: başlık, kopyala (TSV), CSV indir (tarayıcıda), büyüt; 10 satırdan sonrası "Tümünü göster". */
export function TableCard({ block }: { block: Extract<AnswerBlock, { type: 'table' }> }) {
  const [all, setAll] = useState(false)
  const [expanded, setExpanded] = useState(false)
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    await navigator.clipboard?.writeText(toTsv(block.header, block.rows))
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  const download = () => {
    const blob = new Blob(['﻿' + toCsv(block.header, block.rows)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${(block.title ?? 'tablo').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').toLowerCase() || 'tablo'}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  const table = (rows: string[][]) => (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="bg-canvas text-left text-xs text-muted">
            {block.header.map((h, i) => (
              <th key={i} className={`border-b border-line px-3 py-2 font-medium whitespace-nowrap ${block.align[i] === 'right' ? 'text-right' : ''}`}>
                <InlineMarkdown text={h} />
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, r) => (
            <tr key={r} className="border-b border-line last:border-b-0">
              {row.map((cell, c) => (
                <td
                  key={c}
                  className={`px-3 py-2 align-top ${block.align[c] === 'right' ? 'text-right font-mono tabular-nums whitespace-nowrap' : ''} ${isCode(cell) ? 'font-mono text-xs' : ''} ${isNegative(cell) ? 'text-bad' : ''}`}
                >
                  <InlineMarkdown text={cell} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  const visible = all ? block.rows : block.rows.slice(0, ROW_LIMIT)

  return (
    <figure className="m-0 overflow-hidden rounded-xl border border-line bg-surface" aria-label={block.title ?? 'Tablo'}>
      <figcaption className="flex items-center gap-2 border-b border-line px-3 py-2">
        <span className="flex-1 truncate text-xs text-muted">{block.title}</span>
        <button type="button" aria-label="Tabloyu kopyala" title={copied ? 'Kopyalandı' : 'Kopyala'} onClick={copy} className="rounded-md p-1.5 text-muted hover:bg-raised hover:text-ink">
          <CopyIcon />
        </button>
        <button type="button" aria-label="CSV indir" title="CSV indir" onClick={download} className="rounded-md p-1.5 text-muted hover:bg-raised hover:text-ink">
          <DownloadIcon />
        </button>
        <button type="button" aria-label="Tabloyu büyüt" title="Büyüt" onClick={() => setExpanded(true)} className="rounded-md p-1.5 text-muted hover:bg-raised hover:text-ink">
          <ExpandIcon />
        </button>
      </figcaption>
      {table(visible)}
      {!all && block.rows.length > ROW_LIMIT && (
        <button type="button" onClick={() => setAll(true)} className="w-full border-t border-line py-2 text-xs text-brand-fg hover:bg-raised">
          Tümünü göster ({block.rows.length})
        </button>
      )}
      {expanded && (
        <Dialog title={block.title ?? 'Tablo'} wide onClose={() => setExpanded(false)}>
          {table(block.rows)}
        </Dialog>
      )}
    </figure>
  )
}

const ACTION_HINT: Record<SuggestedAction['kind'], string> = {
  ask: 'Sohbette sor',
  automate: 'Düzenli hale getir',
  write: 'onay gerekir',
}

/**
 * Önerilen aksiyon kartları. Tıklanınca sohbete mesaj olarak yazılır; kendiliğinden bir şey yapmaz. "Düzenli hale getir"
 * builder/admin için Architect'i açar (agent taslağı, kurulum yine kullanıcının onayıyla).
 */
export function ActionCards({ actions, onSend, onAutomate, disabled }: { actions: SuggestedAction[]; onSend: (text: string) => void; onAutomate?: (title: string) => void; disabled: boolean }) {
  if (actions.length === 0) return null

  const message = (a: SuggestedAction) => (a.kind === 'automate' ? `${a.title}. Bunu düzenli olarak otomatik almak istiyorum.` : a.title)

  return (
    <section aria-label="Önerilen aksiyonlar" className="flex flex-col gap-2">
      <h3 className="m-0 text-[13px] font-medium text-ink">Önerilen aksiyonlar</h3>
      <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        {actions.map((a, i) => (
          <button
            key={i}
            type="button"
            disabled={disabled}
            onClick={() => (a.kind === 'automate' && onAutomate ? onAutomate(a.title) : onSend(message(a)))}
            className="flex flex-col gap-0.5 rounded-lg border border-line bg-surface px-3 py-2 text-left hover:border-line-strong hover:bg-canvas disabled:opacity-60"
          >
            <span className="text-[13px] font-medium text-ink">{a.title}</span>
            <span className="text-[11px] text-muted">{a.kind === 'write' ? `${a.system ?? 'Dış sistem'} · ${ACTION_HINT.write}` : ACTION_HINT[a.kind]}</span>
          </button>
        ))}
      </div>
    </section>
  )
}

/** Bilgi satırı: kaynak sayısı, kullanılan model(ler), salt okunur mu. */
export function InfoLine({ answer, onSources }: { answer: AnswerMeta; onSources: () => void }) {
  const labels = answer.model_labels ?? answer.models
  const model = labels.length === 0 ? null : `${answer.auto ? 'Auto · ' : ''}${labels.join(' → ')}`

  return (
    <p className="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-muted">
      {answer.queries.length > 0 && (
        <button type="button" onClick={onSources} className="rounded-full border border-line px-2 py-0.5 hover:bg-raised">
          {answer.queries.length} kaynak
        </button>
      )}
      {model && <span>{model}</span>}
      <span aria-hidden>·</span>
      <span className="inline-flex items-center gap-1">
        <LockIcon />
        {answer.writes.length === 0 ? 'Salt okunur · hiçbir sistem değiştirilmedi' : answer.writes.join(', ')}
      </span>
    </p>
  )
}

export function SourcesDialog({ queries, numbers, onClose }: { queries: AnswerQuery[]; numbers: Map<string, number>; onClose: () => void }): ReactNode {
  return (
    <Dialog title="Kaynaklar" onClose={onClose}>
      <ol className="m-0 flex list-none flex-col gap-3 p-0">
        {queries.map((q) => (
          <li key={q.query_id} className="flex gap-3 text-sm">
            <span className="w-6 shrink-0 font-mono text-xs text-brand-fg">{numbers.has(q.query_id) ? `[${numbers.get(q.query_id)}]` : '–'}</span>
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="font-medium">
                {q.source} · {q.purpose}
              </span>
              <span className="text-xs text-muted">
                {count(q.rows)} satır{q.masked ? ' · Kişisel bilgiler gizlendi' : ''}
              </span>
              {q.query && <span className="truncate font-mono text-[11px] text-faint">{queryText(q.query)}</span>}
            </span>
          </li>
        ))}
      </ol>
    </Dialog>
  )
}
