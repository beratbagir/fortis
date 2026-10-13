// Laravel sohbet API'si. React sadece Laravel ile konuşur.
import type { VoiceMeta } from './voice/api'
import { apiFetch } from './http'

export type Role = 'user' | 'assistant'

export interface WarnedStep {
  task_id: string | null
  label: string
  details: string | null
}

export interface FlowCardStatus {
  uploaded: boolean
  uploaded_at: string | null
  kestra_revision: number | null
  is_latest: boolean
  can_upload: boolean
  reason: string | null
}

export interface FlowInput {
  id: string
  type: string
  required: boolean
  defaults: unknown
  description: string | null
  values: string[] | null
}

export interface RunInfo {
  can_run: boolean
  reason: string | null
  inputs: FlowInput[]
  webhook_url: string | null
}

export interface TaskRun {
  task_id: string
  state: string
  duration: string | null
  attempts: number
  value: string | null
}

export interface LogLine {
  timestamp: string | null
  level: string
  task_id: string | null
  message: string
}

export interface PausedInfo {
  task_id: string
  inputs: FlowInput[]
  /** Tek BOOL onay girdisi: Devam et true, Reddet false gönderir. Yoksa Reddet çalışmayı durdurur. */
  approval_input: string | null
}

export interface Execution {
  id: number
  flow_id: number
  flow_version_id: number | null
  conversation_id: number | null
  flow: { namespace: string; flow_id: string; title: string }
  kestra_execution_id: string
  state: string
  finished: boolean
  inputs: Record<string, string>
  started_at: string | null
  ended_at: string | null
  duration: string | null
  task_runs: TaskRun[]
  logs: LogLine[]
  paused: PausedInfo | null
  created_at: string | null
}

/** Akış kartı. Uyarılı adımlar ve eksik secret'lar Laravel tarafından YAML'dan çıkarılır. */
export interface FlowCard {
  flow_version_id: number
  /** Laravel'deki akış kaydının id'si (çalıştırma ucu için). */
  flow_record_id: number
  version: number
  status: FlowCardStatus
  run: RunInfo
  executions: Execution[]
  title: string
  summary: string[]
  flow_id: string
  namespace: string
  triggers: string[]
  warned_steps: WarnedStep[]
  missing_secrets: string[]
  yaml: string
}

/** Akış editöründeki sohbetin önerisi: tuvalde fark olarak gösterilir. */
export interface ProposalCard {
  type: 'proposal'
  proposal_id: number
  flow_record_id: number
  base_version: number
  title: string
  summary: string[]
  status: 'pending' | 'applied' | 'rejected' | null
}

export const isProposalCard = (card: ChatCard): card is ProposalCard => (card as ProposalCard).type === 'proposal'

/** Excel / CSV / PDF rapor kartı. Dosyayı Laravel üretir; indirme bağlantısı imzalı ve kısa süreli. */
export interface ReportCard {
  type: 'report'
  id: string
  title: string
  format: 'xlsx' | 'csv' | 'pdf'
  status: 'queued' | 'ready' | 'failed' | 'expired' | 'missing'
  row_count: number | null
  file_size: number | null
  error: string | null
  expires_at: string | null
  retention_days: number
  conversation_id: number | null
}

/** Claude'un ürettiği arayüz, diyagram veya belge; sohbetin yanındaki panelde açılır. */
export interface ArtifactCard {
  type: 'artifact'
  artifact_id: string
  version: number
  title: string
  kind: ArtifactKind
}

export type ArtifactKind = 'html' | 'svg' | 'mermaid' | 'markdown'

export interface ArtifactDetail {
  id: string
  conversation_id: number
  kind: ArtifactKind
  title: string
  version: number
  latest_version: number
  content: string
  versions: { number: number; title: string; created_at: string | null }[]
}

export const isArtifactCard = (card: ChatCard): card is ArtifactCard => (card as ArtifactCard).type === 'artifact'

export type ChatCard = FlowCard | ProposalCard | ReportCard | ArtifactCard

export const isReportCard = (card: ChatCard): card is ReportCard => (card as ReportCard).type === 'report'

export const isFlowCard = (card: ChatCard): card is FlowCard => !('type' in card)

