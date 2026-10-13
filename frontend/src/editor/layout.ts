import type { Node } from '@xyflow/react'
import type { BlockDef, DiffMark, EditError, FlowGraph, GraphNode, InsertPoint } from './types'

// Ölçüler BlockNode'daki sabit yüksekliklerle aynı olmalı (kart: 14 dolgu, 38 başlık, satır başına 33 + 6 boşluk).
export const BLOCK_W = 360
const CARD_PAD = 14
const HEADER_H = 38
const FIELD_H = 33
const FIELD_GAP = 6
const FIELDS_GAP = 12
export const SLOT_H = 58
const GROUP_PAD = 14
const BRANCH_GAP = 24
const BRANCH_LABEL_H = 26
const TRIGGER_GAP = 12
const SECTION_LABEL_H = 40
export const MAX_FIELDS = 3

export interface CardField {
  k: string
  v: string
}

export interface BlockData extends Record<string, unknown> {
  node: GraphNode
  def: BlockDef | undefined
  fields: CardField[]
  mark: DiffMark | null
  errors: string[]
  selected: boolean
  isTrigger: boolean
  container: boolean
  branchLabels: { key: string; label: string; x: number; y: number }[]
}

export interface SlotData extends Record<string, unknown> {
  point: InsertPoint
  active: boolean
  dropTarget: boolean
  disabled: boolean
  label: string
}

export interface LabelData extends Record<string, unknown> {
  text: string
}

export interface LayoutOptions {
  defs: Map<string, BlockDef>
  marks?: Map<string, DiffMark>
  errors?: EditError[]
  selectedId?: string | null
  activePoint?: InsertPoint | null
  dropPoint?: InsertPoint | null
  readOnly?: boolean
}

const samePoint = (a: InsertPoint | null | undefined, b: InsertPoint) =>
  !!a && a.parentId === b.parentId && a.branch === b.branch && a.afterId === b.afterId

/** Kartta gösterilen en fazla üç ayar: şemadaki sırayla, boş olmayanlar. */
export function cardFields(node: GraphNode, def: BlockDef | undefined): CardField[] {
  if (node.readonly) {
    return [{ k: 'Tür', v: node.raw_type?.split('.').slice(-2).join('.') ?? 'bilinmiyor' }]
  }

  const props = def?.settings_schema.properties ?? {}
  const fields: CardField[] = []

  for (const [key, schema] of Object.entries(props)) {
    const value = node.settings?.[key]
    if (value === null || value === undefined || value === '' || (typeof value === 'object' && Object.keys(value as object).length === 0)) continue

    let text: string
    if (schema.enum && schema.enumNames) text = schema.enumNames[schema.enum.indexOf(String(value))] ?? String(value)
    else if (Array.isArray(value)) text = value.join(', ')
    else if (typeof value === 'object') text = Object.entries(value as Record<string, unknown>).map(([k, v]) => `${k}=${v}`).join(', ')
    else text = String(value)

    fields.push({ k: schema.title ?? key, v: text.replace(/\s+/g, ' ') })
    if (fields.length === MAX_FIELDS) break
  }

  return fields
}

export function blockHeight(fieldCount: number): number {
  const fields = fieldCount === 0 ? 0 : FIELDS_GAP + fieldCount * FIELD_H + (fieldCount - 1) * FIELD_GAP
  return CARD_PAD * 2 + HEADER_H + fields + 2
}

interface Size {
  w: number
  h: number
}

function branchesOf(node: GraphNode, def: BlockDef | undefined): { key: string; label: string; list: GraphNode[] }[] {
  if (node.children) return [{ key: 'tasks', label: '', list: node.children }]
  if (node.branches) {
    return (def?.branches ?? Object.keys(node.branches)).map((key) => ({
      key,
      label: def?.branch_labels?.[key] ?? key,
      list: node.branches?.[key] ?? [],
    }))
  }
  return []
}

/**
 * Grafı React Flow düğümlerine çevirir. Akış dikey ve ağaç yapısındadır: her listede bloklar yukarıdan aşağı,
 * aralarında "+" ekleme noktaları. Döngü ve Koşul grup kutusu olarak çizilir, alt blokları içinde (Koşulun
 * dalları yan yana). Konumlar mutlak; ortada x = 0.
 */
