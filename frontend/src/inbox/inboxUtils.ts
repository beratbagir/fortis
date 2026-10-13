import type { InboxItem, InboxTab, InboxType } from '../api'

export const INBOX_TABS: { key: InboxTab; label: string }[] = [
  { key: 'all', label: 'Tümü' },
  { key: 'action', label: 'Aksiyon gerekiyor' },
  { key: 'mentions', label: 'Bahsedilenler' },
  { key: 'updates', label: 'Güncellemeler' },
  { key: 'done', label: 'Tamamlananlar' },
]

export const TYPE_LABEL: Record<InboxType, string> = {
  approval: 'Onay',
  question: 'Soru',
  update: 'Güncelleme',
  alert: 'Uyarı',
  mention: 'Bahsedilme',
  shared: 'Paylaşım',
}

export const TYPE_TONE: Record<InboxType, string> = {
  approval: 'bg-brand-soft text-brand-fg',
  question: 'bg-info-soft text-info',
  update: 'bg-raised text-ink-2',
  alert: 'bg-bad-soft text-bad',
  mention: 'bg-accent-soft text-accent',
  shared: 'bg-raised text-ink-2',
}

export type InboxGroup = 'Bugün' | 'Dün' | 'Daha eski'
export const INBOX_GROUPS: InboxGroup[] = ['Bugün', 'Dün', 'Daha eski']

export function inboxGroup(iso: string | null, now: Date = new Date()): InboxGroup {
  if (!iso) return 'Daha eski'
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((day(now) - day(new Date(iso))) / 86_400_000)
  return diff <= 0 ? 'Bugün' : diff === 1 ? 'Dün' : 'Daha eski'
}

/** Satır ikonu: öğenin kendi kısaltması, yoksa başlığın baş harfleri (2 harf). */
export function initials(item: Pick<InboxItem, 'icon' | 'title'>): string {
  if (item.icon) return item.icon.slice(0, 2).toLocaleUpperCase('tr')
  const words = item.title.split(/\s+/).filter((w) => /\p{L}/u.test(w))
  return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '?').slice(0, 2)).toLocaleUpperCase('tr')
}

/** Satırın ana düğmesi: onay → Onayla/İncele, soru → Cevapla, diğerleri → Aç. */
export function primaryLabel(item: InboxItem): string {
  if (!item.actionable) return 'Aç'
  return item.type === 'question' ? 'Cevapla' : 'İncele'
}

export const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }) : '')

/** E-posta listesi: virgül/boşlukla ayrılmış metin → adresler. */
export const parseRecipients = (text: string) =>
  text
    .split(/[,;\s]+/)
    .map((s) => s.trim())
    .filter(Boolean)

/** Alıcı izinli mi (sunucudaki kuralın aynısı): izinli alan adında olmalı; agent'ın alıcı listesi varsa o listede de olmalı. */
export function recipientAllowed(email: string, allowed: string[], domains: string[]): boolean {
  const address = email.trim().toLocaleLowerCase('en')
  const domain = /^[^@\s]+@([^@\s]+)$/.exec(address)?.[1] ?? ''
  if (!domains.some((d) => domain === d.toLocaleLowerCase('en'))) return false
  return allowed.length === 0 || allowed.map((a) => a.toLocaleLowerCase('en')).includes(address)
}