async function postReport<T>(path: string, body?: unknown): Promise<T> {
  const response = await apiFetch(`/api/reports/${path}`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(json.message ?? `İstek başarısız (${response.status})`)
  return json.data as T
}

export const reportsApi = {
  get: (id: string) => request<Omit<ReportCard, 'type'>>(`/reports/${id}`),
  downloadLink: (id: string, conversationId: number) => postReport<{ url: string; expires_at: string }>(`${id}/download-link`, { conversation_id: conversationId }),
  regenerate: (id: string) => postReport<Omit<ReportCard, 'type'>>(`${id}/regenerate`),
}

/** Claude'un yaptığı bir veri sorgusu: amaç ve kaç satır döndüğü. Dönen veri arayüze gelmez. */
export interface QueryStep {
  tool: string
  source?: string
  purpose: string
  rows?: number
  total_rows?: number
  masked?: boolean
  error?: boolean
  running?: boolean
}

/** Canlı izin bir adımı (step olayı veya cevabın kayıtlı izi). Sorgu metni sadece admin ve builder'a gelir. */
export interface TraceStep {
  id: string
  kind: 'scope' | 'model' | 'query' | 'tool' | 'ask' | 'error'
  status: 'running' | 'done' | 'error' | 'waiting'
  label?: string
  detail?: string | null
  tool?: string
  purpose?: string | null
  source?: string
  rows?: number
  total_rows?: number
  masked?: boolean
  query_id?: string
  query?: Record<string, unknown> | null
  error?: string
  sources?: { key: string; label: string; in_scope: boolean }[]
  model?: string
  auto?: boolean
  at_ms?: number
}

/** Cevabın dayandığı veri sorgusu (kaynak numaraları). */
export interface AnswerQuery {
  tool: string
  source: string
  purpose: string
  query_id: string
  rows: number
  total_rows: number
  masked: boolean
  query?: Record<string, unknown> | null
  at?: string
}

/** Son cevabın özeti: iz, sorgular, kullanılan modeller. */
export interface AnswerMeta {
  trace: TraceStep[]
  queries: AnswerQuery[]
  models: string[]
  model_labels?: string[]
  auto: boolean
  duration_ms: number
  steps: number
  writes: string[]
  citation_mismatch: string[]
}

export interface AskOption {
  label: string
  source: string | null
}

export interface AskQuestion {
  question: string
  context: string | null
  multi_select: boolean
  allow_other: boolean
  options: AskOption[]
}

/** Soru kartının cevabı. */
export interface AskAnswer {
  skipped?: boolean
  answers?: { question: string; selected: string[]; other: string | null }[]
}

export interface Message {
  id: number
  conversation_id: number
  role: Role
  text: string
  stop_reason: string | null
  status?: string | null
  flow_cards: ChatCard[]
  queries?: QueryStep[]
  ask?: { questions: AskQuestion[] } | null
  /** Soru kartıydı ve cevaplandı: izi devam eden cevapta gösterilir. */
  ask_answered?: boolean
  answer?: AnswerMeta | null
  feedback?: 'up' | 'down' | null
  created_at: string
}

export type ToolName = 'get_catalog' | 'validate_flow' | 'propose_flow' | 'list_flows' | 'get_flow' | (string & {})

export interface Conversation {
  id: number
  title: string | null
  kind?: 'chat' | 'editor'
  model?: string | null
  effective_model?: string
  /** Model seçicide Auto: her tur için Fortis seçer. */
  auto?: boolean
  disabled_sources?: string[]
  usage?: { calls: number; tokens: number; cost_usd: number }
  /** "Chat'te değiştir" ile açıldıysa konusu olan akış. */
  context_flow?: { id: number; flow_id: string; namespace: string; title: string } | null
  created_at: string
  updated_at: string
  messages?: Message[]
}

export type StreamEvent =
  | { event: 'user_message'; data: Message }
  | { event: 'thinking'; data: Record<string, never> }
  | { event: 'delta'; data: { text: string } }
  | { event: 'tool'; data: { name: ToolName; status: 'writing' | 'running' | 'done' | 'error'; purpose?: string; rows?: number; total_rows?: number; masked?: boolean } }
  | { event: 'tool_progress'; data: { name: ToolName; chars: number } }
  | { event: 'step'; data: TraceStep }
  | { event: 'ask'; data: { message_id: number; questions: AskQuestion[] } }
  | { event: 'assistant_message'; data: Message }
  | { event: 'error'; data: { message: string } }
  | { event: 'done'; data: { conversation: Pick<Conversation, 'id' | 'title'> } }

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    ...init,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init?.headers },
  })

  if (!response.ok) {
    throw new Error(`İstek başarısız (${response.status})`)
  }

  return response.status === 204 ? (undefined as T) : ((await response.json()).data as T)
}

export class UploadError extends Error {
  readonly errors: string[]
  readonly card: FlowCard | null

  constructor(errors: string[], card: FlowCard | null) {
    super(errors.join(' '))
    this.errors = errors
    this.card = card
  }
}

/** Kartın "Yükle" butonu. Laravel sürümü yeniden doğrular; geçmezse Kestra'ya hiçbir şey gitmez. */
export async function uploadFlowVersion(versionId: number): Promise<FlowCard> {
  const response = await apiFetch(`/api/flow-versions/${versionId}/upload`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })
  const body = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new UploadError(body.errors ?? [`Yükleme başarısız (${response.status})`], body.card ?? null)
  }

  return body.card as FlowCard
}

