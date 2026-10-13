// Cevap metnini ekrana hazırlar: kaynak numaraları ([^q:ID] → [1]), önerilen aksiyonlar (:::actions bloğu) ve
// kanıt tabloları (üstündeki italik satır başlık olur).
import type { AnswerQuery } from '../api'

export interface SuggestedAction {
  title: string
  kind: 'ask' | 'automate' | 'write'
  system: string | null
}

export type AnswerBlock =
  | { type: 'markdown'; text: string }
  | { type: 'table'; title: string | null; header: string[]; align: ('left' | 'right' | 'center')[]; rows: string[][] }

const ACTIONS = /:::actions\s*\n([\s\S]*?)\n\s*:::/

/** :::actions bloğunu çıkarır. Bozuk satırlar yok sayılır; blok tamamen bozuksa aksiyon yok. */
export function extractActions(text: string): { text: string; actions: SuggestedAction[] } {
  const match = text.match(ACTIONS)
  if (!match) return { text, actions: [] }

  const actions: SuggestedAction[] = []
  for (const line of match[1].split('\n')) {
    const fields = Object.fromEntries(
      line
        .replace(/^\s*-\s*/, '')
        .split('|')
        .map((part) => part.split(':'))
        .filter((pair) => pair.length >= 2)
        .map(([key, ...value]) => [key.trim().toLowerCase(), value.join(':').trim()]),
    )
    if (fields.title && ['ask', 'automate', 'write'].includes(fields.kind)) {
      actions.push({ title: fields.title, kind: fields.kind as SuggestedAction['kind'], system: fields.system || null })
    }
  }

  return { text: text.replace(ACTIONS, '').trimEnd(), actions: actions.slice(0, 3) }
}

/**
 * [^q:ID] kimliklerini görünme sırasına göre numaralandırır ve markdown bağlantısına çevirir (#cite-ID).
 * Bu cevapta çalışmamış bir kimlik (sorgular listesinde yok) gösterilmez.
 */
export function numberCitations(text: string, queries: AnswerQuery[]): { text: string; numbers: Map<string, number> } {
  const known = new Set(queries.map((q) => q.query_id))
  const numbers = new Map<string, number>()

  const out = text.replace(/\s?\[\^q:([A-Za-z0-9_]+)\]/g, (_, id: string) => {
    if (!known.has(id)) return ''
    if (!numbers.has(id)) numbers.set(id, numbers.size + 1)
    return `[${numbers.get(id)}](#cite-${id})`
  })

  return { text: out, numbers }
}

const isSeparator = (line: string) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line)
const cells = (line: string) => line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim())
const isTitle = (line: string) => /^\s*(\*[^*].*[^*]\*|_[^_].*[^_]_)\s*$/.test(line)

/** Markdown'ı metin ve tablo bloklarına ayırır. Tablonun hemen üstündeki italik satır tablo başlığı olur. */
export function splitBlocks(text: string): AnswerBlock[] {
  const lines = text.split('\n')
  const blocks: AnswerBlock[] = []
  let buffer: string[] = []

  const flush = () => {
    const md = buffer.join('\n').trim()
    if (md !== '') blocks.push({ type: 'markdown', text: md })
    buffer = []
  }

  for (let i = 0; i < lines.length; i++) {
    if (lines[i].trim().startsWith('|') && i + 1 < lines.length && isSeparator(lines[i + 1])) {
      // Başlık: tablodan önceki son dolu satır italikse.
      let title: string | null = null
      while (buffer.length > 0 && buffer[buffer.length - 1].trim() === '') buffer.pop()
      if (buffer.length > 0 && isTitle(buffer[buffer.length - 1])) {
        title = buffer.pop()!.trim().replace(/^[*_]|[*_]$/g, '')
      }
      flush()

      const header = cells(lines[i])
      const align = cells(lines[i + 1]).map((c) => (c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : 'left')) as ('left' | 'right' | 'center')[]
      const rows: string[][] = []
      i += 2
      while (i < lines.length && lines[i].trim().startsWith('|')) {
        rows.push(cells(lines[i]))
        i++
      }
      i--
      blocks.push({ type: 'table', title, header, align, rows })
    } else {
      buffer.push(lines[i])
    }
  }
  flush()

  return blocks
}

/** Hücrenin düz metni (kopyalama ve CSV için): markdown işaretleri ve kaynak numaraları çıkarılır. */
export function plainCell(cell: string): string {
  return cell
    .replace(/\[(\d+)\]\(#cite-[^)]+\)/g, '')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/(\*\*|__|\*|_|`)/g, '')
    .trim()
}

export function toTsv(header: string[], rows: string[][]): string {
  return [header, ...rows].map((row) => row.map(plainCell).join('\t')).join('\n')
}

export function toCsv(header: string[], rows: string[][]): string {
  const quote = (v: string) => (/[",\n;]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
  return [header, ...rows].map((row) => row.map((c) => quote(plainCell(c))).join(',')).join('\n')
}
