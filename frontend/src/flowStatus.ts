import type { FlowSummary } from './api'

/** Liste durumu: taslak / yayında / durduruldu / hata (son çalışma hatalıysa). */
export function flowStatus(flow: FlowSummary): { label: string; className: string } {
  if (flow.status === 'draft') return { label: 'Taslak', className: 'bg-raised text-muted' }
  if (flow.status === 'inactive') return { label: 'Durduruldu', className: 'bg-warn-soft text-warn' }
  if (flow.last_execution?.state === 'FAILED') return { label: 'Hata', className: 'bg-bad-soft text-bad' }
  return { label: 'Yayında', className: 'bg-ok-soft text-ok' }
}