export const api = {
  listConversations: () => request<Conversation[]>('/conversations'),
  getConversation: (id: number) => request<Conversation>(`/conversations/${id}`),
  createConversation: () => request<Conversation>('/conversations', { method: 'POST' }),
  renameConversation: (id: number, title: string) =>
    request<Conversation>(`/conversations/${id}`, { method: 'PATCH', body: JSON.stringify({ title }) }),
  deleteConversation: (id: number) => request<void>(`/conversations/${id}`, { method: 'DELETE' }),
  /** Model ("auto" veya model id) ve kapatılan kaynaklar. */
  updateConversation: (id: number, patch: { model?: string; disabled_sources?: string[]; title?: string }) =>
    request<Conversation>(`/conversations/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  feedback: (messageId: number, rating: 'up' | 'down' | null, comment?: string) =>
    request<{ rating: string; comment: string | null } | null>(`/messages/${messageId}/feedback`, { method: 'POST', body: JSON.stringify({ rating, comment: comment ?? null }) }),
  audit: (messageId: number) => request<AuditEntry[]>(`/messages/${messageId}/audit`),
  home: () => request<HomeData>('/home'),
  connectors: () => request<ConnectorStatus[]>('/connectors/status'),
}

export type InboxTab = 'all' | 'action' | 'mentions' | 'updates' | 'done'
export type InboxType = 'approval' | 'question' | 'update' | 'alert' | 'mention' | 'shared'

export interface InboxItem {
  id: number
  type: InboxType
  title: string
  body: string | null
  source_label: string | null
  icon: string | null
  status: 'open' | 'done' | 'snoozed'
  actionable: boolean
  subject_type: 'agent_action' | 'agent_run' | 'execution' | 'agent_trigger' | null
  snoozed_until: string | null
  read: boolean
  created_at: string | null
}

export interface InboxRun {
  id: number
  agent: { id: number; name: string; status: 'draft' | 'pilot' | 'live' | 'paused'; icon: string | null }
  status: string
  is_test: boolean
  started_at: string | null
  summary: string | null
  output: string | null
  conversation_id: number | null
  cost_usd: number
  error?: string | null
}

export interface InboxActionPayload {
  to?: string[]
  cc?: string[]
  subject?: string
  body_markdown?: string
  text_markdown?: string
  message_markdown?: string
  channel?: string
  attach_report_id?: string | null
  deliver_as_email?: boolean
  intended?: { to?: string[]; cc?: string[]; channel?: string }
}

export type InboxDetail =
  | {
      kind: 'action'
      action: { id: number; type: string; status: 'pending' | 'approved' | 'rejected' | 'executed' | 'failed' | 'preview'; payload: InboxActionPayload; reason: string | null; result: Record<string, unknown> | null }
      run: InboxRun
      what_happens: string[]
      can_decide: boolean
      editable_recipients: boolean
      recipient_domains: string[]
      allowed_recipients: string[]
    }
  | { kind: 'run'; run: InboxRun; questions: AskQuestion[] | null }
  | { kind: 'execution'; execution: { id: string; flow: string | null; state: string; started_at: string | null }; what_happens: string[]; can_decide: boolean }

export type InboxItemDetail = InboxItem & { detail: InboxDetail | [] }

export interface DraftPatch {
  to?: string[]
  subject?: string
  body_markdown?: string
  text_markdown?: string
  message_markdown?: string
}

/** Inbox istekleri: hata olursa sunucunun mesajı gösterilir (ör. "Bu aksiyon zaten sonuçlandı."). */
async function inboxRequest<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const response = await apiFetch(`/api/inbox${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(json.message ?? `İstek başarısız (${response.status})`)
  return (path === '' || path.startsWith('?') ? json : json.data) as T
}

export type SnoozeUntil = '1h' | '4h' | 'tomorrow'

export const inboxApi = {
  list: (tab: InboxTab) => inboxRequest<{ data: InboxItem[]; counts: Record<InboxTab, number> }>(`?tab=${tab}`),
  count: () => inboxRequest<{ action: number }>('/count'),
  get: (id: number) => inboxRequest<InboxItemDetail>(`/${id}`),
  approve: (id: number) => inboxRequest<InboxItemDetail>(`/${id}/approve`, 'POST'),
  reject: (id: number, reason: string | null) => inboxRequest<InboxItemDetail>(`/${id}/reject`, 'POST', { reason }),
  answer: (id: number, answer: AskAnswer) => inboxRequest<InboxItemDetail>(`/${id}/answer`, 'POST', { answer }),
  draft: (id: number, patch: DraftPatch) => inboxRequest<InboxItemDetail>(`/${id}/draft`, 'PATCH', patch),
  done: (id: number) => inboxRequest<InboxItem>(`/${id}/done`, 'POST'),
  snooze: (id: number, until: SnoozeUntil) => inboxRequest<InboxItem>(`/${id}/snooze`, 'POST', { until }),
  bulk: (ids: number[], op: 'done' | 'snooze' | 'approve', until?: SnoozeUntil) => inboxRequest<{ updated: number }>('/bulk', 'POST', { ids, op, until }),
}

export interface AuditEntry {
  at: string
  actor: string | null
  system: string
  result: string
  tool: string
  purpose: string
  rows?: number
  total_rows?: number
  masked_fields: string[]
  query?: Record<string, unknown>
}

export interface ConnectorStatus {
  key: string
  label: string
  abbr: string
  status: 'connected' | 'attention' | 'not_configured' | 'disabled'
  detail: string | null
}

export interface HomeData {
  greeting_name: string
  suggestions: { type: 'question' | 'agent' | 'report' | 'presentation'; text: string }[]
  waiting_total: number
  /** Inbox öğeleri (açık onaylar ve sorular); id = Inbox öğesi. */
  waiting: { id: number; kind: 'approval' | 'question'; title: string; detail: string; since: string | null }[]
  activity: { id: string; title: string; state: string; tone: 'ok' | 'bad' | 'warn' | 'running'; detail: string; time: string | null }[]
}

/**
 * Mesajı gönderir ve Laravel'in SSE yanıtını olay olay işler.
 * EventSource sadece GET desteklediği için fetch + ReadableStream kullanılır.
 */
/** Mesajla giden ek bilgi: tuvalde seçili blok ve metin sesten geldiyse ölçüm. */
export interface MessageExtras {
  context?: { selected_task_id: string }
  voice?: VoiceMeta
  /** Soru kartının cevabı (bu durumda metin boş olabilir). */
  answer?: AskAnswer
}

export async function sendMessage(
  conversationId: number,
  content: string,
  onEvent: (event: StreamEvent) => void,
  signal?: AbortSignal,
  extras: MessageExtras = {},
): Promise<void> {
  const response = await apiFetch(`/api/conversations/${conversationId}/messages`, {
    method: 'POST',
    headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json' },
    body: JSON.stringify({ content, ...extras }),
    signal,
  })

  if (!response.ok || !response.body) {
    const body = await response.json().catch(() => ({}))
    throw new Error(body.message ?? `Mesaj gönderilemedi (${response.status})`)
  }

  const reader = response.body.pipeThrough(new TextDecoderStream()).getReader()
  let buffer = ''

  for (;;) {
    const { value, done } = await reader.read()
    if (done) break

    buffer += value
    let boundary: number
    while ((boundary = buffer.indexOf('\n\n')) !== -1) {
      const raw = buffer.slice(0, boundary)
      buffer = buffer.slice(boundary + 2)

      let event = 'message'
      let data = ''
      for (const line of raw.split('\n')) {
        if (line.startsWith('event: ')) event = line.slice(7)
        else if (line.startsWith('data: ')) data += line.slice(6)
      }

      if (data !== '') {
        onEvent({ event, data: JSON.parse(data) } as StreamEvent)
      }
    }
  }
}

async function postJson<T>(path: string, body: unknown): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const json = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new Error(json.message ?? `İstek başarısız (${response.status})`)
  }

  return json.data as T
}

