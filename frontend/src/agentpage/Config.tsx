import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type ReactNode } from 'react'
import { AgentConfigError, agentsApi, type AgentCatalog, type AgentDetail, type AgentDraft } from '../api'
import { describeCron } from '../agents/schedule'
import { useAuth } from '../auth/useAuth'
import { buildCron, parseSchedule, WEEK_DAYS, type ScheduleMode } from './cron'

type Trigger = AgentDraft['triggers'][number]
type Mode = 'always' | 'internal_only_auto' | 'never'

const MODE_LABEL: Record<Mode, string> = { always: 'Her zaman onay', internal_only_auto: 'İç alıcılara onaysız, diğerleri onaylı', never: 'Onaysız (sadece admin)' }

const list = (text: string) =>
  text
    .split(/[,;\n]+/)
    .map((s) => s.trim())
    .filter(Boolean)

/**
 * Yapılandırma sekmesi: Agent (model, talimat, araçlar, önerilen sorular), Tetikleyiciler, Onaylar, Ayarlar.
 * Kaydet → yeni sürüm. Kaydedilmemiş değişiklik varken uyarı görünür ve sayfadan çıkarken sorulur.
 */
export function Config({ agent, onDirty }: { agent: AgentDetail; onDirty: (dirty: boolean) => void }) {
  const catalog = useQuery({ queryKey: ['agents', 'catalog'], queryFn: agentsApi.catalog, staleTime: 300_000 })
  if (!agent.draft) return <p className="m-0 text-[13px] text-muted">Yapılandırmayı sadece agent'ın sahibi ve admin görebilir.</p>
  if (!catalog.data) return null

  return <ConfigForm key={agent.version} agent={agent} initial={agent.draft} catalog={catalog.data} onDirty={onDirty} />
}

