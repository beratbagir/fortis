import type { ToolName } from './api'

const TOOL_LABELS: Record<string, string> = {
  get_catalog: 'Kullanılabilir adımlar inceleniyor',
  validate_flow: 'Akış doğrulanıyor',
  propose_flow: 'Akış kartı hazırlanıyor',
  list_flows: 'Mevcut akışlar listeleniyor',
  get_flow: 'Akış getiriliyor',
  get_execution: 'Çalışma inceleniyor',
  get_queries: 'Hazır sorgular inceleniyor',
  describe_warehouse: 'Veri ambarı inceleniyor',
  describe_successfactors: 'SuccessFactors inceleniyor',
  preview_successfactors: 'SuccessFactors isteği deneniyor',
  describe_apm: 'APM inceleniyor',
  preview_apm: 'APM isteği deneniyor',
  record_unsupported_request: 'İstek listesine ekleniyor',
  list_report_sources: 'Rapor kaynakları inceleniyor',
  create_report: 'Rapor hazırlanıyor',
  create_artifact: 'Hazırlanıyor',
  propose_agent: 'Agent taslağı kaydediliyor',
  query_warehouse: 'IBS sorgulanıyor',
  query_apm: 'APM sorgulanıyor',
  query_successfactors: 'SuccessFactors sorgulanıyor',
}

/** Veri sorgusu araçları: adımları sohbette amaç ve satır sayısıyla gösterilir. */
export const QUERY_TOOLS = ['query_warehouse', 'query_apm', 'query_successfactors']

const QUERY_SOURCES: Record<string, string> = { query_warehouse: 'IBS', query_apm: 'APM', query_successfactors: 'SuccessFactors' }

export const querySource = (tool: string) => QUERY_SOURCES[tool] ?? tool

export const toolLabel = (tool: ToolName | null) => (tool ? (TOOL_LABELS[tool] ?? 'Veri inceleniyor') : 'Düşünüyor')
