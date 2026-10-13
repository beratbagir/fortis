import type { InboxItem, InboxTab, InboxType } from '../api'
import { intlLocale, t } from '../i18n'

const TAB_KEYS: InboxTab[] = ['all', 'action', 'mentions', 'updates', 'done']

export const inboxTabs = (): { key: InboxTab; label: string }[] => TAB_KEYS.map((key) => ({ key, label: t(`inbox.tab.${key}`) }))

export const typeLabel = (type: InboxType) => t(`inbox.type.${type}`)

export const TYPE_TONE: Record<InboxType, string> = {
  approval: 'bg-brand-soft text-brand-fg',
  question: 'bg-info-soft text-info',
  update: 'bg-raised text-ink-2',
  alert: 'bg-bad-soft text-bad',
  mention: 'bg-accent-soft text-accent',
  shared: 'bg-raised text-ink-2',
}

/** Liste grupları, arayüz dilinde ("Bugün" / "Today"…); sırayla. */
export type InboxGroup = string
export const inboxGroups = (): InboxGroup[] => [t('inbox.group.today'), t('inbox.group.yesterday'), t('inbox.group.older')]

export function inboxGroup(iso: string | null, now: Date = new Date()): InboxGroup {
  if (!iso) return t('inbox.group.older')
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((day(now) - day(new Date(iso))) / 86_400_000)
  return diff <= 0 ? t('inbox.group.today') : diff === 1 ? t('inbox.group.yesterday') : t('inbox.group.older')
}

/** Satır ikonu: öğenin kendi kısaltması, yoksa başlığın baş harfleri (2 harf). */
export function initials(item: Pick<InboxItem, 'icon' | 'title'>): string {
  if (item.icon) return item.icon.slice(0, 2).toLocaleUpperCase('tr')
  const words = item.title.split(/\s+/).filter((w) => /\p{L}/u.test(w))
  return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '?').slice(0, 2)).toLocaleUpperCase('tr')
}

/** Satırın ana düğmesi: onay → Onayla/İncele, soru → Cevapla, diğerleri → Aç. */
export function primaryLabel(item: InboxItem): string {
  if (!item.actionable) return t('inbox.primary.open')
  return item.type === 'question' ? t('inbox.primary.answer') : t('inbox.primary.review')
}

export const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString(intlLocale(), { hour: '2-digit', minute: '2-digit' }) : '')

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
