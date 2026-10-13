import type { AgentCard, AgentRunRow, AgentStatus } from '../api'

export const STATUS_BADGE: Record<AgentStatus, [string, string]> = {
  live: ['Canlı', 'bg-ok-soft text-ok'],
  pilot: ['Pilot', 'bg-bad-soft text-bad'],
  draft: ['Taslak', 'bg-raised text-ink-2'],
  paused: ['Duraklatıldı', 'bg-warn-soft text-warn'],
  template: ['Şablon', 'bg-info-soft text-info'],
}

/** Kartın alt şeridi: haftalık çalışma ve % faydalı (geri bildirim yoksa gösterilmez), yoksa onay sayısı, yoksa "henüz çalışmadı". */
export function cardStrip(agent: AgentCard): string {
  if (agent.status === 'template') return '1 tıkla kur'
  const runs = agent.runs_30d ?? 0
  if (runs > 0) {
    const perWeek = agent.runs_7d ?? 0
    const quality = agent.useful_pct !== null && agent.useful_pct !== undefined ? `%${agent.useful_pct} faydalı` : agent.score_avg !== null && agent.score_avg !== undefined ? `puan ${agent.score_avg}` : null
    return [`${perWeek} çalışma/hafta`, quality].filter(Boolean).join(' · ')
  }
  if ((agent.approved_actions_30d ?? 0) > 0) return `${agent.approved_actions_30d} onay`
  return 'henüz çalışmadı'
}

export const RUN_STATUS: Record<AgentRunRow['status'], [string, string]> = {
  queued: ['Kuyrukta', 'bg-faint'],
  running: ['Çalışıyor', 'bg-info'],
  waiting_approval: ['Onay bekliyor', 'bg-warn'],
  waiting_input: ['Soru bekliyor', 'bg-warn'],
  succeeded: ['Başarılı', 'bg-ok'],
  failed: ['Hata', 'bg-bad'],
  cancelled: ['İptal', 'bg-faint'],
}

export const seconds = (n: number | null) => (n === null ? '—' : n < 60 ? `${n} sn` : `${Math.floor(n / 60)} dk ${n % 60} sn`)
export const usd = (n: number | null) => (n === null ? '—' : `$${n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: n < 1 ? 3 : 2 })}`)
export const stamp = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('tr-TR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

/** Puan rengi: 80 ve üstü iyi, 50–79 orta, altı kötü. */
export const scoreTone = (score: number | null | undefined) => (score === null || score === undefined ? 'text-muted' : score >= 80 ? 'text-ok' : score >= 50 ? 'text-warn' : 'text-bad')

/**
 * Veriyle karşılaştırma gerektiren değerlendirme kuralı mı ("Rakamlar kaynakla tutarlı", "Veriye dayanıyor"). Puanlayan
 * model veriyi görmez; bu kurallarda sadece çıktının kendi içindeki tutarlılığına bakılır.
 */
export const needsData = (rule: string) => /(kaynak|veri|sorgu|tutarl|doğrul|gerçek (değer|sayı|veri))/i.test(rule.toLocaleLowerCase('tr'))
