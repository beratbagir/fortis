import type { Tone } from './types'

/** Blok ikon kutusunun arka plan ve yazı rengi. Değerler tema token'larında (index.css, --tone-*); kontrast ≥ 4.5:1. */
const TONE_NAMES: Tone[] = ['navy', 'teal', 'blue', 'orange', 'pink', 'amber', 'cyan', 'slate', 'rose', 'green', 'red']

export const TONES = Object.fromEntries(TONE_NAMES.map((t) => [t, { bg: `var(--tone-${t}-bg)`, fg: `var(--tone-${t}-fg)` }])) as Record<Tone, { bg: string; fg: string }>

export const tone = (t: Tone | undefined) => TONES[t ?? 'slate'] ?? TONES.slate