/** "Şimdi çalıştır" butonu. */
export const startExecution = (flowRecordId: number, inputs: Record<string, unknown>, conversationId: number | null) =>
  postJson<Execution>(`/flows/${flowRecordId}/executions`, { inputs, conversation_id: conversationId })

/** "Devam et" (approved: true) veya "Reddet" (approved: false). */
export const resumeExecution = (executionId: number, approved: boolean, inputs: Record<string, unknown>) =>
  postJson<Execution>(`/executions/${executionId}/resume`, { approved, inputs })

/**
 * Çalışmayı canlı izler. Laravel Kestra'yı sorgulayıp SSE ile iletir; bağlantı süresi dolunca
 * EventSource kendiliğinden yeniden bağlanır. Çalışma bitince (`done`) bağlantı kapatılır.
 */
export function watchExecution(executionId: number, onUpdate: (execution: Execution) => void, onError: (message: string) => void): () => void {
  const source = new EventSource(`/api/executions/${executionId}/stream`)

  source.addEventListener('execution', (event) => onUpdate(JSON.parse((event as MessageEvent).data)))
  source.addEventListener('error', (event) => {
    const data = (event as MessageEvent).data
    if (data) onError(JSON.parse(data).message)
  })
  source.addEventListener('done', () => source.close())

  return () => source.close()
}

// ---- Akışlar sayfası ------------------------------------------------------

export type FlowStatus = 'draft' | 'active' | 'inactive'

export interface RemoteExecution {
  kestra_execution_id: string
  state: string | null
  start: string | null
  end: string | null
  duration: string | null
  /** Schedule, Webhook veya Elle */
  trigger: string
}

export interface FlowSummary {
  id: number
  namespace: string
  flow_id: string
  title: string
  summary: string[]
  status: FlowStatus
  triggers: string[]
  current_version: { number: number; kestra_revision: number | null; uploaded_at: string | null } | null
  versions_count: number
  conversation: { id: number; title: string | null } | null
  /** Kestra'daki gerçek durum; Kestra'da yoksa veya okunamadıysa null. */
  kestra: { revision: number | null; disabled: boolean } | null
  last_execution: RemoteExecution | null
  /** Laravel kaydı ile Kestra arasındaki uyumsuzluk. */
  drift: string | null
  updated_at: string | null
  latest_version: { number: number; source: string; created_at: string | null } | null
  has_draft: boolean
  sources: string[]
  month_cost_usd: number
  /** Son 30 günün çalışmaları (test çalıştırmaları hariç). */
  runs_30d: { total: number; success: number; failed: number }
}

