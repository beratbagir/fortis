import { useQuery, useQueryClient } from '@tanstack/react-query'
import { applyNodeChanges, Background, Controls, ReactFlow, ReactFlowProvider, type Node, type NodeChange } from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../api'
import { AddBlockPanel, type Chip } from '../editor/AddBlockPanel'
import { ApiError, conversationApi, editorApi, usageApi, type EditResult } from '../editor/api'
import { ChatPanel } from '../editor/ChatPanel'
import { diffGraphs, mergeForDiff } from '../editor/diff'
import { BLOCK_W, layoutGraph, nearestSlot, SLOT_H, type SlotData } from '../editor/layout'
import { CanvasContext } from '../editor/canvasContext'
import { nodeTypes } from '../editor/nodeTypes'
import { SettingsPanel } from '../editor/SettingsPanel'
import { tone } from '../editor/tones'
import type { BlockDef, EditError, FlowGraph, GraphNode, InsertPoint, Operation, Settings } from '../editor/types'
import { useSettingsSaver } from '../editor/useSettingsSaver'
import { href, navigate } from '../router'
import { useTheme } from '../theme'
import { PlayIcon } from '../ui/icons'

const YamlDialog = lazy(() => import('../editor/YamlDialog'))
const VersionsDialog = lazy(() => import('../editor/VersionsDialog'))

/** Bloğun bulunduğu liste ve sırası. */
function locate(graph: FlowGraph, id: string): { parentId: string | null; branch: string; list: GraphNode[]; index: number } | null {
  const search = (list: GraphNode[], parentId: string | null, branch: string): ReturnType<typeof locate> => {
    for (const [index, node] of list.entries()) {
      if (node.id === id) return { parentId, branch, list, index }
      if (node.children) {
        const found = search(node.children, node.id, 'tasks')
        if (found) return found
      }
      for (const [key, sub] of Object.entries(node.branches ?? {})) {
        const found = search(sub, node.id, key)
        if (found) return found
      }
    }
    return null
  }

  for (const branch of ['tasks', 'errors', 'finally'] as const) {
    const found = search(graph[branch], null, branch)
    if (found) return found
  }
  return null
}

function findNode(graph: FlowGraph, id: string | null): { node: GraphNode; trigger: boolean } | null {
  if (!id) return null
  const trigger = graph.triggers.find((t) => t.id === id)
  if (trigger) return { node: trigger, trigger: true }
  const found = locate(graph, id)
  return found ? { node: found.list[found.index], trigger: false } : null
}

function describePoint(graph: FlowGraph, point: InsertPoint | 'trigger'): string {
  if (point === 'trigger') return 'Tetikleyici olarak'
  if (point.afterId) {
    const after = findNode(graph, point.afterId)?.node
    return `“${after?.title ?? point.afterId}” adımından sonra`
  }
  if (point.parentId) {
    const parent = findNode(graph, point.parentId)?.node
    return `“${parent?.title ?? point.parentId}” içinde, en başa`
  }
  return point.branch === 'tasks' ? 'Akışın başına' : 'Bölümün başına'
}

const PALETTE: { label: string; glyph: string; tone: BlockDef['tone']; chip?: Chip; kind?: string }[] = [
  { label: 'Tetikleyici', glyph: 'ZM', tone: 'navy', chip: 'Tetikleyici' },
  { label: 'Veri', glyph: 'API', tone: 'teal', chip: 'Havayolu verisi' },
  { label: 'Koşul', glyph: 'IF', tone: 'blue', kind: 'if' },
  { label: 'Döngü', glyph: 'DÖN', tone: 'blue', kind: 'foreach' },
  { label: 'Bildirim', glyph: '@', tone: 'orange', chip: 'Microsoft 365' },
  { label: 'Onay', glyph: 'OK', tone: 'navy', kind: 'pause' },
  { label: 'AI adımı', glyph: 'AI', tone: 'rose', chip: 'AI' },
]

