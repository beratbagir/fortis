/** Zamanlama seçicisi: gün/saat seçimi ↔ cron. Seçiciye uymayan ifadeler "gelişmiş" olarak düzenlenir. */
export type ScheduleMode = 'daily' | 'weekdays' | 'weekly' | 'custom'

export interface ScheduleParts {
  mode: ScheduleMode
  /** 0 = pazar … 6 = cumartesi (sadece weekly) */
  day: number
  /** HH:MM */
  time: string
}

export const WEEK_DAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi']

const pad = (n: number) => String(n).padStart(2, '0')

export function parseSchedule(cron: string): ScheduleParts {
  const parts = cron.trim().split(/\s+/)
  const fallback: ScheduleParts = { mode: 'custom', day: 1, time: '06:00' }
  if (parts.length !== 5) return fallback
  const [minute, hour, dom, month, dow] = parts
  if (!/^\d{1,2}$/.test(minute) || !/^\d{1,2}$/.test(hour) || dom !== '*' || month !== '*') return fallback
  const time = `${pad(Number(hour))}:${pad(Number(minute))}`
  if (dow === '*') return { mode: 'daily', day: 1, time }
  if (dow === '1-5') return { mode: 'weekdays', day: 1, time }
  if (/^[0-6]$/.test(dow)) return { mode: 'weekly', day: Number(dow), time }
  return { ...fallback, time }
}

export function buildCron({ mode, day, time }: ScheduleParts): string {
  const [hour, minute] = time.split(':').map(Number)
  const base = `${minute || 0} ${hour || 0} * *`
  if (mode === 'weekdays') return `${base} 1-5`
  if (mode === 'weekly') return `${base} ${day}`
  return `${base} *`
}