export interface FlowDetail extends FlowSummary {
  versions: { id: number; number: number; title: string; kestra_revision: number | null; uploaded_at: string | null; is_current: boolean; created_at: string | null; yaml: string }[]
  executions: RemoteExecution[]
}

export interface FlowsOverview {
  data: FlowSummary[]
  unmanaged: { flow_id: string; revision: number | null; disabled: boolean }[]
  kestra_error: string | null
}

export interface FeatureRequest {
  id: number
  request: string
  missing_capability: string
  reason: string | null
  status: 'open' | 'planned' | 'done' | 'dismissed'
  conversation: { id: number; title: string | null } | null
  created_at: string | null
}

async function call<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    ...init,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init?.headers },
  })
  const json = response.status === 204 ? {} : await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new Error(json.message ?? `İstek başarısız (${response.status})`)
  }

  return json as T
}

export const flowsApi = {
  overview: () => call<FlowsOverview>('/flows'),
  detail: (id: number) => call<{ data: FlowDetail; kestra_error: string | null }>(`/flows/${id}`),
  setEnabled: (id: number, enabled: boolean) =>
    call<{ data: FlowSummary }>(`/flows/${id}/${enabled ? 'enable' : 'disable'}`, { method: 'POST' }),
  remove: (id: number) => call<void>(`/flows/${id}`, { method: 'DELETE' }),
  editInChat: (id: number) => call<{ data: Conversation }>(`/flows/${id}/edit-in-chat`, { method: 'POST' }),
  featureRequests: () =>
    call<{ data: FeatureRequest[]; top_capabilities: { missing_capability: string; count: number }[] }>('/feature-requests'),
  setFeatureRequestStatus: (id: number, status: FeatureRequest['status']) =>
    call<{ data: FeatureRequest }>(`/feature-requests/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
}

export const artifactsApi = {
  get: (id: string, version?: number) => request<{ data: ArtifactDetail }>(`/artifacts/${id}${version ? `?version=${version}` : ''}`).then((r) => r.data),
}

/* ---------- Agent kurma: Oluştur ve Architect (Faz A3.3) ---------- */

export type AgentStatus = 'draft' | 'pilot' | 'live' | 'paused' | 'template'
export type PlanStepType = 'trigger' | 'read' | 'lookup' | 'decide' | 'draft' | 'approve' | 'act'

export interface PlanStep {
  type: PlanStepType
  title: string
  detail?: string
  /** Sadece karar: "hayır" dalında ne olur. */
  no?: string
}

export interface AgentTriggerInfo {
  id: number
  type: 'manual' | 'schedule' | 'event'
  config: { cron?: string; event?: string; min_delay_minutes?: number }
  input_template: string | null
  enabled: boolean
  kestra_flow_id: string | null
  synced_at: string | null
  sync_error: string | null
}

export interface AgentRunInfo {
  id: number
  agent: { id: number; name: string | null }
  status: 'queued' | 'running' | 'waiting_approval' | 'waiting_input' | 'succeeded' | 'failed' | 'cancelled'
  is_test: boolean
  input: string | null
  summary: string | null
  output: string | null
  error: string | null
  cost_usd: number
  conversation_id: number | null
  started_at: string | null
  finished_at: string | null
  actions: { id: number; type: string; status: string; requires_approval: boolean; payload: InboxActionPayload; reason: string | null }[]
}

export interface ArchitectAgent {
  id: number
  slug: string
  name: string
  description: string | null
  icon: string | null
  status: AgentStatus
  version: number
  updated_at: string | null
  conversation_id: number
  instructions: string
  model: string
  tools: { name: string; kind: 'read' | 'write'; system: string | null; label: string; constraints?: Record<string, unknown> }[]
  triggers: AgentTriggerInfo[]
  approval_policy: Record<string, unknown> | null
  plan: PlanStep[]
  suggested_prompts: string[]
  reads: string[]
  writes: string[]
  estimated_cost_usd: number | null
  last_test_run: AgentRunInfo | null
  can_go_live: boolean
  has_draft: boolean
  /** Sadece sohbetten agent'a: Architect'e gidecek ilk mesaj. */
  seed?: string
}

export interface BuildData {
  examples: string[]
  templates: { key: string; name: string; description: string | null; icon: string | null; systems: string[]; trigger: string | null; available: boolean }[]
  departments: { id: number; name: string }[]
  default_department_id: number | null
  agents: { id: number; name: string; status: AgentStatus; icon: string | null }[]
}

/** Hata olursa sunucunun mesajı gösterilir ("Canlıya almak için en az bir başarılı pilot çalışması gerekiyor"). */
async function send<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(json.message ?? `İstek başarısız (${response.status})`)
  return json.data as T
}

export const buildApi = {
  index: () => send<BuildData>('/build'),
  create: (body: { mode: 'blank' | 'template' | 'copy'; department_id?: number | null; template_key?: string; source_agent_id?: number }) => send<ArchitectAgent>('/agents', 'POST', body),
  fromConversation: (conversationId: number, actionTitle?: string) => send<ArchitectAgent>('/agents/from-conversation', 'POST', { conversation_id: conversationId, action_title: actionTitle ?? null }),
  architect: (id: number) => send<ArchitectAgent>(`/agents/${id}/architect`),
  status: (id: number, status: 'pilot' | 'live' | 'paused') => send<ArchitectAgent>(`/agents/${id}/status`, 'POST', { status }),
  run: (id: number, body: { test: boolean; input?: string }) => send<AgentRunInfo>(`/agents/${id}/runs`, 'POST', body),
  getRun: (id: number) => send<AgentRunInfo>(`/agent-runs/${id}`),
}

/** Architect'e gidecek ilk mesaj (Oluştur ekranındaki metin veya sohbetten agent'a). Sayfa yenilenirse kaybolur. */
const pendingPrompts = new Map<number, string>()
export const architectPrompt = {
  set: (agentId: number, text: string) => pendingPrompts.set(agentId, text),
  take: (agentId: number) => {
    const text = pendingPrompts.get(agentId) ?? null
    pendingPrompts.delete(agentId)
    return text
  },
}

/* ---------- Agent Kaydı ve agent sayfası (Faz A3.4) ---------- */

export interface AgentCard {
  id: number
  slug: string
  name: string
  description: string | null
  status: AgentStatus
  icon: string | null
  tools: string[]
  systems: string[]
  owner: { id: number; name: string; title: string | null } | null
  department: string | null
  department_id: number | null
  trigger: string
  mine: boolean
  created_at: string | null
  runs_7d?: number
  runs_30d?: number
  useful_pct?: number | null
  /** Geri bildirim yokken gösterilen ortalama değerlendirme puanı (değerlendirme açıksa). */
  score_avg?: number | null
  approved_actions_30d?: number
  last_run_at?: string | null
  key?: string | null
}

export interface AgentRegistry {
  counts: { live: number; pilot: number; draft: number; paused: number; template: number }
  agents: AgentCard[]
  templates: AgentCard[]
}

export type RunOrigin = 'manual' | 'schedule' | 'event' | 'chat'

export interface AgentRunRow {
  id: number
  status: AgentRunInfo['status']
  origin: RunOrigin
  is_test: boolean
  attempts: number
  summary: string | null
  started_at: string | null
  duration_seconds: number | null
  cost_usd: number
  actions_approved: number
  actions_total: number
  score: number | null
}

export interface RunScore {
  score: number
  details: { rule: string; passed: boolean; reason: string }[]
  model: string
  created_at: string | null
}

export interface AgentEvaluationInfo {
  criteria: string[]
  enabled: boolean
  can_edit: boolean
  avg_score_30d: number | null
  scored_runs_30d: number
  rule_pass_rates: { rule: string; checked: number; pass_pct: number | null }[]
  recent: { run_id: number; score: number; started_at: string | null }[]
}

export interface AgentRunDetail extends AgentRunInfo {
  origin: RunOrigin
  attempts: number
  duration_seconds: number | null
  trace: TraceStep[]
  feedback: { useful: number; not_useful: number; mine: 'useful' | 'not_useful' | null }
  score: RunScore | null
}

export interface AgentDraft {
  name: string
  description: string | null
  icon: string | null
  instructions: string
  model: string
  tools: { name: string; constraints?: { recipients?: string[]; channels?: string[]; max_per_run?: number; max_rows?: number } }[]
  triggers: { type: 'schedule' | 'event'; cron?: string; event?: string; min_delay_minutes?: number; input_template?: string | null }[]
  approval_policy: { approvers?: string[] } & Record<string, { mode: 'always' | 'internal_only_auto' | 'never' } | string[] | undefined>
  plan: PlanStep[]
  suggested_prompts: string[]
  department_id: number | null
  owner_id: number | null
}

export interface AgentDetail extends AgentCard {
  version: number
  conversation_id: number | null
  can_manage: boolean
  can_chat: boolean
  suggested_prompts: string[]
  overview: {
    kpis: {
      runs: number
      retried: number
      success_rate: number | null
      avg_seconds: number | null
      avg_seconds_delta: number | null
      cost_usd: number
      cost_per_run_usd: number | null
      useful_pct: number | null
      score_avg: number | null
      scored_runs: number
      feedback: { useful: number; not_useful: number }
      actions_approved: number
      actions_total: number
    }
    days: { date: string; succeeded: number; retried: number; failed: number; other: number }[]
    config: { trigger: string; delivery: string; reads: string; writes: string; model: string; memory: string }
  }
  recent_runs: AgentRunRow[]
  draft: AgentDraft | null
}

export interface AgentCatalog {
  tools: { name: string; kind: 'read' | 'write'; system: string; label: string; description: string }[]
  events: { name: string; label: string; default_min_delay_minutes: number | null }[]
  models: { id: string; label: string }[]
  recipient_domains: string[]
  teams_channels: string[]
  departments: { id: number; name: string }[]
  owners: { id: number; name: string; email: string }[]
}

export interface AgentVersionInfo {
  number: number
  source: string
  note: string | null
  created_at: string | null
  created_by: string | null
  snapshot: Omit<Partial<AgentDraft>, 'triggers'> & { triggers?: { type: string; cron?: string; event?: string; min_delay_minutes?: number; input_template?: string | null }[] }
  current: boolean
}

/** Doğrulama hatası (422): sunucunun hata listesi alanında. */
export class AgentConfigError extends Error {
  readonly errors: string[]
  readonly status: number

  constructor(message: string, errors: string[], status: number) {
    super(message)
    this.errors = errors
    this.status = status
  }
}

async function sendAgent<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const response = await apiFetch(`/api${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await response.json().catch(() => ({}))
  if (!response.ok) throw new AgentConfigError(json.message ?? `İstek başarısız (${response.status})`, Array.isArray(json.errors) ? json.errors : [], response.status)
  return json.data as T
}

export const agentsApi = {
  registry: () => sendAgent<AgentRegistry>('/agents'),
  get: (id: number) => sendAgent<AgentDetail>(`/agents/${id}`),
  runs: (id: number, filters: { status?: string; origin?: string; test?: '0' | '1' | 'all' }) => {
    const query = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][]).toString()
    return sendAgent<AgentRunRow[]>(`/agents/${id}/runs${query ? `?${query}` : ''}`)
  },
  run: (runId: number) => sendAgent<AgentRunDetail>(`/agent-runs/${runId}`),
  runNow: (id: number) => sendAgent<AgentRunInfo>(`/agents/${id}/runs`, 'POST', {}),
  feedback: (runId: number, rating: 'useful' | 'not_useful', comment?: string) => sendAgent<AgentRunDetail>(`/agent-runs/${runId}/feedback`, 'POST', { rating, comment: comment ?? null }),
  chats: (id: number) => sendAgent<{ id: number; conversation_id: number; title: string; started_at: string | null; is_test: boolean }[]>(`/agents/${id}/chats`),
  newChat: (id: number) => sendAgent<AgentRunInfo>(`/agents/${id}/chats`, 'POST'),
  catalog: () => sendAgent<AgentCatalog>('/agents/catalog'),
  save: (id: number, draft: AgentDraft, baseVersion: number) => sendAgent<AgentDetail>(`/agents/${id}`, 'PUT', { ...draft, base_version: baseVersion }),
  versions: (id: number) => sendAgent<AgentVersionInfo[]>(`/agents/${id}/versions`),
  restore: (id: number, number: number) => sendAgent<AgentDetail>(`/agents/${id}/versions/${number}/restore`, 'POST'),
  status: (id: number, status: 'pilot' | 'live' | 'paused') => sendAgent<unknown>(`/agents/${id}/status`, 'POST', { status }),
  evaluation: (id: number) => sendAgent<AgentEvaluationInfo>(`/agents/${id}/evaluation`),
  saveEvaluation: (id: number, criteria: string[], enabled: boolean) => sendAgent<AgentEvaluationInfo>(`/agents/${id}/evaluation`, 'PUT', { criteria, enabled }),
  score: (runId: number) => sendAgent<AgentRunDetail>(`/agent-runs/${runId}/score`, 'POST'),
}

