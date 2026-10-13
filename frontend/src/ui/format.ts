const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** $48.20; çok küçük tutarlarda dört basamak ($0.0042). */
export function money(value: number, digits?: number): string {
  if (digits !== undefined) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value)
  }
  return value > 0 && value < 0.01 ? `$${value.toFixed(4)}` : usd.format(value)
}

/** 7.4M, 18.4K, 950 */
export function tokens(value: number): string {
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`
  if (value >= 1_000) return `${(value / 1_000).toFixed(1)}K`
  return String(value)
}

const dateTime = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const time = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' })
const timeSeconds = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

/** "Bugün 10:42", "Dün 07:00", "29 Eyl 09:00" */
export function when(iso: string | null): string {
  if (!iso) return '—'
  const date = new Date(iso)
  const today = new Date()
  const yesterday = new Date(today.getTime() - 86_400_000)
  if (date.toDateString() === today.toDateString()) return `Bugün ${time.format(date)}`
  if (date.toDateString() === yesterday.toDateString()) return `Dün ${time.format(date)}`
  return dateTime.format(date)
}

export const clock = (iso: string | null) => (iso ? timeSeconds.format(new Date(iso)) : '—')

/** "5 dk önce" */
export function ago(iso: string | null): string {
  if (!iso) return '—'
  const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60_000)
  if (minutes < 1) return 'az önce'
  if (minutes < 60) return `${minutes} dk önce`
  return when(iso)
}

/** ISO süre (PT2H, PT30M) → "2 saat", "30 dakika" */
export function isoDuration(value: string | null): string | null {
  const m = value ? /^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/.exec(value) : null
  if (!m) return value
  const [, d, h, min, s] = m
  return [d && `${d} gün`, h && `${h} saat`, min && `${min} dakika`, s && `${s} saniye`].filter(Boolean).join(' ') || value
}
