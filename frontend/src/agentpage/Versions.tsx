import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { agentsApi, type AgentDetail, type AgentVersionInfo } from '../api'
import { describeCron } from '../agents/schedule'
import { diffLines, diffSets } from './diff'
import { stamp } from './format'

const SOURCE: Record<string, string> = { architect: 'Architect', manual: 'Yapılandırma', restore: 'Geri yükleme', template: 'Şablon', copy: 'Kopya' }

const toolKeys = (v: AgentVersionInfo) => (v.snapshot.tools ?? []).map((t) => `${t.name}${t.constraints && Object.keys(t.constraints).length > 0 ? ` ${JSON.stringify(t.constraints)}` : ''}`)
const triggerKeys = (v: AgentVersionInfo) =>
  (v.snapshot.triggers ?? []).filter((t) => t.type !== 'manual').map((t) => (t.type === 'schedule' ? `Zamanlama: ${describeCron(t.cron ?? '')}` : `Olay: ${t.event}${t.min_delay_minutes ? ` ≥ ${t.min_delay_minutes} dk` : ''}`))

/** Sürümler: liste, iki sürüm arası fark (talimat metni + araç/tetikleyici), geri yükle (yeni sürüm olarak). */
export function Versions({ agent }: { agent: AgentDetail }) {
  const queryClient = useQueryClient()
  const versions = useQuery({ queryKey: ['agent-versions', agent.id, agent.version], queryFn: () => agentsApi.versions(agent.id) })
  const [selected, setSelected] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (!agent.can_manage) return <p className="m-0 text-[13px] text-muted">Sürümleri sadece agent'ın sahibi ve admin görebilir.</p>
  const list = versions.data ?? []
  const current = list.find((v) => v.current) ?? list[0]
  const chosen = list.find((v) => v.number === selected) ?? list.find((v) => !v.current) ?? null

  const restore = async (number: number) => {
    if (!window.confirm(`v${number} geri yüklensin mi? Mevcut hali silinmez; v${number}'in içeriği yeni bir sürüm olur.`)) return
    setError(null)
    try {
      queryClient.setQueryData(['agent', agent.id], await agentsApi.restore(agent.id, number))
      setSelected(null)
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
      <ol aria-label="Sürümler" className="m-0 flex list-none flex-col gap-1 p-0">
        {list.map((v) => (
          <li key={v.number}>
            <button
              type="button"
              aria-pressed={chosen?.number === v.number}
              onClick={() => setSelected(v.number)}
              disabled={v.current}
              className={`flex w-full flex-col rounded-lg px-3 py-2 text-left ${chosen?.number === v.number ? 'bg-raised' : 'hover:bg-raised'} disabled:cursor-default`}
            >
              <span className="flex items-center gap-2 text-[13px]">
                <span className="font-mono">v{v.number}</span>
                <span className="text-ink-2">{SOURCE[v.source] ?? v.source}</span>
                {v.current && <span className="rounded bg-ok-soft px-1.5 text-[10px] text-ok">güncel</span>}
              </span>
              <span className="text-[11px] text-muted">
                {stamp(v.created_at)}
                {v.created_by ? ` · ${v.created_by}` : ''}
                {v.note ? ` · ${v.note}` : ''}
              </span>
            </button>
          </li>
        ))}
      </ol>

      <section aria-label="Sürüm farkı" className="flex min-w-0 flex-col gap-3 rounded-xl border border-line bg-surface p-4">
        {error && (
          <p role="alert" className="m-0 text-sm text-bad">
            {error}
          </p>
        )}
        {!chosen || !current ? (
          <p className="m-0 text-[13px] text-muted">Karşılaştırılacak eski bir sürüm yok.</p>
        ) : (
          <>
            <div className="flex items-center gap-2 text-[13px]">
              <span className="flex-1">
                <span className="font-mono">v{chosen.number}</span> → <span className="font-mono">v{current.number}</span> (güncel)
              </span>
              <button type="button" onClick={() => restore(chosen.number)} className="rounded-lg border border-line px-2.5 py-1 text-xs hover:bg-raised">
                v{chosen.number}'i geri yükle
              </button>
            </div>
            <SetDiff title="Araçlar" {...diffSets(toolKeys(chosen), toolKeys(current))} />
            <SetDiff title="Tetikleyiciler" {...diffSets(triggerKeys(chosen), triggerKeys(current))} />
            <div className="flex flex-col gap-1">
              <h3 className="m-0 text-xs font-medium text-muted">Talimat</h3>
              <pre aria-label="Talimat farkı" className="m-0 overflow-x-auto rounded-lg bg-canvas p-3 text-[12px] leading-relaxed whitespace-pre-wrap">
                {diffLines(chosen.snapshot.instructions ?? '', current.snapshot.instructions ?? '').map((line, i) => (
                  <span key={i} className={`block ${line.kind === 'added' ? 'bg-ok-soft text-ok' : line.kind === 'removed' ? 'bg-bad-soft text-bad line-through' : 'text-ink-2'}`}>
                    {line.kind === 'added' ? '+ ' : line.kind === 'removed' ? '− ' : '  '}
                    {line.text}
                  </span>
                ))}
              </pre>
            </div>
          </>
        )}
      </section>
    </div>
  )
}

function SetDiff({ title, added, removed }: { title: string; added: string[]; removed: string[] }) {
  return (
    <div className="flex flex-col gap-1 text-xs">
      <h3 className="m-0 text-xs font-medium text-muted">{title}</h3>
      {added.length === 0 && removed.length === 0 && <span className="text-muted">Değişiklik yok</span>}
      {added.map((x) => (
        <span key={`+${x}`} className="text-ok">
          + {x}
        </span>
      ))}
      {removed.map((x) => (
        <span key={`-${x}`} className="text-bad">
          − {x}
        </span>
      ))}
    </div>
  )
}
