// Arayüz metinleri. Varsayılan dil İngilizce; her kullanıcı kendi dilini seçer (profil menüsü → users.locale).
// Girişten önce sunucu tarayıcının dilini söyler (/api/auth/config). Anahtarlar düz: "nav.chats", "governance.policies.title".
// Sözlükler: en.json / tr.json (genel) + alan dosyaları en/*.json, tr/*.json (ekran başına).
import enBase from './en.json'
import trBase from './tr.json'

export type Locale = 'en' | 'tr'

export const LOCALES: { id: Locale; label: string }[] = [
  { id: 'en', label: 'English' },
  { id: 'tr', label: 'Türkçe' },
]

const merge = (base: Record<string, string>, files: Record<string, { default: Record<string, string> }>) =>
  Object.values(files).reduce<Record<string, string>>((all, file) => ({ ...all, ...file.default }), { ...base })

const dictionaries: Record<Locale, Record<string, string>> = {
  en: merge(enBase, import.meta.glob('./en/*.json', { eager: true })),
  tr: merge(trBase, import.meta.glob('./tr/*.json', { eager: true })),
}

let current: Locale = 'en'

export function setLocale(locale: string | null | undefined) {
  current = locale === 'tr' ? 'tr' : 'en'
  document.documentElement.lang = current
}

export const getLocale = () => current

/** Intl biçimleri için dil etiketi: tr-TR veya en-GB (gün ay yıl sırası, 24 saat). */
export const intlLocale = () => (current === 'tr' ? 'tr-TR' : 'en-GB')

/** Metni seçili dilde döner; yoksa İngilizcesi, o da yoksa anahtar. {name} yer tutucuları doldurulur. */
export function t(key: string, vars: Record<string, string | number> = {}): string {
  const text = dictionaries[current][key] ?? dictionaries.en[key] ?? key
  return text.replace(/\{(\w+)\}/g, (match, name: string) => (name in vars ? String(vars[name]) : match))
}

/** Sayı (dil biçiminde). */
export const formatNumber = (n: number, digits = 1) => new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: digits }).format(n)

/** Yüzde: Türkçede %82,4, İngilizcede 82.4%. */
export const formatPercent = (n: number, digits = 1) => (current === 'tr' ? `%${formatNumber(n, digits)}` : `${formatNumber(n, digits)}%`)

/** Tarih ve saat, arayüz diline göre. */
export const dateTime = (iso: string) => new Date(iso).toLocaleString(intlLocale(), { dateStyle: 'medium', timeStyle: 'short' })
