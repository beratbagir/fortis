import { describe, expect, it } from 'vitest'
import { describeCron, describeTrigger } from './schedule'

describe('tetikleyici metni', () => {
  it.each([
    ['0 8 * * *', 'Her gün 08:00'],
    ['30 8 * * *', 'Her gün 08:30'],
    ['0 8 * * 1', 'Her pazartesi 08:00'],
    ['0 9 * * 1-5', 'Hafta içi her gün 09:00'],
    ['*/15 * * * *', '15 dakikada bir'],
    ['0 6 1 * *', 'Her ayın 1. günü 06:00'],
    ['0 8 * 1 *', '0 8 * 1 *'],
  ])('%s → %s', (cron, text) => expect(describeCron(cron)).toBe(text))

  it('kart metinlerini kısaltır', () => {
    expect(describeTrigger('Zamanlanmış: 0 8 * * 1 (Europe/Istanbul)')).toBe('Her pazartesi 08:00')
    expect(describeTrigger('Webhook ile tetiklenir')).toBe('Webhook ile')
    expect(describeTrigger('Elle çalıştırılır')).toBe('Elle')
  })
})
