import type { FlowInput } from './api'
import { intlLocale, t } from './i18n'

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
  if (h) parts.push(t('format.duration.h', { n: h }))
  if (min) parts.push(t('format.duration.m', { n: min }))
  if (sec) parts.push(t('format.duration.s', { n: Number(sec).toLocaleString(intlLocale(), { maximumFractionDigits: 1 }) }))
  return parts.join(' ') || t('format.duration.s', { n: 0 })
}

const EXECUTION_STATES: Record<string, { className: string }> = {
  CREATED: { className: 'bg-raised text-ink-2' },
  QUEUED: { className: 'bg-raised text-ink-2' },
  SUBMITTED: { className: 'bg-raised text-ink-2' },
  RUNNING: { className: 'bg-info-soft text-info' },
  RESTARTED: { className: 'bg-info-soft text-info' },
  RETRYING: { className: 'bg-info-soft text-info' },
  KILLING: { className: 'bg-raised text-ink-2' },
  PAUSED: { className: 'bg-warn-soft text-warn' },
  SUCCESS: { className: 'bg-ok-soft text-ok' },
  WARNING: { className: 'bg-warn-soft text-warn' },
  FAILED: { className: 'bg-bad-soft text-bad' },
  KILLED: { className: 'bg-line text-ink-2' },
  CANCELLED: { className: 'bg-line text-ink-2' },
  SKIPPED: { className: 'bg-line text-ink-2' },
  RETRIED: { className: 'bg-line text-ink-2' },
}

/** Kestra çalışma durumunun etiketi (arayüz dilinde) ve rozet rengi. */
export function executionState(state: string | null): { label: string; className: string } {
  const known = EXECUTION_STATES[state ?? '']
  return known ? { label: t(`format.state.${state}`), className: known.className } : { label: state ?? '—', className: 'bg-raised text-ink-2' }
}

const TRIGGERS = ['Schedule', 'Webhook', 'Elle']

export function triggerLabel(trigger: string): string {
  return TRIGGERS.includes(trigger) ? t(`format.trigger.${trigger}`) : trigger
}
