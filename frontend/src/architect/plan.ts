import type { PlanStep, PlanStepType } from '../api'

export const STEP_LABEL: Record<PlanStepType, string> = {
  trigger: 'TETİKLEYİCİ',
  read: 'OKU',
  lookup: 'ARA',
  decide: 'KARAR',
  draft: 'TASLAK',
  approve: 'ONAY',
  act: 'GÖNDER',
}

const SYSTEM_ABBR: [RegExp, string][] = [
  [/\bAPM\b/i, 'AP'],
  [/\bIBS\b/i, 'IB'],
  [/SuccessFactors/i, 'SF'],
  [/Teams/i, 'TM'],
  [/e-posta|outlook|mail/i, 'OL'],
]

const TYPE_ABBR: Record<PlanStepType, string> = { trigger: 'TR', read: 'OK', lookup: 'AR', decide: '?', draft: 'AI', approve: 'ON', act: 'GN' }

/** Kart ikonu: adımda geçen sistem (APM → AP), yoksa adım türü. */
export function stepBadge(step: PlanStep): string {
  if (step.type === 'decide' || step.type === 'draft' || step.type === 'approve') return TYPE_ABBR[step.type]
  const text = `${step.title} ${step.detail ?? ''}`
  return SYSTEM_ABBR.find(([pattern]) => pattern.test(text))?.[1] ?? TYPE_ABBR[step.type]
}
