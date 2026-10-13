import { describe, expect, it } from 'vitest'
import { defs, sampleGraph } from '../test/fixtures'
import { layoutGraph, nearestSlot, type BlockData, type SlotData } from './layout'

const block = (nodes: ReturnType<typeof layoutGraph>, id: string) => nodes.find((n) => n.id === `block:${id}`)!

describe('graf → React Flow düğümleri', () => {
  it('konteyner blokların çocukları grup kutusunun içinde gösterilir', () => {
    const nodes = layoutGraph(sampleGraph(), { defs })
    const loop = block(nodes, 'notify_each')
    const condition = block(nodes, 'is_long')

    expect(loop.type).toBe('container')
    expect(condition.type).toBe('container')

    const inside = (child: (typeof nodes)[number], parent: (typeof nodes)[number]) =>
      child.position.x >= parent.position.x &&
      child.position.y > parent.position.y &&
      child.position.x + child.width! <= parent.position.x + parent.width! &&
      child.position.y + child.height! <= parent.position.y + parent.height!

    expect(inside(condition, loop)).toBe(true)
    expect(inside(block(nodes, 'log_delay'), loop)).toBe(true)
    expect(inside(block(nodes, 'send_mail'), condition)).toBe(true)
    expect(inside(block(nodes, 'remember'), loop)).toBe(false)

    // Kutu çocuklarından önce gelir ki altta çizilsin.
    expect(nodes.indexOf(loop)).toBeLessThan(nodes.indexOf(condition))
  })

  it('bloklar yukarıdan aşağı sıralanır ve aralarında ekleme noktaları vardır', () => {
    const nodes = layoutGraph(sampleGraph(), { defs })
    const ys = ['every_15_min', 'get_delays', 'notify_each', 'remember'].map((id) => block(nodes, id).position.y)

    expect(ys).toEqual([...ys].sort((a, b) => a - b))

    const points = nodes.filter((n) => n.type === 'slot').map((n) => (n.data as SlotData).point)
    expect(points).toContainEqual({ parentId: null, branch: 'tasks', afterId: 'get_delays' })
    expect(points).toContainEqual({ parentId: 'is_long', branch: 'else', afterId: null })
    expect(points).toContainEqual({ parentId: 'notify_each', branch: 'tasks', afterId: 'log_delay' })
  })

  it('gelişmiş blok salt okunur, Koşul dalları etiketli', () => {
    const nodes = layoutGraph(sampleGraph(), { defs })

    expect((block(nodes, 'remember').data as BlockData).fields).toEqual([{ k: 'Tür', v: 'kv.Set' }])
    expect((block(nodes, 'is_long').data as BlockData).branchLabels.map((b) => b.label)).toEqual(['Doğruysa', 'Değilse'])
  })

  it('422 hatası ilgili bloğa bağlanır', () => {
    const nodes = layoutGraph(sampleGraph(), { defs, errors: [{ task_id: 'get_delays', message: 'APM\'de böyle bir uç yok' }, { task_id: null, message: 'genel' }] })

    expect((block(nodes, 'get_delays').data as BlockData).errors).toEqual(["APM'de böyle bir uç yok"])
    expect((block(nodes, 'log_delay').data as BlockData).errors).toEqual([])
  })

  it('sürüklenen blok en yakın ekleme noktasına bırakılır, kendi arkasındaki noktaya değil', () => {
    const nodes = layoutGraph(sampleGraph(), { defs })
    const after = nodes.find((n) => n.type === 'slot' && (n.data as SlotData).point.afterId === 'get_delays')!
    const center = { x: after.position.x + 13, y: after.position.y + 29 }

    expect(nearestSlot(nodes, center, 'remember')).toEqual({ parentId: null, branch: 'tasks', afterId: 'get_delays' })
    expect(nearestSlot(nodes, center, 'get_delays')).toBeNull()
  })
})