/* ---------- Bağlantılar (Faz A4.1) ---------- */

export type ConnectorScope = 'read' | 'write·approval' | 'events'

export interface ConnectorCard {
  key: string
  label: string
  abbr: string
  category: 'data' | 'channel' | 'setup'
  description: string | null
  status: ConnectorStatus['status']
  detail: string | null
  enabled: boolean
  scopes: ConnectorScope[]
  last_checked_at: string | null
  uses_this_week: number
}

export interface ConnectorDetail extends ConnectorCard {
  disabled: { reason: string | null; by: string | null; at: string | null } | null
  checks: { ok: boolean; at: string; duration_ms: number | null }[]
  avg_duration_ms: number | null
  last_error: { message: string | null; at: string } | null
  checkable: boolean
  tools: { name: string; kind: 'read' | 'write'; label: string; description: string }[]
  used_by: { id: number; name: string; status: AgentStatus; owner: string | null }[]
  chat_queries_7d: number
  protection: { masked_groups: string[]; rules: string[] }
  needs: string[]
  /** Sadece admin: .env değişken adları ve tanımlı mı (değer asla). */
  env: { name: string; defined: boolean }[] | null
  affected_agents: number
}

export const connectorsApi = {
  list: () => sendAgent<ConnectorCard[]>('/connectors'),
  summary: async () => {
    const response = await apiFetch('/api/connectors', { headers: { Accept: 'application/json' } })
    const json = await response.json()
    return { data: json.data as ConnectorCard[], summary: json.summary as { connected: number; attention: number; needs_setup: number; disabled: number } }
  },
  get: (key: string) => sendAgent<ConnectorDetail>(`/connectors/${key}`),
  check: (key: string) => sendAgent<ConnectorDetail>(`/connectors/${key}/check`, 'POST'),
  disable: (key: string, reason: string) => sendAgent<ConnectorDetail>(`/connectors/${key}/disable`, 'POST', { reason }),
  enable: (key: string) => sendAgent<ConnectorDetail>(`/connectors/${key}/enable`, 'POST'),
}

