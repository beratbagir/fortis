import type { AgentCard, AgentRunRow, AgentStatus } from '../api'
import { formatPercent, intlLocale, t } from '../i18n'

const STATUS_TONE: Record<AgentStatus, string> = {
  live: 'bg-ok-soft text-ok',
  pilot: 'bg-bad-soft text-bad',
  draft: 'bg-raised text-ink-2',
  paused: 'bg-warn-soft text-warn',
  template: 'bg-info-soft text-info',
}

/** Durum rozeti: [etiket, ton]. */
export const statusBadge = (status: AgentStatus): [string, string] => [t(`agent.status.${status}`), STATUS_TONE[status]]

/** Kartın alt şeridi: haftalık çalışma ve % faydalı (geri bildirim yoksa gösterilmez), yoksa onay sayısı, yoksa "henüz çalışmadı". */
export function cardStrip(agent: AgentCard): string {
  if (agent.status === 'template') return t('agent.strip.template')
  const runs = agent.runs_30d ?? 0
  if (runs > 0) {
    const perWeek = agent.runs_7d ?? 0
    const quality =
      agent.useful_pct !== null && agent.useful_pct !== undefined
        ? t('agent.strip.useful', { pct: formatPercent(agent.useful_pct, 0) })
        : agent.score_avg !== null && agent.score_avg !== undefined
          ? t('agent.strip.score', { score: agent.score_avg })
          : null
    return [t('agent.strip.per_week', { n: perWeek }), quality].filter(Boolean).join(' · ')
  }
  if ((agent.approved_actions_30d ?? 0) > 0) return t('agent.strip.approvals', { n: agent.approved_actions_30d ?? 0 })
  return t('agent.strip.never_run')
}

const RUN_DOT: Record<AgentRunRow['status'], string> = {
  queued: 'bg-faint',
  running: 'bg-info',
  waiting_approval: 'bg-warn',
  waiting_input: 'bg-warn',
  succeeded: 'bg-ok',
  failed: 'bg-bad',
  cancelled: 'bg-faint',
}

export const RUN_STATUSES = Object.keys(RUN_DOT) as AgentRunRow['status'][]

/** Çalışma durumu: [etiket, nokta rengi]. */
export const runStatus = (status: AgentRunRow['status']): [string, string] => [t(`agent.run_status.${status}`), RUN_DOT[status]]

export const seconds = (n: number | null) => (n === null ? '—' : n < 60 ? t('agent.format.seconds', { n }) : t('agent.format.minutes_seconds', { m: Math.floor(n / 60), s: n % 60 }))
export const usd = (n: number | null) => (n === null ? '—' : `$${n.toLocaleString(intlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: n < 1 ? 3 : 2 })}`)
export const stamp = (iso: string | null) => (iso ? new Date(iso).toLocaleString(intlLocale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/** Puan rengi: 80 ve üstü iyi, 50–79 orta, altı kötü. */
export const scoreTone = (score: number | null | undefined) => (score === null || score === undefined ? 'text-muted' : score >= 80 ? 'text-ok' : score >= 50 ? 'text-warn' : 'text-bad')

/**
 * Veriyle karşılaştırma gerektiren değerlendirme kuralı mı ("Rakamlar kaynakla tutarlı", "Veriye dayanıyor"). Puanlayan
 * model veriyi görmez; bu kurallarda sadece çıktının kendi içindeki tutarlılığına bakılır.
 */
export const needsData = (rule: string) =>
  /(kaynak|veri|sorgu|tutarl|doğrul|gerçek (değer|sayı|veri)|\bsource|\bdata\b|\bquer|consisten|verif|actual (value|number|data))/i.test(rule.toLocaleLowerCase('tr'))
