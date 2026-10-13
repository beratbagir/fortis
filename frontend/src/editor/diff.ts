import type { DiffMark, FlowGraph, GraphNode } from './types'

const LISTS = ['triggers', 'tasks', 'errors', 'finally'] as const

/** Grafın tüm düğümleri (tetikleyiciler dahil), id → düğüm. */
export function indexGraph(graph: FlowGraph): Map<string, GraphNode> {
  const out = new Map<string, GraphNode>()
  const walk = (nodes: GraphNode[]) => {
    for (const node of nodes) {
      if (node.id) out.set(node.id, node)
      walk(node.children ?? [])
      Object.values(node.branches ?? {}).forEach(walk)
    }
  }
  LISTS.forEach((list) => walk(graph[list] ?? []))
  return out
}

/** Bloğun kendi içeriği (alt bloklar hariç): değişti mi karşılaştırması için. */
const signature = (node: GraphNode) => JSON.stringify([node.kind, node.title, node.raw_type, node.readonly, sortKeys(node.settings)])

function sortKeys(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(sortKeys)
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((k) => [k, sortKeys((value as Record<string, unknown>)[k])]))
  }
  return value
}

/**
 * İki sürüm arasındaki blok farkı: yenide olup eskide olmayanlar eklendi, eskide olup yenide olmayanlar silindi,
 * ikisinde olup içeriği farklı olanlar değişti. Taşınan blok (aynı id, aynı içerik) işaretlenmez.
 */
export function diffGraphs(base: FlowGraph, next: FlowGraph): Map<string, DiffMark> {
  const before = indexGraph(base)
  const after = indexGraph(next)
  const marks = new Map<string, DiffMark>()

  for (const [id, node] of after) {
    const old = before.get(id)
    if (!old) marks.set(id, 'added')
    else if (signature(old) !== signature(node)) marks.set(id, 'changed')
  }
  for (const id of before.keys()) {
    if (!after.has(id)) marks.set(id, 'removed')
  }

  return marks
}

/**
 * Tuvalde göstermek için yeni grafa silinen blokları da ekler: her silinen blok, eski listesindeki bir önceki
 * kardeşinin hemen arkasına (yeni grafta varsa) yerleştirilir.
 */
export function mergeForDiff(base: FlowGraph, next: FlowGraph): FlowGraph {
  const after = indexGraph(next)

  const mergeList = (oldList: GraphNode[], newList: GraphNode[]): GraphNode[] => {
    const result = newList.map((node) => {
      const old = node.id ? findIn(oldList, node.id) : undefined
      return old ? mergeNode(old, node) : node
    })

    oldList.forEach((old, index) => {
      if (old.id && after.has(old.id)) return
      const prev = oldList.slice(0, index).reverse().find((n) => n.id && result.some((r) => r.id === n.id))
      const at = prev ? result.findIndex((r) => r.id === prev.id) + 1 : 0
      result.splice(at, 0, old)
    })

    return result
  }

  const mergeNode = (old: GraphNode, node: GraphNode): GraphNode => ({
    ...node,
    children: node.children ? mergeList(old.children ?? [], node.children) : undefined,
    branches: node.branches
      ? Object.fromEntries(Object.entries(node.branches).map(([key, list]) => [key, mergeList(old.branches?.[key] ?? [], list)]))
      : undefined,
  })

  return {
    ...next,
    triggers: mergeList(base.triggers, next.triggers),
    tasks: mergeList(base.tasks, next.tasks),
    errors: mergeList(base.errors, next.errors),
    finally: mergeList(base.finally, next.finally),
  }
}

const findIn = (list: GraphNode[], id: string) => list.find((n) => n.id === id)