function ConfigForm({ agent, initial, catalog, onDirty }: { agent: AgentDetail; initial: AgentDraft; catalog: AgentCatalog; onDirty: (dirty: boolean) => void }) {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.abilities.admin ?? false
  const [draft, setDraft] = useState<AgentDraft>(initial)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<string[]>([])
  const [saved, setSaved] = useState<number | null>(null)
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial)

  useEffect(() => {
    onDirty(dirty)
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty, onDirty])

  const set = (patch: Partial<AgentDraft>) => {
    setSaved(null)
    setDraft((d) => ({ ...d, ...patch }))
  }
  const tool = (name: string) => draft.tools.find((t) => t.name === name)
  const toggleTool = (name: string, on: boolean) => set({ tools: on ? [...draft.tools, { name }] : draft.tools.filter((t) => t.name !== name) })
  const setConstraints = (name: string, constraints: NonNullable<AgentDraft['tools'][number]['constraints']>) =>
    set({ tools: draft.tools.map((t) => (t.name === name ? { name, ...(Object.keys(constraints).length > 0 ? { constraints } : {}) } : t)) })
  const setTrigger = (i: number, patch: Partial<Trigger>) => set({ triggers: draft.triggers.map((t, j) => (j === i ? { ...t, ...patch } : t)) })
  const writeTools = catalog.tools.filter((t) => t.kind === 'write' && tool(t.name))
  const policyMode = (name: string): Mode => ((draft.approval_policy[name] as { mode?: Mode } | undefined)?.mode ?? 'always')

  const save = async () => {
    setBusy(true)
    setErrors([])
    try {
      const updated = await agentsApi.save(agent.id, draft, agent.version)
      queryClient.setQueryData(['agent', agent.id], updated)
      queryClient.invalidateQueries({ queryKey: ['agents', 'registry'] })
      setSaved(updated.version)
    } catch (e) {
      setErrors(e instanceof AgentConfigError && e.errors.length > 0 ? e.errors : [(e as Error).message])
    } finally {
      setBusy(false)
    }
  }

  return (
    <form
      aria-label="Yapılandırma"
      className="flex flex-col gap-5"
      onSubmit={(e) => {
        e.preventDefault()
        save()
      }}
    >
      {(dirty || saved !== null || errors.length > 0) && (
        <div role="status" className={`sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-[13px] shadow-[0_8px_24px_var(--shadow)] ${errors.length > 0 ? 'border-bad bg-bad-soft text-bad' : dirty ? 'border-warn bg-warn-soft text-ink' : 'border-ok bg-ok-soft text-ok'}`}>
          <span className="flex-1">{errors.length > 0 ? 'Kaydedilmedi.' : dirty ? 'Kaydedilmemiş değişiklikler var.' : `v${saved} olarak kaydedildi.`}</span>
          {dirty && (
            <>
              <button type="button" onClick={() => setDraft(initial)} className="rounded-md border border-line bg-surface px-2.5 py-1 text-ink">
                Vazgeç
              </button>
              <button type="submit" disabled={busy} className="rounded-md bg-ink px-2.5 py-1 font-medium text-bg disabled:opacity-60">
                Kaydet (v{agent.version + 1})
              </button>
            </>
          )}
          {errors.length > 0 && (
            <ul className="m-0 w-full list-disc pl-5 text-xs">
              {errors.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <Section title="Agent">
        <Field label="Model">
          <select value={draft.model} onChange={(e) => set({ model: e.target.value })} className={INPUT}>
            <option value="auto">Auto (soruya göre model seçilir)</option>
            {catalog.models.map((m) => (
              <option key={m.id} value={m.id}>
                {m.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Talimatlar">
          <textarea value={draft.instructions} onChange={(e) => set({ instructions: e.target.value })} rows={12} className={`${INPUT} font-mono text-[12px]`} />
        </Field>
        <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
          <legend className="mb-1 p-0 text-xs text-muted">Okuma araçları</legend>
          <div className="grid gap-1.5 sm:grid-cols-2">
            {catalog.tools
              .filter((t) => t.kind === 'read')
              .map((t) => (
                <label key={t.name} className="flex items-start gap-2 text-[13px]">
                  <input type="checkbox" checked={!!tool(t.name)} onChange={(e) => toggleTool(t.name, e.target.checked)} className="mt-0.5 accent-brand" />
                  <span>
                    {t.label} <span className="text-[11px] text-muted">· {t.system}</span>
                  </span>
                </label>
              ))}
          </div>
        </fieldset>
        <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
          <legend className="mb-1 p-0 text-xs text-muted">Yazan araçlar (her zaman onaya gider)</legend>
          {catalog.tools
            .filter((t) => t.kind === 'write')
            .map((t) => {
              const current = tool(t.name)
              const c = current?.constraints ?? {}
              return (
                <div key={t.name} className="flex flex-col gap-1.5 rounded-lg border border-line p-2.5">
                  <label className="flex items-center gap-2 text-[13px]">
                    <input type="checkbox" checked={!!current} onChange={(e) => toggleTool(t.name, e.target.checked)} className="accent-brand" />
                    {t.label} <span className="text-[11px] text-muted">· {t.system}</span>
                  </label>
                  {current && (
                    <div className="grid gap-2 pl-6 sm:grid-cols-[minmax(0,1fr)_120px]">
                      {t.name === 'post_teams' ? (
                        <Field label="Kanallar">
                          <span className="flex flex-wrap gap-2 text-[13px]">
                            {catalog.teams_channels.length === 0 && <span className="text-xs text-muted">Tanımlı kanal yok.</span>}
                            {catalog.teams_channels.map((ch) => (
                              <label key={ch} className="flex items-center gap-1">
                                <input
                                  type="checkbox"
                                  checked={(c.channels ?? []).includes(ch)}
                                  onChange={(e) => setConstraints(t.name, { ...c, channels: e.target.checked ? [...(c.channels ?? []), ch] : (c.channels ?? []).filter((x) => x !== ch) })}
                                  className="accent-brand"
                                />
                                #{ch}
                              </label>
                            ))}
                          </span>
                        </Field>
                      ) : (
                        <Field label={`Alıcılar (sadece ${catalog.recipient_domains.map((d) => `@${d}`).join(', ')})`}>
                          <input
                            value={(c.recipients ?? []).join(', ')}
                            onChange={(e) => setConstraints(t.name, { ...c, recipients: list(e.target.value) })}
                            placeholder="Boşsa izinli alan adlarındaki herkes"
                            className={INPUT}
                          />
                        </Field>
                      )}
                      <Field label="En fazla / çalışma">
                        <input type="number" min={1} max={20} value={c.max_per_run ?? ''} onChange={(e) => setConstraints(t.name, { ...c, max_per_run: e.target.value === '' ? undefined : Number(e.target.value) })} className={INPUT} />
                      </Field>
                    </div>
                  )}
                </div>
              )
            })}
        </fieldset>
        <Field label="Önerilen sorular (her satıra bir tane, en fazla 4)">
          <textarea value={draft.suggested_prompts.join('\n')} onChange={(e) => set({ suggested_prompts: e.target.value.split('\n').slice(0, 4) })} rows={3} className={INPUT} />
        </Field>
      </Section>

      <Section title="Tetikleyiciler">
        <p className="m-0 text-xs text-muted">Elle çalıştırma (Şimdi çalıştır, sohbet) her agentta vardır. Saatler Türkiye saatidir.</p>
        {draft.triggers.map((trigger, i) => (
          <TriggerEditor key={i} trigger={trigger} catalog={catalog} onChange={(patch) => setTrigger(i, patch)} onRemove={() => set({ triggers: draft.triggers.filter((_, j) => j !== i) })} />
        ))}
        <div className="flex gap-2">
          <button type="button" onClick={() => set({ triggers: [...draft.triggers, { type: 'schedule', cron: '0 6 * * *', input_template: '' }] })} className={BUTTON}>
            + Zamanlama
          </button>
          <button type="button" onClick={() => set({ triggers: [...draft.triggers, { type: 'event', event: catalog.events[0]?.name, min_delay_minutes: catalog.events[0]?.default_min_delay_minutes ?? undefined, input_template: 'Olay:\n{{event}}' }] })} className={BUTTON}>
            + Olay
          </button>
        </div>
      </Section>

      <Section title="Onaylar">
        {writeTools.length === 0 && <p className="m-0 text-xs text-muted">Yazan araç yok; onay gerekmez.</p>}
        {writeTools.map((t) => (
          <Field key={t.name} label={t.label}>
            <select
              value={policyMode(t.name)}
              onChange={(e) => set({ approval_policy: { ...draft.approval_policy, [t.name]: { mode: e.target.value as Mode } } })}
              className={INPUT}
            >
              {(Object.keys(MODE_LABEL) as Mode[])
                .filter((m) => m !== 'never' || isAdmin || policyMode(t.name) === 'never')
                .map((m) => (
                  <option key={m} value={m}>
                    {MODE_LABEL[m]}
                  </option>
                ))}
            </select>
          </Field>
        ))}
        <Field label="Onaylayanlar (sahibi + e-postalar)">
          <input
            value={(draft.approval_policy.approvers ?? ['owner']).filter((a) => a !== 'owner').join(', ')}
            onChange={(e) => set({ approval_policy: { ...draft.approval_policy, approvers: ['owner', ...list(e.target.value)] } })}
            placeholder="Sadece sahibi"
            className={INPUT}
          />
        </Field>
        {agent.status === 'pilot' && <p className="m-0 text-xs text-muted">Pilotta politika ne olursa olsun her gönderim sahibine gider ve onaylanır.</p>}
      </Section>

      <Section title="Ayarlar">
        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_80px]">
          <Field label="Ad">
            <input value={draft.name} onChange={(e) => set({ name: e.target.value })} className={INPUT} />
          </Field>
          <Field label="İkon">
            <input value={draft.icon ?? ''} maxLength={2} onChange={(e) => set({ icon: e.target.value.toLocaleUpperCase('tr') })} className={`${INPUT} font-mono`} />
          </Field>
        </div>
        <Field label="Açıklama (kartta görünür)">
          <textarea value={draft.description ?? ''} onChange={(e) => set({ description: e.target.value })} rows={2} className={INPUT} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Departman">
            <select value={draft.department_id ?? ''} onChange={(e) => set({ department_id: e.target.value === '' ? null : Number(e.target.value) })} className={INPUT}>
              <option value="">Seçilmedi</option>
              {catalog.departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Sahibi">
            {isAdmin && catalog.owners.length > 0 ? (
              <select value={draft.owner_id ?? ''} onChange={(e) => set({ owner_id: Number(e.target.value) })} className={INPUT}>
                {catalog.owners.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name} · {o.email}
                  </option>
                ))}
              </select>
            ) : (
              <span className="text-[13px] text-ink-2">{agent.owner?.name ?? '—'} (sahibi admin değiştirir)</span>
            )}
          </Field>
        </div>
      </Section>
    </form>
  )
}

const INPUT = 'w-full rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px] text-ink'
const BUTTON = 'rounded-lg border border-line px-2.5 py-1 text-xs text-ink-2 hover:bg-raised'

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
      <h2 className="m-0 text-[13px] font-medium">{title}</h2>
      {children}
    </section>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-xs text-muted">
      {label}
      {children}
    </label>
  )
}

function TriggerEditor({ trigger, catalog, onChange, onRemove }: { trigger: Trigger; catalog: AgentCatalog; onChange: (patch: Partial<Trigger>) => void; onRemove: () => void }) {
  const schedule = trigger.type === 'schedule' ? parseSchedule(trigger.cron ?? '') : null
  const [advanced, setAdvanced] = useState(schedule?.mode === 'custom')
  const setSchedule = (patch: Partial<NonNullable<typeof schedule>>) => onChange({ cron: buildCron({ ...schedule!, ...patch }) })

  return (
    <div className="flex flex-col gap-2 rounded-lg border border-line p-3">
      <div className="flex items-center gap-2 text-[13px]">
        <span className="font-medium">{trigger.type === 'schedule' ? 'Zamanlama' : 'Olay'}</span>
        {trigger.type === 'schedule' && <span className="text-xs text-muted">· {describeCron(trigger.cron ?? '')}</span>}
        <span className="flex-1" />
        <button type="button" onClick={onRemove} className="text-xs text-bad">
          Kaldır
        </button>
      </div>
      {schedule && !advanced && (
        <div className="flex flex-wrap items-end gap-2">
          <Field label="Sıklık">
            <select value={schedule.mode === 'custom' ? 'daily' : schedule.mode} onChange={(e) => setSchedule({ mode: e.target.value as ScheduleMode })} className={INPUT}>
              <option value="daily">Her gün</option>
              <option value="weekdays">Hafta içi her gün</option>
              <option value="weekly">Haftada bir</option>
            </select>
          </Field>
          {schedule.mode === 'weekly' && (
            <Field label="Gün">
              <select value={schedule.day} onChange={(e) => setSchedule({ day: Number(e.target.value) })} className={INPUT}>
                {WEEK_DAYS.map((d, i) => (
                  <option key={d} value={i}>
                    {d}
                  </option>
                ))}
              </select>
            </Field>
          )}
          <Field label="Saat">
            <input type="time" value={schedule.time} onChange={(e) => setSchedule({ time: e.target.value })} className={INPUT} />
          </Field>
          <button type="button" onClick={() => setAdvanced(true)} className={BUTTON}>
            Gelişmiş (cron)
          </button>
        </div>
      )}
      {schedule && advanced && (
        <Field label="Cron (dakika saat gün ay haftanın-günü)">
          <input value={trigger.cron ?? ''} onChange={(e) => onChange({ cron: e.target.value })} className={`${INPUT} font-mono`} />
        </Field>
      )}
      {trigger.type === 'event' && (
        <div className="flex flex-wrap items-end gap-2">
          <Field label="Olay">
            <select value={trigger.event ?? ''} onChange={(e) => onChange({ event: e.target.value })} className={INPUT}>
              {catalog.events.map((ev) => (
                <option key={ev.name} value={ev.name}>
                  {ev.label}
                </option>
              ))}
            </select>
          </Field>
          {trigger.event === 'flight.delayed' && (
            <Field label="Rötar eşiği (dk)">
              <input type="number" min={1} max={1440} value={trigger.min_delay_minutes ?? 60} onChange={(e) => onChange({ min_delay_minutes: Number(e.target.value) })} className={INPUT} />
            </Field>
          )}
        </div>
      )}
      <Field label="Görev metni (tetiklenince agent'a verilir)">
        <textarea value={trigger.input_template ?? ''} onChange={(e) => onChange({ input_template: e.target.value })} rows={2} className={INPUT} />
      </Field>
    </div>
  )
}
