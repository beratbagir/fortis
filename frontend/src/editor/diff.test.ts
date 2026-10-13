import { describe, expect, it } from 'vitest'
import { sampleGraph } from '../test/fixtures'
import { diffGraphs, mergeForDiff } from './diff'

describe('öneri farkı', () => {
  it('eklenen, silinen ve değişen bloklar doğru işaretlenir', () => {
    const base = sampleGraph()
    const next = sampleGraph()

    // Değişti: eşik
    next.tasks[0].settings = { path: '/api/legs', params: { min: '180' } }
    // Eklendi: döngüye Teams bildirimi
    next.tasks[1].children!.push({ id: 'notify_teams', kind: 'notify_teams', label: 'Bildirim · Teams', title: null, settings: { message: 'x' }, readonly: false, raw_type: null, writes: true })
    // Silindi: koşul dalındaki adım
    next.tasks[1].children![0].branches!.then = []

    const marks = diffGraphs(base, next)

    expect(Object.fromEntries(marks)).toEqual({ get_delays: 'changed', notify_teams: 'added', send_mail: 'removed' })
  })

  it('içeriği aynı kalan veya sadece alt blokları değişen konteyner işaretlenmez', () => {
    const base = sampleGraph()
    const next = sampleGraph()
    next.tasks[1].children!.pop()

    const marks = diffGraphs(base, next)

    expect(marks.get('notify_each')).toBeUndefined()
    expect(marks.get('log_delay')).toBe('removed')
    expect(diffGraphs(base, sampleGraph()).size).toBe(0)
  })

  it('silinen bloklar tuvalde eski yerlerinde gösterilir', () => {
    const base = sampleGraph()
    const next = sampleGraph()
    next.tasks.splice(0, 1)
    next.tasks[0].children!.splice(1, 1)

    const merged = mergeForDiff(base, next)

    expect(merged.tasks.map((n) => n.id)).toEqual(['get_delays', 'notify_each', 'remember'])
    expect(merged.tasks[1].children!.map((n) => n.id)).toEqual(['is_long', 'log_delay'])
  })
})
