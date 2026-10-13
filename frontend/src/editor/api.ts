// Editör, çalışmalar ve kullanım ekranlarının Laravel API'si. React sadece Laravel ile konuşur.
import type { Execution, LogLine } from '../api'
import type { Catalog, EditError, EditorFlow, FlowGraph, Operation } from './types'
import { apiFetch } from '../http'

export class ApiError extends Error {
  readonly status: number
  readonly errors: EditError[]
  readonly latestVersion: number | null

  constructor(status: number, message: string, errors: EditError[] = [], latestVersion: number | null = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.latestVersion = latestVersion
  }
}

async function call<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    ...init,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init?.headers },
  })
  const json = response.status === 204 ? {} : await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(response.status, json.message ?? `İstek başarısız (${response.status})`, json.errors ?? [], json.latest_version ?? null)
  }

  return json as T
}

const post = <T>(path: string, body?: unknown) => call<T>(path, { method: 'POST', body: body === undefined ? undefined : JSON.stringify(body) })

export interface EditResult {
  data: EditorFlow
  created: string[]
  changed: boolean
}

export interface VersionInfo {
  number: number
  source: 'chat' | 'canvas' | 'yaml' | 'restore'
  created_by: string | null
  created_at: string | null
  uploaded_at: string | null
  kestra_revision: number | null
  is_published: boolean
}

export interface Proposal {
  id: number
  flow_id: number
  status: 'pending' | 'applied' | 'rejected'
  base_version: number
  title: string
  summary: string[]
  yaml: string
  base_yaml: string | null
  graph: FlowGraph
  base_graph: FlowGraph | null
  warnings: string[]
  missing_secrets: string[]
}

export interface RunSummary {
  kestra_execution_id: string
  namespace: string
  is_test: boolean
  flow: { id: number | null; flow_id: string; title: string }
  state: string | null
  start: string | null
  end: string | null
  duration: string | null
  trigger: string
}

export interface RunStep {
  task_id: string
  title: string | null
  label: string | null
  kind: string | null
  depth: number
  section: 'tasks' | 'errors' | 'finally'
  state: string | null
  runs: number
  started_at: string | null
  duration: string | null
  output_summary: string | null
}

export interface RunDetail extends Execution {
  is_test: boolean
  steps: RunStep[]
  paused: (Execution['paused'] & { question: string | null; timeout: string | null; on_timeout: string | null }) | null
}

export interface UsageSummary {
  month: string
  cost_usd: number
  calls: number
  input_tokens: number
  output_tokens: number
  avg_cost_per_call: number
  flow_runs: number | null
  flow_step_calls: number
  previous_cost_usd: number
  change_pct: number | null
  budget: { limit: number | null; spent: number; ratio: number | null } | null
  blocks_flow_ai_steps: boolean
}

export interface UsageByFlow {
  type: 'flow' | 'conversation' | 'other'
  flow_id: number | null
  conversation_id: number | null
  name: string
  flow_status: string | null
  models: string[]
  calls: number
  chat_calls: number
  flow_step_calls: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
}

export interface AiSettings {
  models: { id: string; label: string; strength?: string | null; input_per_mtok: number; output_per_mtok: number; cache_write_per_mtok: number; cache_read_per_mtok: number }[]
  policy_note?: string
  default_model: string
  monthly_budget_usd: number | null
  budget_blocks_flow_ai_steps: boolean
  /** off: Claude veriye bakmaz; masked: kişisel bilgiler gizlenerek bakar. */
  ai_data_access: 'off' | 'masked'
  ai_allow_employee_names: boolean
}

