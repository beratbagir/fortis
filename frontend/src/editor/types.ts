// Laravel'in verdiği graf modeli ve blok kataloğu. Tuval YAML'ın bir görünümüdür; tek doğruluk kaynağı Kestra YAML'ıdır.

export type Settings = Record<string, unknown>

export interface GraphNode {
  id: string | null
  kind: string
  /** Blok türünün etiketi, örn. "Bildirim · Teams" */
  label: string
  /** Kestra görevinin description'ı; yoksa id gösterilir. */
  title: string | null
  settings: Settings
  /** Gelişmiş (tanınmayan) blok: tuvalden düzenlenemez. */
  readonly: boolean
  raw_type: string | null
  /** Dış sisteme yazar (e-posta, mesaj...). Test çalıştırmada gönderilmez. */
  writes: boolean
  children?: GraphNode[]
  branches?: Record<string, GraphNode[]>
}

export interface FlowGraph {
  flow_id: string
  namespace: string
  description: string | null
  triggers: GraphNode[]
  tasks: GraphNode[]
  errors: GraphNode[]
  finally: GraphNode[]
}

export interface EditError {
  message: string
  task_id: string | null
}

export interface EditorFlow {
  id: number
  flow_id: string
  namespace: string
  title: string
  status: 'draft' | 'active' | 'inactive'
  version: number
  version_source: string
  published_version: number | null
  has_draft: boolean
  yaml: string
  graph: FlowGraph
  validation: { ok: boolean; errors: EditError[]; warnings: string[]; missing_secrets: string[] }
}

export interface JsonSchema {
  type?: string
  title?: string
  description?: string
  required?: string[]
  properties?: Record<string, JsonSchema>
  additionalProperties?: JsonSchema | boolean
  enum?: string[]
  enumNames?: string[]
  default?: unknown
  format?: string
  readOnly?: boolean
  items?: JsonSchema
}

export interface BlockDef {
  kind: string
  label: string
  group: string
  glyph: string
  tone: Tone
  id_prefix: string
  settings_schema: JsonSchema
  summary_template: string
  trigger?: boolean
  palette?: boolean
  writes?: boolean
  container?: boolean
  branches?: string[]
  branch_labels?: Record<string, string>
  available?: boolean
  unavailable_reason?: string
  readonly?: boolean
  app?: string
}

export interface AppAction {
  kind: string | null
  name: string
  description: string
  unavailable_reason?: string
}

export interface AppDef {
  app: string
  name: string
  group: string
  glyph: string
  tone: Tone
  description: string
  required_secrets: string[]
  missing_secrets: string[]
  available: boolean
  connected: boolean
  auth?: string
  actions: AppAction[]
}

export interface Catalog {
  blocks: BlockDef[]
  apps: AppDef[]
}

export type Tone = 'navy' | 'teal' | 'blue' | 'orange' | 'pink' | 'amber' | 'cyan' | 'slate' | 'rose' | 'green' | 'red'

/** Ekleme noktası: hangi listenin hangi bloğundan sonra. */
export interface InsertPoint {
  parentId: string | null
  branch: string
  afterId: string | null
}

export type Operation =
  | { op: 'update_settings'; task_id: string; settings: Settings }
  | { op: 'insert'; parent_id: string | null; branch?: string; after_id: string | null; kind: string; settings: Settings }
  | { op: 'delete'; task_id: string }
  | { op: 'move'; task_id: string; parent_id: string | null; branch?: string; after_id: string | null }
  | { op: 'update_trigger'; trigger_id: string; settings: Settings }
  | { op: 'insert_trigger'; kind: string; settings: Settings }
  | { op: 'delete_trigger'; trigger_id: string }

export type DiffMark = 'added' | 'removed' | 'changed'