export default function FlowEditorPage({ flowId }: { flowId: number }) {
  return (
    <ReactFlowProvider>
      <Editor flowId={flowId} />
    </ReactFlowProvider>
  )
}

function Editor({ flowId }: { flowId: number }) {
  const theme = useTheme().resolved
  const queryClient = useQueryClient()
  const flowQuery = useQuery({ queryKey: ['editor', flowId], queryFn: () => editorApi.flow(flowId) })
  const catalogQuery = useQuery({ queryKey: ['catalog'], queryFn: editorApi.catalog, staleTime: 60_000 })
  const conversationQuery = useQuery({ queryKey: ['editor-conversation', flowId], queryFn: () => editorApi.editorConversation(flowId), staleTime: Infinity })
  const settingsQuery = useQuery({ queryKey: ['ai-settings'], queryFn: usageApi.settings, staleTime: 60_000 })

  const flow = flowQuery.data
  const defs = useMemo(() => new Map((catalogQuery.data?.blocks ?? []).map((b) => [b.kind, b])), [catalogQuery.data])

  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [tab, setTab] = useState<'settings' | 'chat'>('settings')
  const [adding, setAdding] = useState<{ point: InsertPoint | 'trigger'; chip: Chip } | null>(null)
  const [draft, setDraft] = useState<{ kind: string; point: InsertPoint | 'trigger' } | null>(null)
  const [errors, setErrors] = useState<EditError[]>([])
  const [conflict, setConflict] = useState(false)
  const [saving, setSaving] = useState(0)
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [dialog, setDialog] = useState<'yaml' | 'versions' | null>(null)
  const [notice, setNotice] = useState<{ text: string; tone: 'ok' | 'bad'; link?: { href: string; label: string } } | null>(null)
  const [outbox, setOutbox] = useState<{ id: number; text: string } | null>(null)
  const [menuOpen, setMenuOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  // Tuval dışından gelen değişiklikler (chat önerisi, YAML, geri yükleme, yenileme) formu yeniden kurar;
  // tuvaldeki kendi kayıtlarımız kurmaz (yazarken odak kaybolmasın).
  const [externalRev, setExternalRev] = useState(0)

  const versionRef = useRef(0)
  useEffect(() => {
    if (flow) versionRef.current = flow.version
  }, [flow])

  // İşlemler sırayla gider (her biri bir önceki sürümün üzerine).
  const chain = useRef<Promise<unknown>>(Promise.resolve())
  const send = useCallback(
    (ops: Operation[]): Promise<EditResult> => {
      const run = async () => {
        setSaving((n) => n + 1)
        try {
          const result = await editorApi.operations(flowId, versionRef.current, ops)
          versionRef.current = result.data.version
          queryClient.setQueryData(['editor', flowId], result.data)
          setErrors([])
          return result
        } catch (e) {
          if (e instanceof ApiError && e.status === 409) setConflict(true)
          else if (e instanceof ApiError) setErrors(e.errors.length ? e.errors : [{ message: e.message, task_id: null }])
          else setErrors([{ message: (e as Error).message, task_id: null }])
          throw e
        } finally {
          setSaving((n) => n - 1)
        }
      }
      const next = chain.current.then(run, run)
      chain.current = next.catch(() => undefined)
      return next
    },
    [flowId, queryClient],
  )
  const saver = useSettingsSaver(useCallback((ops: Operation[]) => void send(ops).catch(() => undefined), [send]))

  const structural = async (ops: Operation[]) => {
    saver.flush()
    return send(ops)
  }

  const proposalQuery = useQuery({ queryKey: ['proposal', previewId], queryFn: () => editorApi.proposal(previewId!), enabled: previewId !== null })
  const preview = proposalQuery.data?.status === 'pending' && proposalQuery.data.base_graph ? proposalQuery.data : null

  const graph = useMemo(() => {
    if (!flow) return null
    return preview ? mergeForDiff(preview.base_graph!, preview.graph) : flow.graph
  }, [flow, preview])
  const marks = useMemo(() => (preview ? diffGraphs(preview.base_graph!, preview.graph) : undefined), [preview])

  const activePoint = adding && adding.point !== 'trigger' ? adding.point : draft && draft.point !== 'trigger' ? draft.point : null
  const layout = useMemo(
    () => (graph ? layoutGraph(graph, { defs, marks, errors, selectedId, activePoint, readOnly: preview !== null }) : []),
    [graph, defs, marks, errors, selectedId, activePoint, preview],
  )
  // Sürükleme sırasında konumlar yerelde değişir; graf her değiştiğinde yerleşime döner.
  const [nodes, setNodes] = useState<Node[]>(layout)
  const [shownLayout, setShownLayout] = useState(layout)
  if (shownLayout !== layout) {
    setShownLayout(layout)
    setNodes(layout)
  }

  const canvasActions = useMemo(
    () => ({
      select: (id: string | null) => {
        setSelectedId(id)
        setAdding(null)
        setDraft(null)
        setTab('settings')
      },
      openInsert: (point: InsertPoint) => {
        saver.flush()
        setDraft(null)
        setAdding({ point, chip: 'Tümü' })
      },
    }),
    [saver],
  )

  if (flowQuery.error) {
    return <p className="p-8 text-bad">Akış açılamadı: {(flowQuery.error as Error).message}</p>
  }
  if (!flow || !graph || !catalogQuery.data) {
    return <p className="p-8 text-muted">Yükleniyor…</p>
  }

  const selected = findNode(flow.graph, selectedId)
  const draftCount = flow.published_version === null ? null : flow.version - flow.published_version

  const defaultPoint = (): InsertPoint => {
    const at = selectedId ? locate(flow.graph, selectedId) : null
    if (at) return { parentId: at.parentId, branch: at.branch, afterId: selectedId }
    const last = flow.graph.tasks.at(-1)
    return { parentId: null, branch: 'tasks', afterId: last?.id ?? null }
  }

  const pick = (kind: string) => {
    const point = defs.get(kind)?.trigger ? 'trigger' : adding?.point && adding.point !== 'trigger' ? adding.point : defaultPoint()
    setAdding(null)
    setDraft({ kind, point })
    setTab('settings')
    setErrors([])
  }

  const insertDraft = async (settings: Settings) => {
    if (!draft) return
    setBusy('insert')
    const op: Operation =
      draft.point === 'trigger'
        ? { op: 'insert_trigger', kind: draft.kind, settings }
        : { op: 'insert', parent_id: draft.point.parentId, branch: draft.point.branch, after_id: draft.point.afterId, kind: draft.kind, settings }
    try {
      const result = await structural([op])
      setDraft(null)
      setSelectedId(result.created.at(-1) ?? null)
    } catch {
      // Hatalar formda gösterilir.
    } finally {
      setBusy(null)
    }
  }

  const remove = async (node: GraphNode, trigger: boolean) => {
    if (!node.id) return
    await structural([trigger ? { op: 'delete_trigger', trigger_id: node.id } : { op: 'delete', task_id: node.id }]).catch(() => undefined)
    setSelectedId(null)
  }

  const move = (id: string, direction: -1 | 1) => {
    const at = locate(flow.graph, id)
    if (!at) return
    const afterId = direction === -1 ? (at.list[at.index - 2]?.id ?? null) : (at.list[at.index + 1]?.id ?? null)
    structural([{ op: 'move', task_id: id, parent_id: at.parentId, branch: at.branch, after_id: afterId }]).catch(() => undefined)
  }

  const onNodesChange = (changes: NodeChange[]) => setNodes((current) => applyNodeChanges(changes, current))

  const dragCenter = (node: Node) => ({ x: node.position.x + (node.width ?? BLOCK_W) / 2, y: node.position.y + (node.height ?? SLOT_H) / 2 })

  const onNodeDrag = (_: unknown, node: Node) => {
    const id = node.id.replace(/^block:/, '')
    const target = nearestSlot(nodes, dragCenter(node), id)
    setNodes((current) =>
      current.map((n) => {
        if (n.type !== 'slot') return n
        const data = n.data as SlotData
        const isTarget = !!target && data.point.parentId === target.parentId && data.point.branch === target.branch && data.point.afterId === target.afterId
        return data.dropTarget === isTarget ? n : { ...n, data: { ...data, dropTarget: isTarget } }
      }),
    )
  }

  const onNodeDragStop = (_: unknown, node: Node) => {
    const id = node.id.replace(/^block:/, '')
    const target = nearestSlot(nodes, dragCenter(node), id)
    setNodes(layout)
    if (target && target.afterId !== id) {
      structural([{ op: 'move', task_id: id, parent_id: target.parentId, branch: target.branch, after_id: target.afterId }]).catch(() => undefined)
    }
  }

  const act = async (name: string, action: () => Promise<void>) => {
    saver.flush()
    await chain.current
    setBusy(name)
    setNotice(null)
    try {
      await action()
    } catch (e) {
      if (e instanceof ApiError && e.errors.length) setErrors(e.errors)
      setNotice({ text: (e as Error).message, tone: 'bad' })
    } finally {
      setBusy(null)
    }
  }

  const publish = () =>
    act('publish', async () => {
      const updated = await editorApi.publish(flowId)
      queryClient.setQueryData(['editor', flowId], updated)
      queryClient.invalidateQueries({ queryKey: ['flows'] })
      setNotice({ text: `Sürüm ${updated.published_version} Kestra'ya gönderildi ve yayında.`, tone: 'ok' })
    })

  const testRun = () =>
    act('test', async () => {
      const result = await editorApi.testRun(flowId)
      setNotice({
        text: `Test çalışması başladı (kuru çalıştırma).${result.skipped.length ? ` Gönderilmeyen adımlar: ${result.skipped.join(', ')}.` : ''}`,
        tone: 'ok',
        link: { href: href({ name: 'runs', executionId: result.data.kestra_execution_id }), label: 'Çalışmayı izle' },
      })
    })

  const applyProposal = async (id: number) => {
    saver.flush()
    await chain.current
    try {
      const updated = await editorApi.applyProposal(id)
      versionRef.current = updated.version
      queryClient.setQueryData(['editor', flowId], updated)
      queryClient.invalidateQueries({ queryKey: ['proposal', id] })
      setPreviewId(null)
      setErrors([])
      setExternalRev((n) => n + 1)
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) throw new Error('Akış öneriden sonra değişti. Değişikliği sohbette yeniden isteyin.')
      throw e
    }
  }

  const rejectProposal = async (id: number) => {
    await editorApi.rejectProposal(id)
    queryClient.invalidateQueries({ queryKey: ['proposal', id] })
    setPreviewId(null)
  }

  const refresh = async () => {
    setConflict(false)
    setErrors([])
    await flowQuery.refetch()
    setExternalRev((n) => n + 1)
  }

  const status =
    flow.status === 'inactive'
      ? { text: `Durduruldu · v${flow.published_version}`, className: 'bg-warn-soft text-warn', dot: 'var(--warning)' }
      : flow.published_version !== null
        ? { text: `Yayında · v${flow.published_version}`, className: 'bg-ok-soft text-ok', dot: 'var(--success)' }
        : { text: `Taslak · v${flow.version}`, className: 'bg-warn-soft text-warn', dot: 'var(--warning)' }

  const flowErrors = errors.filter((e) => !e.task_id || !findNode(flow.graph, e.task_id))
  const conversationId = conversationQuery.data

  return (
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-line bg-surface px-4 py-3.5 md:px-6">
        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="text-xs font-semibold text-muted">
            <a href="#/build/flows" className="text-muted no-underline hover:underline">
              Akışlar
            </a>{' '}
            / <span className="font-mono">{flow.flow_id}</span>
          </span>
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="m-0 truncate font-serif text-xl font-normal tracking-tight">{flow.title}</h1>
            <span className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ${status.className}`}>
              <span className="size-1.5 rounded-full" style={{ background: status.dot }} aria-hidden />
              {status.text}
            </span>
            {saver.pending || saving > 0 ? (
              <span className="text-xs font-semibold text-muted" aria-live="polite">
                Kaydediliyor…
              </span>
            ) : (
              flow.has_draft &&
              flow.published_version !== null && (
                <span className="text-xs font-semibold text-warn">Taslakta {draftCount} değişiklik · yayınlanmadı</span>
              )
            )}
          </div>
        </div>
        <div className="flex-1" />
        {conversationId && settingsQuery.data && (
          <ModelPicker conversationId={conversationId} models={settingsQuery.data.models} />
        )}
        <button type="button" onClick={() => setDialog('yaml')} className="h-10 rounded-xl border border-line bg-surface px-3.5 text-sm font-bold">
          YAML
        </button>
        <button type="button" onClick={() => setDialog('versions')} className="h-10 rounded-xl border border-line bg-surface px-3.5 text-sm font-bold">
          Sürümler
        </button>
        <button type="button" disabled={busy !== null} onClick={testRun} className="flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-bold disabled:opacity-60">
          <PlayIcon />
          {busy === 'test' ? 'Başlatılıyor…' : 'Test çalıştır'}
        </button>
        <button
          type="button"
          disabled={busy !== null || !flow.has_draft}
          onClick={publish}
          title={flow.has_draft ? undefined : 'Yayınlanacak taslak yok'}
          className="h-10 rounded-xl bg-brand px-4.5 text-sm font-bold text-on-brand hover:bg-brand-hover disabled:opacity-50"
        >
          {busy === 'publish' ? 'Yayınlanıyor…' : 'Yayınla'}
        </button>
        <div className="relative">
          <button type="button" aria-label="Diğer işlemler" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)} className="h-10 w-10 rounded-xl border border-line bg-surface text-lg font-bold">
            ⋯
          </button>
          {menuOpen && <FlowMenu flowId={flowId} status={flow.status} onDone={(text) => { setMenuOpen(false); if (text) setNotice({ text, tone: 'ok' }); flowQuery.refetch() }} />}
        </div>
      </header>

      {(conflict || notice || flowErrors.length > 0 || flow.validation.missing_secrets.length > 0) && (
        <div className="flex flex-col gap-2 border-b border-line bg-surface px-4 py-2.5 md:px-6" aria-live="polite">
          {conflict && (
            <div role="alert" className="flex items-center gap-3 rounded-xl bg-warn-soft px-3 py-2 text-[13px] text-warn">
              Akış bu arada başka bir yerde (sohbet veya başka bir kişi) değişti; son değişikliğin kaydedilmedi.
              <button type="button" onClick={refresh} className="h-8 rounded-lg bg-surface px-3 font-bold text-ink">
                Yenile
              </button>
            </div>
          )}
          {flowErrors.map((e, i) => (
            <div key={i} role="alert" className="rounded-xl bg-bad-soft px-3 py-2 text-[13px] text-bad">
              Kaydedilmedi: {e.message}
            </div>
          ))}
          {flow.validation.missing_secrets.length > 0 && (
            <div className="rounded-xl bg-warn-soft px-3 py-2 text-[13px] text-warn">
              Kestra'da tanımlı olmayan secret: <span className="font-mono font-bold">{flow.validation.missing_secrets.join(', ')}</span>. Yayınlamadan önce yöneticinin eklemesi gerekiyor.
            </div>
          )}
          {notice && (
            <div className={`flex items-center gap-3 rounded-xl px-3 py-2 text-[13px] ${notice.tone === 'ok' ? 'bg-ok-soft text-ok' : 'bg-bad-soft text-bad'}`}>
              <span className="flex-1">{notice.text}</span>
              {notice.link && (
                <a href={notice.link.href} className="font-bold text-ink">
                  {notice.link.label}
                </a>
              )}
              <button type="button" aria-label="Bildirimi kapat" onClick={() => setNotice(null)} className="font-bold">
                ×
              </button>
            </div>
          )}
        </div>
      )}

      <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
        <main className="relative h-[65vh] min-w-0 flex-1 lg:h-auto" aria-label="Akış tuvali">
          {preview && (
            <div className="absolute top-3 left-1/2 z-10 -translate-x-1/2 rounded-full bg-surface px-3 py-1.5 text-xs font-bold text-ink-2 shadow-md" role="status">
              Öneri önizleniyor: <span className="text-ok">yeşil eklenen</span>, <span className="text-warn">sarı değişen</span>, <span className="text-bad">kırmızı silinen</span>
            </div>
          )}
          <CanvasContext.Provider value={canvasActions}>
            <ReactFlow
              colorMode={theme}
              nodes={nodes}
              edges={[]}
              nodeTypes={nodeTypes}
              onNodesChange={onNodesChange}
              onNodeDrag={onNodeDrag}
              onNodeDragStop={onNodeDragStop}
              nodesConnectable={false}
              elementsSelectable={false}
              nodesFocusable={false}
              panOnScroll
              onInit={(instance) => {
                // Akışın üstü, okunur ölçekte ortada başlar (uzun akışta tamamını sığdırmak blokları okunmaz yapar).
                const width = document.querySelector('[aria-label="Akış tuvali"]')?.clientWidth ?? 900
                instance.setViewport({ x: width / 2, y: 32, zoom: 0.9 })
              }}
              minZoom={0.25}
            >
              <Background gap={22} size={1.2} color="var(--canvas-dot)" bgColor="var(--bg-subtle)" />
              <Controls showInteractive={false} position="top-left" />
            </ReactFlow>
          </CanvasContext.Provider>

          <div role="toolbar" aria-label="Blok ekle" className="absolute inset-x-4 bottom-6 z-10 mx-auto flex w-fit max-w-full flex-wrap justify-center gap-1 rounded-2xl border border-line bg-surface p-1.5 shadow-[0_10px_30px_var(--shadow)]">
            {PALETTE.map((p) => {
              const colors = tone(p.tone)
              return (
                <button
                  key={p.label}
                  type="button"
                  disabled={preview !== null}
                  onClick={() => {
                    saver.flush()
                    const point = p.chip === 'Tetikleyici' ? 'trigger' : defaultPoint()
                    if (p.kind) {
                      setAdding(null)
                      setDraft({ kind: p.kind, point })
                      setTab('settings')
                    } else {
                      setDraft(null)
                      setAdding({ point, chip: p.chip ?? 'Tümü' })
                    }
                  }}
                  className="flex h-10 items-center gap-2 rounded-xl pr-3 pl-1.5 text-[13px] font-bold text-ink hover:bg-canvas disabled:opacity-50"
                >
                  <span className="flex size-7 items-center justify-center rounded-[9px] text-[11px] font-extrabold" style={{ background: colors.bg, color: colors.fg }} aria-hidden>
                    {p.glyph}
                  </span>
                  {p.label}
                </button>
              )
            })}
          </div>
        </main>

        <div className="flex min-h-0 w-full flex-col border-line bg-surface max-lg:border-t lg:w-[400px] lg:border-l">
          {adding ? (
            <AddBlockPanel
              key={JSON.stringify(adding)}
              catalog={catalogQuery.data}
              where={describePoint(flow.graph, adding.point)}
              initialChip={adding.chip}
              onPick={pick}
              onClose={() => setAdding(null)}
              onDescribe={(text) => {
                const where = describePoint(flow.graph, adding.point)
                setAdding(null)
                setTab('chat')
                setOutbox({ id: Date.now(), text: `${where} şunu ekle: ${text}` })
              }}
            />
          ) : (
            <>
              <div className="p-3">
                <div role="tablist" className="flex gap-1 rounded-xl bg-canvas p-1">
                  {(['settings', 'chat'] as const).map((key) => (
                    <button
                      key={key}
                      type="button"
                      role="tab"
                      aria-selected={tab === key}
                      onClick={() => setTab(key)}
                      className={`h-9 flex-1 rounded-[9px] text-[13px] font-bold ${tab === key ? 'bg-surface text-ink shadow-sm' : 'text-muted'}`}
                    >
                      {key === 'settings' ? 'Blok ayarları' : 'AI ile düzenle'}
                    </button>
                  ))}
                </div>
              </div>

              {tab === 'settings' && (
                <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-6">
                  {draft && defs.get(draft.kind) ? (
                    <SettingsPanel
                      key={`draft:${draft.kind}`}
                      mode="draft"
                      def={defs.get(draft.kind)!}
                      where={describePoint(flow.graph, draft.point)}
                      errors={errors}
                      busy={busy === 'insert'}
                      onInsert={insertDraft}
                      onCancel={() => {
                        setDraft(null)
                        setErrors([])
                      }}
                    />
                  ) : selected ? (
                    <SettingsPanel
                      key={`${selected.node.id}:${externalRev}`}
                      mode="edit"
                      node={selected.node}
                      def={defs.get(selected.node.kind)}
                      isTrigger={selected.trigger}
                      errors={errors.filter((e) => e.task_id === selected.node.id)}
                      onSchedule={saver.schedule}
                      onDelete={() => remove(selected.node, selected.trigger)}
                      onMove={(d) => selected.node.id && move(selected.node.id, d)}
                      canMove={(() => {
                        const at = selected.node.id ? locate(flow.graph, selected.node.id) : null
                        return { up: !!at && at.index > 0, down: !!at && at.index < at.list.length - 1 }
                      })()}
                    />
                  ) : (
                    <div className="flex flex-col gap-3 text-[13px] text-muted">
                      <p className="m-0">Ayarlarını görmek için tuvalde bir blok seçin. Yeni blok için blokların arasındaki + düğmesini veya alttaki paleti kullanın.</p>
                      <p className="m-0">Değişiklikler 600 ms sonra taslak sürüm olarak kaydedilir ve her seferinde doğrulanır. Kestra'ya sadece Yayınla ile gider.</p>
                      {flow.validation.warnings.length > 0 && (
                        <ul className="m-0 list-disc pl-4 text-warn">
                          {flow.validation.warnings.map((w, i) => (
                            <li key={i}>{w}</li>
                          ))}
                        </ul>
                      )}
                    </div>
                  )}
                </div>
              )}

              {conversationId && (
                <ChatPanel
                  conversationId={conversationId}
                  previewId={previewId}
                  onPreview={setPreviewId}
                  onApply={applyProposal}
                  onReject={rejectProposal}
                  outbox={outbox}
                  active={tab === 'chat'}
                  onActivate={() => setTab('chat')}
                  selection={selected?.node.id ? { id: selected.node.id, title: selected.node.title ?? selected.node.id } : null}
                />
              )}
            </>
          )}
        </div>
      </div>

      <Suspense fallback={null}>
        {dialog === 'yaml' && (
          <YamlDialog
            yaml={flow.yaml}
            version={flow.version}
            onClose={() => setDialog(null)}
            onSave={async (yaml) => {
              saver.flush()
              await chain.current
              const result = await editorApi.saveYaml(flowId, versionRef.current, yaml)
              versionRef.current = result.data.version
              queryClient.setQueryData(['editor', flowId], result.data)
              setExternalRev((n) => n + 1)
            }}
          />
        )}
        {dialog === 'versions' && (
          <VersionsDialog
            flowId={flowId}
            current={flow.version}
            onClose={() => setDialog(null)}
            onRestore={async (number) => {
              saver.flush()
              await chain.current
              const result = await editorApi.restore(flowId, number, versionRef.current)
              versionRef.current = result.data.version
              queryClient.setQueryData(['editor', flowId], result.data)
              setExternalRev((n) => n + 1)
              setNotice({ text: `v${number} yeni taslak (v${result.data.version}) olarak geri yüklendi. Kestra'ya gitmesi için Yayınla.`, tone: 'ok' })
            }}
          />
        )}
      </Suspense>
    </div>
  )
}

