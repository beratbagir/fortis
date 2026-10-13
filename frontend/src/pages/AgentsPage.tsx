import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { agentsApi, architectPrompt, buildApi, flowsApi, type AgentCard } from '../api'
import { cardStrip, STATUS_BADGE } from '../agentpage/format'
import { useAuth } from '../auth/useAuth'
import { href, navigate } from '../router'
import { ChartIcon, ChatIcon, LockIcon, PlusIcon, SearchIcon } from '../ui/icons'

type Sort = 'used' | 'newest' | 'name'

/** Agent Kaydı (#/agents): her agent'ın ne yaptığı, kimin olduğu, hangi sistemlere dokunduğu ve nasıl gittiği. */
export function AgentsPage() {
  const { user } = useAuth()
  const canBuild = user?.abilities.build ?? false
  const registry = useQuery({ queryKey: ['agents', 'registry'], queryFn: agentsApi.registry, refetchInterval: 60_000 })
  const [search, setSearch] = useState('')
  const [department, setDepartment] = useState<string | null>(null)
  const [sort, setSort] = useState<Sort>('used')
  const [showFlows, setShowFlows] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const data = registry.data
  const term = search.trim().toLocaleLowerCase('tr')
  const matches = (a: AgentCard) =>
    term === '' || [a.name, a.description ?? '', a.owner?.name ?? '', a.department ?? '', ...a.systems].some((v) => v.toLocaleLowerCase('tr').includes(term))
  const searched = (data?.agents ?? []).filter(matches)
  const departments = [...new Set(searched.map((a) => a.department ?? 'Departmansız'))].sort((a, b) => a.localeCompare(b, 'tr'))
  const list = searched
    .filter((a) => department === null || (a.department ?? 'Departmansız') === department)
    .sort((a, b) => (sort === 'name' ? a.name.localeCompare(b.name, 'tr') : sort === 'newest' ? (b.created_at ?? '').localeCompare(a.created_at ?? '') : (b.runs_30d ?? 0) - (a.runs_30d ?? 0)))

  const install = async (key: string) => {
    setError(null)
    try {
      const agent = await buildApi.create({ mode: 'template', template_key: key })
      architectPrompt.take(agent.id)
      navigate({ name: 'architect', agentId: agent.id })
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <header className="flex flex-col gap-4 border-b border-line px-4 pt-6 pb-5 md:px-8">
        <div className="flex flex-wrap items-start gap-3">
          <p className="m-0 flex-1 text-xs text-muted">
            {data && (
              <>
                <b className="font-mono text-ink">{data.counts.live}</b> canlı · <b className="font-mono text-ink">{data.counts.pilot}</b> pilotta · <b className="font-mono text-ink">{data.counts.template}</b> şablon
              </>
            )}
          </p>
          {canBuild && (
            <a href={href({ name: 'build' })} className="flex h-9 items-center gap-1.5 rounded-lg bg-ink px-3 text-[13px] font-medium text-bg no-underline">
              <PlusIcon width={12} height={12} /> Yeni agent
            </a>
          )}
        </div>
        <div className="flex flex-col items-center gap-1 text-center">
          <span className="text-xs text-muted">Freebird Airlines</span>
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Agent Kaydı</h1>
          <p className="m-0 max-w-[460px] text-[13px] text-muted">Her agent ne yapar, hangi departmanın sorumluluğunda, hangi sistemlere dokunur ve nasıl çalışıyor.</p>
        </div>
        <label className="mx-auto flex w-full max-w-[420px] items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-muted">
          <SearchIcon />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Agent, sistem, sahip ara…" aria-label="Agentlarda ara" className="flex-1 bg-transparent text-sm text-ink outline-none" />
        </label>
        <div role="group" aria-label="Departman" className="flex flex-wrap justify-center gap-1.5">
          <Chip active={department === null} onClick={() => setDepartment(null)} label="Tümü" count={searched.length} />
          {departments.map((d) => (
            <Chip key={d} active={department === d} onClick={() => setDepartment(d)} label={d} count={searched.filter((a) => (a.department ?? 'Departmansız') === d).length} />
          ))}
        </div>
      </header>

      <div className="flex flex-col gap-4 px-4 py-5 md:px-8">
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted">
          <span className="flex-1">{list.length} agent</span>
          <label className="flex items-center gap-1.5">
            <input type="checkbox" checked={showFlows} onChange={(e) => setShowFlows(e.target.checked)} className="accent-brand" />
            Akışları da göster
          </label>
          <label className="flex items-center gap-1.5">
            Sırala
            <select value={sort} onChange={(e) => setSort(e.target.value as Sort)} className="rounded-md border border-line bg-surface px-1.5 py-0.5 text-ink">
              <option value="used">En çok kullanılan</option>
              <option value="newest">En yeni</option>
              <option value="name">Ada göre</option>
            </select>
          </label>
        </div>

        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}
        {registry.isSuccess && list.length === 0 && (
          <p className="m-0 rounded-lg border border-dashed border-line px-3 py-8 text-center text-[13px] text-muted">{term || department ? 'Eşleşen agent yok.' : 'Henüz agent yok. Oluştur ekranından ilkini kur.'}</p>
        )}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {list.map((agent) => (
            <AgentCardView key={agent.id} agent={agent} />
          ))}
        </div>

        {data && data.templates.length > 0 && (
          <section aria-labelledby="templates-title" className="mt-4 flex flex-col gap-3">
            <h2 id="templates-title" className="m-0 text-sm font-medium">
              Şablonlar
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {data.templates.filter(matches).map((template) => (
                <AgentCardView key={template.id} agent={template} onInstall={canBuild && template.key ? () => install(template.key!) : undefined} />
              ))}
            </div>
          </section>
        )}

        {showFlows && <FlowsSection />}
      </div>
    </main>
  )
}

