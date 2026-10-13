import type { FlowInput } from './api'

/** Girdilerin başlangıç değerleri: BOOL için varsayılan, diğerleri boş (boşsa Kestra varsayılanı kullanır). */
export function initialValues(inputs: FlowInput[]): Record<string, unknown> {
  return Object.fromEntries(
    inputs.filter((i) => i.type === 'BOOL' || i.type === 'BOOLEAN').map((i) => [i.id, Boolean(i.defaults)]),
  )
}

/** "PT1.234S", "PT2M3S" → "1,2 sn", "2 dk 3 sn" */
export function formatDuration(iso: string | null): string {
  if (!iso) return ''
  const m = /^PT(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?$/.exec(iso)
  if (!m) return iso
  const [, h, min, sec] = m
  const parts = []
  if (h) parts.push(`${h} sa`)
  if (min) parts.push(`${min} dk`)
  if (sec) parts.push(`${Number(sec).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} sn`)
  return parts.join(' ') || '0 sn'
}

const EXECUTION_STATES: Record<string, { label: string; className: string }> = {
  CREATED: { label: 'Sırada', className: 'bg-raised text-ink-2' },
  QUEUED: { label: 'Sırada', className: 'bg-raised text-ink-2' },
  SUBMITTED: { label: 'Sırada', className: 'bg-raised text-ink-2' },
  RUNNING: { label: 'Çalışıyor', className: 'bg-info-soft text-info' },
  RESTARTED: { label: 'Çalışıyor', className: 'bg-info-soft text-info' },
  RETRYING: { label: 'Yeniden deneniyor', className: 'bg-info-soft text-info' },
  KILLING: { label: 'Durduruluyor', className: 'bg-raised text-ink-2' },
  PAUSED: { label: 'Onay bekliyor', className: 'bg-warn-soft text-warn' },
  SUCCESS: { label: 'Başarılı', className: 'bg-ok-soft text-ok' },
  WARNING: { label: 'Uyarıyla bitti', className: 'bg-warn-soft text-warn' },
  FAILED: { label: 'Hata', className: 'bg-bad-soft text-bad' },
  KILLED: { label: 'Durduruldu', className: 'bg-line text-ink-2' },
  CANCELLED: { label: 'İptal edildi', className: 'bg-line text-ink-2' },
  SKIPPED: { label: 'Atlandı', className: 'bg-line text-ink-2' },
  RETRIED: { label: 'Yeniden denendi', className: 'bg-line text-ink-2' },
}

/** Kestra çalışma durumunun Türkçe etiketi ve rozet rengi. */
export function executionState(state: string | null): { label: string; className: string } {
  return EXECUTION_STATES[state ?? ''] ?? { label: state ?? '—', className: 'bg-raised text-ink-2' }
}

const TRIGGER_LABELS: Record<string, string> = { Schedule: 'Zamanlayıcı', Webhook: 'Webhook', Elle: 'Elle' }

export function triggerLabel(trigger: string): string {
  return TRIGGER_LABELS[trigger] ?? trigger
}
