import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { flowsApi, type FlowSummary } from '../api'
import { flowStatus } from '../flowStatus'
import { FeatureRequests } from '../components/FeatureRequests'
import { executionState } from '../format'
import { href, navigate } from '../router'
import { ago, money } from '../ui/format'

function triggerSummary(flow: FlowSummary): string {
  return flow.triggers.length === 0 ? 'Elle' : flow.triggers.join(' · ')
}

function lastRun(flow: FlowSummary): string {
  const run = flow.last_execution
  if (!run) return '—'
  return `${ago(run.start)} · ${executionState(run.state).label.toLocaleLowerCase('tr')}`
}

export function FlowsListPage() {
  const [tab, setTab] = useState<'flows' | 'requests'>('flows')
  const [search, setSearch] = useState('')
  const flows = useQuery({ queryKey: ['flows'], queryFn: flowsApi.overview, refetchInterval: 30_000 })
  const requests = useQuery({ queryKey: ['feature-requests'], queryFn: flowsApi.featureRequests, enabled: tab === 'requests' })

  const term = search.trim().toLocaleLowerCase('tr')
  const rows = (flows.data?.data ?? []).filter(
    (f) => term === '' || f.title.toLocaleLowerCase('tr').includes(term) || f.flow_id.includes(term),
  )

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:px-8 md:py-7">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Akışlar</h1>
          <div role="tablist" aria-label="Görünüm" className="flex gap-1 rounded-xl bg-raised p-1">
            {(['flows', 'requests'] as const).map((key) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={tab === key}
                onClick={() => setTab(key)}
                className={`h-8 rounded-[9px] px-3 text-[13px] font-bold ${tab === key ? 'bg-surface text-ink shadow-sm' : 'text-muted'}`}
              >
                {key === 'flows' ? 'Akışlar' : 'İstek listesi'}
              </button>
            ))}
          </div>
          <div className="flex-1" />
          {tab === 'flows' && (
            <label className="flex">
              <span className="sr-only">Akış ara</span>
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Akış ara"
                className="h-10 w-60 max-w-full rounded-xl border border-line-strong bg-surface px-3 text-sm"
              />
            </label>
          )}
          <a href={href({ name: 'chat', conversationId: null })} className="flex h-10 items-center rounded-xl bg-brand px-4 text-sm font-bold text-on-brand no-underline hover:bg-brand-hover">
            Sohbetle yeni akış
          </a>
        </div>

        {tab === 'requests' && requests.data && (
          <FeatureRequests data={requests.data} onChanged={() => requests.refetch()} onOpenConversation={(id) => navigate({ name: 'chat', conversationId: id })} />
        )}

        {tab === 'flows' && (
          <>
            {flows.error && <p className="rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{(flows.error as Error).message}</p>}
            {flows.data?.kestra_error && (
              <p className="rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-sm text-warn">Kestra'ya ulaşılamadı: {flows.data.kestra_error}</p>
            )}

            <div className="overflow-x-auto rounded-[18px] border border-line bg-surface">
              <table className="w-full min-w-[860px] border-collapse text-sm">
                <thead>
                  <tr className="text-left text-xs tracking-wider text-muted uppercase">
                    {['Akış', 'Durum', 'Tetikleyici', 'Kaynaklar', 'Son çalışma', 'Bu ay maliyet'].map((h) => (
                      <th key={h} scope="col" className="border-b border-line px-4 py-3 font-bold">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {flows.isLoading && (
                    <tr>
                      <td colSpan={6} className="px-4 py-6 text-muted">
                        Yükleniyor…
                      </td>
                    </tr>
                  )}
                  {flows.data && rows.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-4 py-6 text-muted">
                        {term ? 'Aramaya uyan akış yok.' : 'Henüz akış yok. Sohbetle yeni bir akış oluşturun.'}
                      </td>
                    </tr>
                  )}
                  {rows.map((flow) => {
                    const status = flowStatus(flow)
                    return (
                      <tr key={flow.id} onClick={() => navigate({ name: 'editor', flowId: flow.id })} className="cursor-pointer hover:bg-canvas">
                        <td className="border-b border-line px-4 py-3.5">
                          <a href={href({ name: 'editor', flowId: flow.id })} className="font-bold text-ink no-underline" onClick={(e) => e.stopPropagation()}>
                            {flow.title}
                          </a>
                          <div className="font-mono text-xs text-muted">{flow.flow_id}</div>
                          {flow.drift && <div className="mt-0.5 text-xs text-warn">{flow.drift}</div>}
                        </td>
                        <td className="border-b border-line px-4 py-3.5">
                          <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${status.className}`}>{status.label}</span>
                          {flow.has_draft && flow.status !== 'draft' && <div className="mt-1 text-xs font-semibold text-warn">Taslakta değişiklik var</div>}
                        </td>
                        <td className="border-b border-line px-4 py-3.5 text-ink-2">{triggerSummary(flow)}</td>
                        <td className="border-b border-line px-4 py-3.5 text-ink-2">{flow.sources.join(' · ') || '—'}</td>
                        <td className="border-b border-line px-4 py-3.5 text-ink-2">{lastRun(flow)}</td>
                        <td className="border-b border-line px-4 py-3.5 font-mono">{money(flow.month_cost_usd)}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <p className="m-0 text-[13px] text-muted">
              Maliyet, akışın oluşturulması ve düzenlenmesi için yapılan AI çağrıları ile akış içindeki AI adımlarının toplamıdır.
              {flows.data && flows.data.unmanaged.length > 0 && ` Kestra'da Fortis kaydı olmayan ${flows.data.unmanaged.length} akış var.`}
            </p>
          </>
        )}
      </div>
    </main>
  )
}