function ModelPicker({ conversationId, models }: { conversationId: number; models: { id: string; label: string }[] }) {
  const queryClient = useQueryClient()
  const conversation = useQuery({ queryKey: ['conversation', conversationId], queryFn: () => api.getConversation(conversationId) })
  const value = conversation.data?.effective_model ?? models[0]?.id

  return (
    <label className="flex h-10 items-center gap-2 rounded-xl border border-line bg-surface pr-1.5 pl-3 text-[13px] font-semibold text-muted">
      Model
      <select
        value={value}
        onChange={async (e) => {
          await conversationApi.setModel(conversationId, e.target.value)
          queryClient.invalidateQueries({ queryKey: ['conversation', conversationId] })
        }}
        className="h-8 rounded-lg border-0 bg-canvas px-2 text-[13px] font-bold text-ink"
      >
        {models.map((m) => (
          <option key={m.id} value={m.id}>
            {m.label.replace('Claude ', '')}
          </option>
        ))}
      </select>
    </label>
  )
}

function FlowMenu({ flowId, status, onDone }: { flowId: number; status: string; onDone: (text?: string) => void }) {
  const [confirm, setConfirm] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const run = async (action: () => Promise<unknown>, text: string) => {
    setError(null)
    try {
      await action()
      onDone(text)
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <div role="menu" className="absolute top-12 right-0 z-30 flex w-60 flex-col gap-0.5 rounded-xl border border-line bg-surface p-1.5 text-sm shadow-lg">
      {status !== 'draft' && (
        <button type="button" role="menuitem" onClick={() => run(() => editorApi.setEnabled(flowId, status !== 'active'), status === 'active' ? 'Akış durduruldu.' : 'Akış yeniden aktif.')} className="rounded-lg px-3 py-2 text-left font-semibold hover:bg-canvas">
          {status === 'active' ? 'Durdur (pasif yap)' : 'Aktif yap'}
        </button>
      )}
      <a role="menuitem" href="#/runs" className="rounded-lg px-3 py-2 font-semibold text-ink no-underline hover:bg-canvas">
        Çalışmaları gör
      </a>
      {confirm ? (
        <div className="flex flex-col gap-2 rounded-lg bg-bad-soft p-2 text-[13px] text-bad">
          Akış Kestra'dan silinir. Emin misiniz?
          <div className="flex gap-1.5">
            <button type="button" onClick={() => run(() => editorApi.remove(flowId), '').then(() => navigate({ name: 'flows' }))} className="h-8 rounded-lg bg-bad px-3 font-bold text-bg">
              Sil
            </button>
            <button type="button" onClick={() => setConfirm(false)} className="h-8 rounded-lg bg-surface px-3 font-bold text-ink">
              Vazgeç
            </button>
          </div>
        </div>
      ) : (
        <button type="button" role="menuitem" onClick={() => setConfirm(true)} className="rounded-lg px-3 py-2 text-left font-semibold text-bad hover:bg-bad-soft">
          Akışı sil
        </button>
      )}
      {error && <p className="m-0 px-3 py-1 text-xs text-bad">{error}</p>}
    </div>
  )
}