export function layoutGraph(graph: FlowGraph, options: LayoutOptions): Node[] {
  const { defs, marks = new Map(), errors = [], selectedId = null, activePoint = null, dropPoint = null, readOnly = false } = options
  const nodes: Node[] = []
  const sizes = new Map<GraphNode, Size>()
  const errorMap = new Map<string, string[]>()
  for (const e of errors) if (e.task_id) errorMap.set(e.task_id, [...(errorMap.get(e.task_id) ?? []), e.message])

  const fieldsOf = (node: GraphNode) => cardFields(node, defs.get(node.kind))

  const measure = (node: GraphNode): Size => {
    const cached = sizes.get(node)
    if (cached) return cached
    const def = defs.get(node.kind)
    const header = blockHeight(fieldsOf(node).length)
    const branches = branchesOf(node, def)
    let size: Size

    if (branches.length === 0) {
      size = { w: BLOCK_W, h: header }
    } else {
      const measured = branches.map((b) => measureList(b.list))
      const inner = measured.reduce((sum, m) => sum + m.w, 0) + BRANCH_GAP * (measured.length - 1)
      const labelH = branches.length > 1 ? BRANCH_LABEL_H : 0
      size = {
        w: Math.max(BLOCK_W + GROUP_PAD * 2, inner + GROUP_PAD * 2),
        h: GROUP_PAD + header + labelH + Math.max(...measured.map((m) => m.h)) + GROUP_PAD,
      }
    }

    sizes.set(node, size)
    return size
  }

  const measureList = (list: GraphNode[]): Size => ({
    w: Math.max(BLOCK_W, ...list.map((n) => measure(n).w)),
    h: SLOT_H + list.reduce((sum, n) => sum + measure(n).h + SLOT_H, 0),
  })

  const slot = (point: InsertPoint, cx: number, y: number, label: string) => {
    nodes.push({
      id: `slot:${point.parentId ?? 'root'}:${point.branch}:${point.afterId ?? '^'}`,
      type: 'slot',
      position: { x: cx - 13, y },
      data: { point, active: samePoint(activePoint, point), dropTarget: samePoint(dropPoint, point), disabled: readOnly, label } satisfies SlotData,
      draggable: false,
      selectable: false,
      width: 26,
      height: SLOT_H,
    })
  }

  const placeBlock = (node: GraphNode, cx: number, y: number, isTrigger = false) => {
    const def = defs.get(node.kind)
    const size = measure(node)
    const branches = branchesOf(node, def)
    const container = branches.length > 0
    const left = cx - size.w / 2
    const branchLabels: BlockData['branchLabels'] = []

    nodes.push({
      id: `block:${node.id ?? Math.random()}`,
      type: container ? 'container' : 'block',
      position: { x: left, y },
      width: size.w,
      height: size.h,
      zIndex: container ? -1 : 1,
      draggable: !readOnly && !isTrigger && !container && node.id !== null && marks.get(node.id) !== 'removed',
      data: {
        node,
        def,
        fields: fieldsOf(node),
        mark: node.id ? (marks.get(node.id) ?? null) : null,
        errors: node.id ? (errorMap.get(node.id) ?? []) : [],
        selected: node.id !== null && node.id === selectedId,
        isTrigger,
        container,
        branchLabels,
      } satisfies BlockData,
    })

    // Kutu çocuklarından önce eklenir ki altta çizilsin (iç içe konteynerler görünür kalır).
    if (container) {
      const header = blockHeight(fieldsOf(node).length)
      const measured = branches.map((b) => measureList(b.list))
      const inner = measured.reduce((sum, m) => sum + m.w, 0) + BRANCH_GAP * (measured.length - 1)
      let x = cx - inner / 2
      const labelH = branches.length > 1 ? BRANCH_LABEL_H : 0
      const top = y + GROUP_PAD + header + labelH

      branches.forEach((branch, i) => {
        const bcx = x + measured[i].w / 2
        if (branches.length > 1) branchLabels.push({ key: branch.key, label: branch.label, x: bcx - left, y: header + GROUP_PAD })
        placeList(branch.list, node.id, branch.key, bcx, top, `"${node.title ?? node.id}" ${branch.label ? branch.label.toLocaleLowerCase('tr') + ' dalının' : 'içinin'}`)
        x += measured[i].w + BRANCH_GAP
      })
    }
  }

  const placeList = (list: GraphNode[], parentId: string | null, branch: string, cx: number, y: number, where: string) => {
    let cursor = y
    slot({ parentId, branch, afterId: null }, cx, cursor, `${where} başına blok ekle`)
    cursor += SLOT_H

    for (const node of list) {
      placeBlock(node, cx, cursor)
      cursor += measure(node).h
      if (node.id && marks.get(node.id) !== 'removed') {
        slot({ parentId, branch, afterId: node.id }, cx, cursor, `"${node.title ?? node.id}" adımından sonra blok ekle`)
      }
      cursor += SLOT_H
    }

    return cursor
  }

  // Tetikleyiciler
  let y = 0
  if (graph.triggers.length === 0) {
    nodes.push({ id: 'label:manual', type: 'label', position: { x: -BLOCK_W / 2, y }, width: BLOCK_W, height: 40, draggable: false, selectable: false, data: { text: 'Tetikleyici yok · elle veya testle çalışır' } })
    y += 40
  }
  graph.triggers.forEach((trigger, i) => {
    if (i > 0) y += TRIGGER_GAP
    placeBlock(trigger, 0, y, true)
    y += measure(trigger).h
  })

  y = placeList(graph.tasks, null, 'tasks', 0, y, 'Akışın')

  for (const [list, text] of [['errors', 'Hata olursa'], ['finally', 'Her durumda en sonda']] as const) {
    if (graph[list].length === 0) continue
    y += 20
    nodes.push({ id: `label:${list}`, type: 'label', position: { x: -BLOCK_W / 2, y }, width: BLOCK_W, height: SECTION_LABEL_H, draggable: false, selectable: false, data: { text } })
    y += SECTION_LABEL_H
    y = placeList(graph[list], null, list, 0, y, `"${text}" bölümünün`)
  }

  // React Flow sürüklenemeyen/seçilemeyen düğümlerde tıklamayı kapatır; düğümlerdeki düğmeler çalışsın.
  return nodes.map((node) => ({ ...node, style: { ...node.style, pointerEvents: 'all' } }))
}

/** Sürüklenen bloğun bırakılabileceği en yakın ekleme noktası (bloğun kendi çevresi hariç). */
export function nearestSlot(nodes: Node[], center: { x: number; y: number }, draggedId: string, maxDistance = 90): InsertPoint | null {
  let best: { point: InsertPoint; d: number } | null = null

  for (const node of nodes) {
    if (node.type !== 'slot') continue
    const { point } = node.data as SlotData
    if (point.afterId === draggedId) continue
    const d = Math.hypot(node.position.x + 13 - center.x, node.position.y + SLOT_H / 2 - center.y)
    if (d <= maxDistance && (!best || d < best.d)) best = { point, d }
  }

  return best?.point ?? null
}