function Chip({ active, onClick, label, count }: { active: boolean; onClick: () => void; label: string; count: number }) {
  return (
    <button type="button" aria-pressed={active} onClick={onClick} className={`rounded-full border px-2.5 py-1 text-xs ${active ? 'border-ink bg-ink text-bg' : 'border-line text-ink-2 hover:bg-raised'}`}>
      {label} <span className="font-mono opacity-70">{count}</span>
    </button>
  )
}

export function AgentCardView({ agent, onInstall }: { agent: AgentCard; onInstall?: () => void }) {
  const [label, tone] = STATUS_BADGE[agent.status]
  const template = agent.status === 'template'

  return (
    <article aria-label={agent.name} className="flex flex-col gap-2.5 rounded-xl border border-line bg-surface p-3.5">
      <div className="flex items-start gap-2.5">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-raised font-mono text-[11px] font-medium text-ink-2" aria-hidden>
          {agent.icon ?? agent.name.slice(0, 2).toLocaleUpperCase('tr')}
        </span>
        <span className="flex min-w-0 flex-1 flex-col">
          {template ? (
            <span className="truncate font-serif text-[16px] text-ink">{agent.name}</span>
          ) : (
            <a href={href({ name: 'agent', agentId: agent.id, tab: 'overview', runId: null })} className="truncate font-serif text-[16px] text-ink no-underline hover:underline">
              {agent.name}
            </a>
          )}
          <span className="truncate text-[11px] text-muted">
            {[agent.department, agent.owner ? `sahibi ${agent.owner.title ?? agent.owner.name}` : null].filter(Boolean).join(' · ') || '—'}
          </span>
        </span>
        <span className={`rounded px-1.5 py-px text-[10px] font-medium ${tone}`}>{label}</span>
      </div>
      {agent.description && <p className="m-0 line-clamp-3 text-[13px] text-ink-2">{agent.description}</p>}
      {agent.systems.length > 0 && (
        <ul aria-label="Sistemler" className="m-0 flex list-none flex-wrap gap-1 p-0">
          {agent.systems.map((s) => (
            <li key={s} className="rounded border border-line px-1.5 py-px text-[10px] text-ink-2">
              {s}
            </li>
          ))}
        </ul>
      )}
      <div className="mt-auto flex items-center gap-1.5 border-t border-line pt-2.5">
        <span className="flex-1 truncate font-mono text-[11px] text-muted">{cardStrip(agent)}</span>
        {template ? (
          onInstall && (
            <button type="button" onClick={onInstall} className="rounded-md bg-ink px-2 py-1 text-[11px] font-medium text-bg">
              Kur
            </button>
          )
        ) : (
          <>
            <IconLink href={href({ name: 'agent', agentId: agent.id, tab: 'chat', runId: null })} label={`${agent.name}: sohbet`} dark>
              <ChatIcon width={13} height={13} />
            </IconLink>
            <IconLink href={href({ name: 'agent', agentId: agent.id, tab: 'overview', runId: null })} label={`${agent.name}: izleme`}>
              <ChartIcon width={13} height={13} />
            </IconLink>
            <IconLink href={href({ name: 'agent', agentId: agent.id, tab: 'config', runId: null })} label={`${agent.name}: izinler`}>
              <LockIcon width={13} height={13} />
            </IconLink>
          </>
        )}
      </div>
    </article>
  )
}

function IconLink({ href: to, label, dark = false, children }: { href: string; label: string; dark?: boolean; children: React.ReactNode }) {
  return (
    <a href={to} aria-label={label} title={label} className={`flex size-7 items-center justify-center rounded-md no-underline ${dark ? 'bg-ink text-bg' : 'border border-line text-ink-2 hover:bg-raised'}`}>
      {children}
    </a>
  )
}

/** Sabit kurallı Kestra akışları (Agent Kaydı'nda isteğe bağlı). */
function FlowsSection() {
  const flows = useQuery({ queryKey: ['flows'], queryFn: flowsApi.overview })

  return (
    <section aria-labelledby="flows-title" className="mt-4 flex flex-col gap-3">
      <h2 id="flows-title" className="m-0 text-sm font-medium">
        Akışlar (sabit kurallı)
      </h2>
      {flows.isError && <p className="m-0 text-xs text-bad">Akışlar yüklenemedi.</p>}
      <ul className="m-0 grid list-none gap-2 p-0 sm:grid-cols-2 xl:grid-cols-3">
        {(flows.data?.data ?? []).map((flow) => (
          <li key={flow.id}>
            <a href={href({ name: 'editor', flowId: flow.id })} className="flex flex-col gap-0.5 rounded-lg border border-line bg-surface px-3 py-2 text-ink no-underline hover:bg-canvas">
              <span className="text-[13px] font-medium">{flow.title}</span>
              <span className="text-[11px] text-muted">{flow.sources.join(', ') || flow.flow_id}</span>
            </a>
          </li>
        ))}
      </ul>
    </section>
  )
}
