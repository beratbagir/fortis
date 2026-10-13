import type { FlowSummary } from './api'
import { t } from './i18n'

/** Liste durumu: taslak / yayında / durduruldu / hata (son çalışma hatalıysa). */
export function flowStatus(flow: FlowSummary): { label: string; className: string } {
  if (flow.status === 'draft') return { label: t('format.flow.draft'), className: 'bg-raised text-muted' }
  if (flow.status === 'inactive') return { label: t('format.flow.inactive'), className: 'bg-warn-soft text-warn' }
  if (flow.last_execution?.state === 'FAILED') return { label: t('format.flow.failed'), className: 'bg-bad-soft text-bad' }
  return { label: t('format.flow.live'), className: 'bg-ok-soft text-ok' }
}
