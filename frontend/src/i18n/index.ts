// Arayüz metinleri. Dil Laravel'den gelir (config/app.php → locale: tr | en). Anahtarlar düz: "nav.chats".
import en from './en.json'
import tr from './tr.json'

export type Locale = 'tr' | 'en'

const dictionaries: Record<Locale, Record<string, string>> = { tr, en }
let current: Locale = 'tr'

export function setLocale(locale: Locale) {
  current = dictionaries[locale] ? locale : 'tr'
  document.documentElement.lang = current
}

export const getLocale = () => current

/** Metni seçili dilde döner; yoksa Türkçesi, o da yoksa anahtar. {name} yer tutucuları doldurulur. */
export function t(key: string, vars: Record<string, string | number> = {}): string {
  const text = dictionaries[current][key] ?? tr[key as keyof typeof tr] ?? key
  return text.replace(/\{(\w+)\}/g, (match, name: string) => (name in vars ? String(vars[name]) : match))
}

/** Tarih ve saat, arayüz diline göre. */
export const dateTime = (iso: string) => new Date(iso).toLocaleString(current === 'tr' ? 'tr-TR' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
