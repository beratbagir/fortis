import { intlLocale } from '../i18n'

/** Dolar gösterimi (arayüz dilinin sayı biçimi). */
export const usd = (n: number | null | undefined, digits = 2) =>
  n === null || n === undefined ? '—' : `$${n.toLocaleString(intlLocale(), { minimumFractionDigits: digits, maximumFractionDigits: Math.max(digits, n > 0 && n < 0.01 ? 4 : digits) })}`

/** Kredi gösterimi: ayarlarda kredi değeri tanımlıysa (hesap her zaman dolardan). */
export const credits = (costUsd: number, creditUsdValue: number) => Math.round(costUsd / creditUsdValue).toLocaleString(intlLocale())