export const editorApi = {
  flow: (id: number) => call<{ data: EditorFlow }>(`/flows/${id}/editor`).then((r) => r.data),
  catalog: () => call<Catalog>('/catalog/blocks'),
  operations: (id: number, baseVersion: number, operations: Operation[]) =>
    post<EditResult>(`/flows/${id}/operations`, { base_version: baseVersion, operations }),
  saveYaml: (id: number, baseVersion: number, yaml: string) =>
    call<EditResult>(`/flows/${id}/yaml`, { method: 'PUT', body: JSON.stringify({ base_version: baseVersion, yaml }) }),
  publish: (id: number) => post<{ data: EditorFlow }>(`/flows/${id}/publish`).then((r) => r.data),
  testRun: (id: number, inputs: Record<string, unknown> = {}) =>
    post<{ data: RunDetail; skipped: string[] }>(`/flows/${id}/test-run`, { inputs }),
  versions: (id: number) => call<{ data: VersionInfo[] }>(`/flows/${id}/versions`).then((r) => r.data),
  diff: (id: number, number: number, against: number) =>
    call<{ data: { version: { number: number; yaml: string; graph: FlowGraph }; against: { number: number; yaml: string; graph: FlowGraph } } }>(
      `/flows/${id}/versions/${number}/diff?against=${against}`,
    ).then((r) => r.data),
  restore: (id: number, number: number, baseVersion: number) =>
    post<EditResult>(`/flows/${id}/versions/${number}/restore`, { base_version: baseVersion }),
  editorConversation: (id: number) => post<{ data: { id: number } }>(`/flows/${id}/editor-conversation`).then((r) => r.data.id),
  proposal: (id: number) => call<{ data: Proposal }>(`/proposals/${id}`).then((r) => r.data),
  applyProposal: (id: number) => post<{ data: EditorFlow }>(`/proposals/${id}/apply`).then((r) => r.data),
  rejectProposal: (id: number) => post<{ data: { status: string } }>(`/proposals/${id}/reject`),
  setEnabled: (id: number, enabled: boolean) => post(`/flows/${id}/${enabled ? 'enable' : 'disable'}`),
  remove: (id: number) => call<void>(`/flows/${id}`, { method: 'DELETE' }),
}

export const runsApi = {
  list: (params: { status: 'all' | 'paused' | 'failed'; flowId?: number | null; page?: number }) => {
    const query = new URLSearchParams({ status: params.status, page: String(params.page ?? 1) })
    if (params.flowId) query.set('flow_id', String(params.flowId))
    return call<{ data: RunSummary[]; total: number; counts: { paused: number; failed: number }; warning: string | null }>(`/executions?${query}`)
  },
  get: (id: string) => call<{ data: RunDetail }>(`/executions/${id}`).then((r) => r.data),
  logs: (id: string, page = 1) => call<{ data: LogLine[]; total: number; page: number; per_page: number }>(`/executions/${id}/logs?page=${page}`),
  resume: (id: string, approved: boolean) => post<{ data: RunDetail }>(`/executions/${id}/resume`, { approved, inputs: {} }).then((r) => r.data),
  kill: (id: string) => post<{ data: RunDetail }>(`/executions/${id}/kill`).then((r) => r.data),
  explain: (id: string) => post<{ data: { explanation: string; sent: { task: string | null; type: string; message: string }[] } }>(`/executions/${id}/explain`).then((r) => r.data),
}

export const usageApi = {
  summary: (month?: string) => call<{ data: UsageSummary }>(`/usage/summary${month ? `?month=${month}` : ''}`).then((r) => r.data),
  daily: (month: string) => call<{ data: { date: string; chat_cost: number; flow_step_cost: number }[] }>(`/usage/daily?month=${month}`).then((r) => r.data),
  byModel: (month: string) =>
    call<{ data: { model: string; label: string; calls: number; input_tokens: number; output_tokens: number; cost_usd: number }[] }>(`/usage/by-model?month=${month}`).then((r) => r.data),
  byFlow: (month: string) => call<{ data: UsageByFlow[] }>(`/usage/by-flow?month=${month}`).then((r) => r.data),
  exportUrl: (month: string) => `/api/usage/export.csv?month=${month}`,
  settings: () => call<{ data: AiSettings }>('/settings/ai').then((r) => r.data),
  updateSettings: (patch: Partial<Pick<AiSettings, 'default_model' | 'monthly_budget_usd' | 'budget_blocks_flow_ai_steps' | 'ai_data_access' | 'ai_allow_employee_names'>>) =>
    call<{ data: AiSettings }>('/settings/ai', { method: 'PUT', body: JSON.stringify(patch) }).then((r) => r.data),
}

export const conversationApi = {
  setModel: (id: number, model: string | null) =>
    call<{ data: { model: string | null; effective_model: string } }>(`/conversations/${id}`, { method: 'PATCH', body: JSON.stringify({ model }) }).then((r) => r.data),
}