/* ---------- Yönetişim: Genel bakış (Faz A4.2) ---------- */

export interface LiveRun {
  kind: 'agent' | 'flow'
  id: string
  name: string | null
  link: string | null
  started_by: string
  status: string
  is_test: boolean
  started_at: string | null
  seconds: number
  tool_calls: number | null
  cost_usd: number
}

export interface GovernanceAlertInfo {
  id: number
  type: string
  severity: 'info' | 'warning' | 'critical'
  message: string
  link: string | null
  created_at: string | null
  updated_at: string | null
  resolved_at: string | null
  resolved_by: string | null
}

export interface GovernanceOverview {
  live: LiveRun[]
  month: {
    label: string
    spent_usd: number
    budget_usd: number | null
    ratio: number | null
    previous_same_period_usd: number
    change_pct: number | null
    questions: number
    agent_runs: number
    actions_approved: number
    actions_rejected: number
  }
  alerts: GovernanceAlertInfo[]
  quality: {
    eval: { run_at: string | null; questions: number; passed: number; correct_tool_ratio: number | null; cost_usd: number | null } | null
    /** Son tam çalıştırmadan sonraki kısmi (--only) çalıştırma. */
    eval_partial?: { run_at: string | null; questions: number; passed: number } | null
    eval_total_questions?: number
    agent_score_avg: number | null
    scored_runs: number
    agents: { id: number; name: string | null; score_avg: number; runs: number }[]
  }
}

