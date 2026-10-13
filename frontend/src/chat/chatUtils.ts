// Konuşma ekranlarının bileşen dışı yardımcıları.
import { createContext } from 'react'
import type { AnswerQuery } from '../api'

/** Cevaptaki kaynak numaralarının sorguları (kimlik → sorgu, kimlik → numara). */
export const CitationContext = createContext<{ queries: Map<string, AnswerQuery>; numbers: Map<string, number> }>({ queries: new Map(), numbers: new Map() })

/** Sorgu metni tek satır (admin ve builder'a gelir). */
export function queryText(query: Record<string, unknown>): string {
  if (typeof query.sql === 'string') return query.sql.replace(/\s+/g, ' ')
  return [query.method ?? 'GET', query.path].filter(Boolean).join(' ')
}

/** Veri kaynakları (sorgu araçları olanlar): kaynak seçicide gösterilir. */
export const DATA_SOURCES = ['ibs', 'apm', 'successfactors']

/** Türkiye saatine göre selamlama: Günaydın 05–12, İyi günler 12–18, İyi akşamlar 18–05. */
export function greeting(date: Date = new Date()): string {
  const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Europe/Istanbul' }).format(date))
  if (hour >= 5 && hour < 12) return 'Günaydın'
  if (hour >= 12 && hour < 18) return 'İyi günler'
  return 'İyi akşamlar'
}

export type ChatGroup = 'Bugün' | 'Dün' | 'Bu hafta' | 'Daha eski'
export const CHAT_GROUPS: ChatGroup[] = ['Bugün', 'Dün', 'Bu hafta', 'Daha eski']

/** Sohbetin grubu, son güncellenme tarihine göre (yerel saat). */
export function groupOf(iso: string, now: Date = new Date()): ChatGroup {
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((day(now) - day(new Date(iso))) / 86_400_000)
  if (diff <= 0) return 'Bugün'
  if (diff === 1) return 'Dün'
  if (diff < 7) return 'Bu hafta'
  return 'Daha eski'
}
