const DAYS = ['pazar', 'pazartesi', 'salı', 'çarşamba', 'perşembe', 'cuma', 'cumartesi', 'pazar']

const pad = (n: string) => n.padStart(2, '0')

/** Basit cron ifadelerini Türkçeye çevirir; tanımadığını olduğu gibi bırakır. */
export function describeCron(cron: string): string {
  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) return cron
  const [minute, hour, dom, month, dow] = parts

  const every = minute.match(/^\*\/(\d+)$/)
  if (every && hour === '*' && dom === '*' && month === '*' && dow === '*') return `${every[1]} dakikada bir`
  if (/^\d+$/.test(minute) && hour === '*' && dom === '*' && month === '*' && dow === '*') return 'Saatte bir'

  if (!/^\d+$/.test(minute) || !/^\d+$/.test(hour) || month !== '*') return cron
  const time = `${pad(hour)}:${pad(minute)}`

  if (dom === '*' && dow === '*') return `Her gün ${time}`
  if (dom === '*' && (dow === '1-5' || dow === 'MON-FRI')) return `Hafta içi her gün ${time}`
  if (dom === '*' && /^[0-7]$/.test(dow)) return `Her ${DAYS[Number(dow)]} ${time}`
  if (/^\d+$/.test(dom) && dow === '*') return `Her ayın ${dom}. günü ${time}`
  return cron
}

/** Kart tetikleyici metnini ("Zamanlanmış: 0 8 * * 1 (Europe/Istanbul)") kısa haliyle gösterir. */
export function describeTrigger(trigger: string): string {
  const scheduled = trigger.match(/^Zamanlanmış: (.+?) \((.+)\)$/)
  if (scheduled) return describeCron(scheduled[1])
  if (trigger === 'Webhook ile tetiklenir') return 'Webhook ile'
  if (trigger === 'Elle çalıştırılır') return 'Elle'
  return trigger
}