export const governanceApi = {
  overview: () => sendAgent<GovernanceOverview>('/governance/overview'),
  stop: (run: LiveRun, reason: string) => sendAgent<{ live: LiveRun[] }>(`/governance/runs/${run.id}/stop`, 'POST', { kind: run.kind, reason }),
  resolve: (id: number) => sendAgent<GovernanceAlertInfo>(`/governance/alerts/${id}/resolve`, 'POST'),
}

/* ---------- Yönetişim: Kullanım ve bütçeler (Faz A4.3) ---------- */

export type UsageType = 'chat' | 'agent' | 'title' | 'evaluation' | 'eval' | 'flow'

export interface UsageFilters {
  from?: string
  to?: string
  department_id?: string
  user_id?: string
  model?: string
  type?: string
}

export interface UsageReportData {
  range: { from: string; to: string }
  totals: { calls: number; cost_usd: number; input_tokens: number; output_tokens: number }
  daily: ({ date: string } & Record<UsageType, number>)[]
  by_type: { type: UsageType; label: string; calls: number; cost_usd: number }[]
  by_department: { id: number | null; name: string; calls: number; cost_usd: number }[]
  by_model: { model: string; label: string; calls: number; cost_usd: number }[]
  top_agents: { id: number; name: string; runs: number; cost_usd: number }[]
  top_users: { id: number; name: string; department: string | null; calls: number; cost_usd: number }[]
  rows_total: number
  rows: { id: number; at: string; user: string | null; department: string | null; type: UsageType; purpose: string | null; model: string; input_tokens: number; output_tokens: number; cost_usd: number }[]
  options: { departments: { id: number; name: string }[]; users: { id: number; name: string }[]; models: { id: string; label: string }[]; types: { id: UsageType; label: string }[] }
  credit_usd_value: number | null
}

export interface BudgetRow {
  id: number
  monthly_limit_usd: number | null
  warn_at_percent: number
  hard_stop: boolean
  spent_usd: number
  ratio: number | null
}

export interface BudgetsData {
  month: string
  org: BudgetRow
  departments: { id: number; name: string; budget: BudgetRow | null; spent_usd: number }[]
  users: { user_id: number; name: string | null; budget: BudgetRow }[]
  credit_usd_value: number | null
  blocks_flow_ai_steps: boolean
}

export interface BudgetInput {
  scope: 'org' | 'department' | 'user'
  scope_id?: number | null
  monthly_limit_usd: number | null
  warn_at_percent?: number
  hard_stop?: boolean
}

const usageQuery = (filters: UsageFilters) => new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '') as [string, string][]).toString()

export const governanceUsageApi = {
  report: (filters: UsageFilters) => sendAgent<UsageReportData>(`/governance/usage?${usageQuery(filters)}`),
  csvUrl: (filters: UsageFilters) => `/api/governance/usage?${usageQuery({ ...filters })}&format=csv`,
  budgets: () => sendAgent<BudgetsData>('/governance/budgets'),
  saveBudgets: (budgets: BudgetInput[], creditUsdValue: number | null) => sendAgent<BudgetsData>('/governance/budgets', 'PUT', { budgets, credit_usd_value: creditUsdValue }),
}
