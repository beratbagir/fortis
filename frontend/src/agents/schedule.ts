import { getLocale, intlLocale, t } from '../i18n'

const pad = (n: string) => n.padStart(2, '0')

/** Gün adı (0 ve 7 = pazar); Türkçede cümle içinde küçük harf ("Her pazartesi"). */
function dayName(dow: number): string {
  const name = new Date(2026, 0, 4 + (dow % 7)).toLocaleDateString(intlLocale(), { weekday: 'long' })
  return getLocale() === 'tr' ? name.toLocaleLowerCase('tr') : name
}

/** Basit cron ifadelerini arayüz diline çevirir; tanımadığını olduğu gibi bırakır. */
export function describeCron(cron: string): string {
  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) return cron
  const [minute, hour, dom, month, dow] = parts

  const every = minute.match(/^\*\/(\d+)$/)
  if (every && hour === '*' && dom === '*' && month === '*' && dow === '*') return t('agents.cron.every_minutes', { n: every[1] })
  if (/^\d+$/.test(minute) && hour === '*' && dom === '*' && month === '*' && dow === '*') return t('agents.cron.hourly')

  if (!/^\d+$/.test(minute) || !/^\d+$/.test(hour) || month !== '*') return cron
  const time = `${pad(hour)}:${pad(minute)}`

  if (dom === '*' && dow === '*') return t('agents.cron.daily', { time })
  if (dom === '*' && (dow === '1-5' || dow === 'MON-FRI')) return t('agents.cron.weekdays', { time })
  if (dom === '*' && /^[0-7]$/.test(dow)) return t('agents.cron.weekly', { day: dayName(Number(dow)), time })
  if (/^\d+$/.test(dom) && dow === '*') return t('agents.cron.monthly', { dom, time })
  return cron
}

/** Kart tetikleyici metnini ("Zamanlanmış: 0 8 * * 1 (Europe/Istanbul)") kısa haliyle gösterir. Sunucu metni Türkçe veya İngilizce olabilir. */
export function describeTrigger(trigger: string): string {
  const scheduled = trigger.match(/^(?:Zamanlanmış|Scheduled): (.+?) \((.+)\)$/)
  if (scheduled) return describeCron(scheduled[1])
  if (trigger === 'Webhook ile tetiklenir' || trigger === 'Triggered by webhook') return t('agents.trigger.webhook')
  if (trigger === 'Elle çalıştırılır' || trigger === 'Run manually') return t('agents.trigger.manual')
  return trigger
}
