import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { connectorsApi, policiesApi, type ConnectorCard, type PoliciesData, type PolicyValues } from '../api'
import { Dialog } from '../ui/Dialog'
import { Budgets } from './Budgets'
import { usd } from './money'

type Message = { tone: 'ok' | 'bad'; text: string }

/** Yönetişim > Politikalar (admin): veri, modeller, bütçe, agentlar, bağlantılar. Her değişiklik onaydan sonra kaydedilir ve denetim kaydına önceki/yeni değerle yazılır. */
export function GovernancePoliciesView() {
  const policies = useQuery({ queryKey: ['governance', 'policies'], queryFn: policiesApi.get })
  // Kayıttan sonra form sunucudaki değerlerle yeniden kurulur; sonuç mesajı bu yüzden burada tutulur.
  const [message, setMessage] = useState<Message | null>(null)

  return (
    <main className="min-w-0 flex-1 overflow-y-auto">
      <div className="flex flex-col gap-5 px-4 py-6 md:px-8">
        <header className="flex flex-col gap-1">
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight">Politikalar</h1>
          <p className="m-0 text-[13px] text-muted">Her değişiklik onay penceresinden geçer ve denetim kaydına önceki ve yeni değeriyle yazılır.</p>
        </header>
        {policies.isError && (
          <p role="alert" className="m-0 text-sm text-bad">
            Politikalar yüklenemedi.
          </p>
        )}
        {policies.data && <PoliciesForm key={policies.dataUpdatedAt} data={policies.data} message={message} setMessage={setMessage} />}
      </div>
    </main>
  )
}

const LABELS: Record<keyof PolicyValues, string> = {
  ai_data_access: 'AI veri erişimi',
  ai_allow_employee_names: "Çalışan adı/soyadı AI'a gidebilir",
  allowed_models: 'İzinli modeller',
  default_model: 'Genel varsayılan model',
  data_question_model: 'Veri sorusu modeli',
  fast_model: 'Hızlı model',
  budget_blocks_flow_ai_steps: 'Bütçe dolunca akış içi AI adımları dursun',
  allow_external_recipients: 'Şirket dışı alıcı',
  allowed_recipient_domains: 'Şirket içi alan adları',
  max_tool_calls: 'Çalışma başına araç çağrısı sınırı',
  max_cost_usd: 'Çalışma başına maliyet sınırı',
  max_seconds: 'Çalışma süresi sınırı',
}

const pick = (data: PoliciesData): PolicyValues => ({
  ai_data_access: data.ai_data_access,
  ai_allow_employee_names: data.ai_allow_employee_names,
  allowed_models: data.allowed_models,
  default_model: data.default_model,
  data_question_model: data.data_question_model,
  fast_model: data.fast_model,
  budget_blocks_flow_ai_steps: data.budget_blocks_flow_ai_steps,
  allow_external_recipients: data.allow_external_recipients,
  allowed_recipient_domains: data.allowed_recipient_domains,
  max_tool_calls: data.max_tool_calls,
  max_cost_usd: data.max_cost_usd,
  max_seconds: data.max_seconds,
})

const parseDomains = (text: string) => [
  ...new Set(
    text
      .split(/[\s,]+/)
      .map((d) => d.trim().toLowerCase())
      .filter(Boolean),
  ),
]

function PoliciesForm({ data, message, setMessage }: { data: PoliciesData; message: Message | null; setMessage: (m: Message | null) => void }) {
  const queryClient = useQueryClient()
  const initial = pick(data)
  const [draft, setDraft] = useState<PolicyValues>(initial)
  const [domains, setDomains] = useState(initial.allowed_recipient_domains.join(', '))
  const [confirming, setConfirming] = useState(false)
  const set = (patch: Partial<PolicyValues>) => setDraft((d) => ({ ...d, ...patch }))
  const label = (id: string) => data.model_catalog.find((m) => m.id === id)?.label ?? id

  const current: PolicyValues = { ...draft, allowed_recipient_domains: parseDomains(domains) }
  const changes = (Object.keys(LABELS) as (keyof PolicyValues)[]).filter((k) => JSON.stringify(current[k]) !== JSON.stringify(initial[k]))
  const show = (key: keyof PolicyValues, value: PolicyValues[keyof PolicyValues]): string => {
    if (typeof value === 'boolean') return key === 'allow_external_recipients' ? (value ? 'onaylı' : 'kapalı') : value ? 'açık' : 'kapalı'
    if (key === 'ai_data_access') return value === 'masked' ? 'Maskeli' : 'Kapalı'
    if (key === 'allowed_models') return (value as string[]).map(label).join(', ')
    if (key === 'allowed_recipient_domains') return (value as string[]).join(', ')
    if (key.endsWith('_model')) return label(value as string)
    if (key === 'max_cost_usd') return usd(value as number)
    if (key === 'max_seconds') return `${Math.round((value as number) / 60)} dk`
    return String(value)
  }

  const save = async () => {
    setConfirming(false)
    setMessage(null)
    try {
      const patch = Object.fromEntries(changes.map((k) => [k, current[k]])) as Partial<PolicyValues>
      queryClient.setQueryData(['governance', 'policies'], await policiesApi.update(patch))
      queryClient.invalidateQueries({ queryKey: ['ai-settings'] })
      setMessage({ tone: 'ok', text: 'Politikalar kaydedildi.' })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    }
  }

  const allowed = data.model_catalog.filter((m) => draft.allowed_models.includes(m.id))

  return (
    <>
      {message && (
        <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
          {message.text}
        </p>
      )}

      <Group title="Veri">
        <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
          <legend className="mb-1.5 p-0 text-[13px] font-medium">AI veri erişimi</legend>
          <Radio name="data" checked={draft.ai_data_access === 'off'} onChange={() => set({ ai_data_access: 'off' })} label="Kapalı" note="Claude şirket verisine bakmaz." />
          <Radio name="data" checked={draft.ai_data_access === 'masked'} onChange={() => set({ ai_data_access: 'masked' })} label="Maskeli" note="Claude veriye bakar; kişisel bilgiler maskelenir." />
        </fieldset>
        <Check checked={draft.ai_allow_employee_names} onChange={(v) => set({ ai_allow_employee_names: v })} label={LABELS.ai_allow_employee_names} note="Kapalıysa SuccessFactors çalışan adları da maskelenir." />
        <p className="m-0 text-xs text-muted">
          Claude'a sorgu başına en fazla {data.max_rows.toLocaleString('tr-TR')} satır ve {data.max_chars.toLocaleString('tr-TR')} karakter gider (sunucu ayarı, buradan değişmez).
        </p>
      </Group>

      <Group title="Modeller">
        <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
          <legend className="mb-1.5 p-0 text-[13px] font-medium">İzinli modeller</legend>
          {data.model_catalog.map((m) => (
            <Check
              key={m.id}
              checked={draft.allowed_models.includes(m.id)}
              onChange={(v) => set({ allowed_models: v ? data.model_catalog.map((c) => c.id).filter((id) => id === m.id || draft.allowed_models.includes(id)) : draft.allowed_models.filter((id) => id !== m.id) })}
              label={m.label}
              note={`${m.strength ?? ''} · $${m.input_per_mtok} / $${m.output_per_mtok} (1M token giriş/çıkış)`}
            />
          ))}
        </fieldset>
        <div className="flex flex-wrap gap-3">
          {(['default_model', 'data_question_model', 'fast_model'] as const).map((key) => (
            <label key={key} className="flex flex-col gap-1 text-xs text-muted">
              {LABELS[key]}
              <select value={draft[key]} onChange={(e) => set({ [key]: e.target.value })} className={INPUT}>
                {!allowed.some((m) => m.id === draft[key]) && <option value={draft[key]}>{label(draft[key])} (izinli değil)</option>}
                {allowed.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.label}
                  </option>
                ))}
              </select>
            </label>
          ))}
        </div>
        <p className="m-0 text-xs text-muted">Auto seçili sohbetlerde ilk tur genel varsayılan, veri sorgusundan sonraki turlar veri sorusu modeli, sohbet başlığı hızlı modelle yazılır.</p>
      </Group>

      <Group title="Bütçe">
        <p className="m-0 text-xs text-muted">
          Şirket bütçesi: {data.company_budget.monthly_limit_usd === null ? 'limitsiz' : `${usd(data.company_budget.monthly_limit_usd)} / ay`} · uyarı %{data.company_budget.warn_at_percent} · dolunca durdur{' '}
          {data.company_budget.hard_stop ? 'açık' : 'kapalı'}. Limitler aşağıdaki Bütçeler tablosundan değişir.
        </p>
        <Check checked={draft.budget_blocks_flow_ai_steps} onChange={(v) => set({ budget_blocks_flow_ai_steps: v })} label={LABELS.budget_blocks_flow_ai_steps} note="Durursa ilgili akış adımı bütçe hatasıyla biter." />
      </Group>
      <Budgets />

      <Group title="Agentlar">
        <div className="flex flex-col gap-1">
          <span className="text-[13px] font-medium">Onay politikası</span>
          <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
            {data.approval_policy.map((p) => (
              <li key={p.tool} className="rounded-md bg-raised px-2 py-1 text-xs">
                {p.label}: <strong>her zaman onay</strong>
              </li>
            ))}
          </ul>
          <span className="text-xs text-muted">Pilot boyunca yazan bütün araçlar insan onayından geçer; bu kural kodda zorunlu ve buradan değişmez.</span>
        </div>
        <label className="flex flex-col gap-1 text-xs text-muted">
          {LABELS.allow_external_recipients}
          <select value={draft.allow_external_recipients ? 'approval' : 'off'} onChange={(e) => set({ allow_external_recipients: e.target.value === 'approval' })} className={`${INPUT} w-fit`}>
            <option value="off">Kapalı</option>
            <option value="approval">Onaylı</option>
          </select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted">
          {LABELS.allowed_recipient_domains} (virgülle)
          <input value={domains} onChange={(e) => setDomains(e.target.value)} className={`${INPUT} max-w-md`} />
        </label>
        <div className="flex flex-wrap gap-3">
          <Num label={LABELS.max_tool_calls} value={draft.max_tool_calls} min={1} max={100} onChange={(v) => set({ max_tool_calls: v })} />
          <Num label={`${LABELS.max_cost_usd} ($)`} value={draft.max_cost_usd} min={0.1} max={50} step={0.1} onChange={(v) => set({ max_cost_usd: v })} />
          <Num label={`${LABELS.max_seconds} (dk)`} value={Math.round(draft.max_seconds / 60)} min={1} max={60} onChange={(v) => set({ max_seconds: v * 60 })} />
        </div>
        <span className="text-xs text-muted">Sınırı aşan çalışma durdurulur ve Yönetişim'de kritik uyarı açılır.</span>
      </Group>

      <ConnectorPolicies />

      <div className="sticky bottom-0 -mx-4 flex items-center gap-2 border-t border-line bg-bg px-4 py-3 md:-mx-8 md:px-8">
        <span className="mr-auto text-[13px] text-muted">{changes.length === 0 ? 'Kaydedilmemiş değişiklik yok.' : `${changes.length} kaydedilmemiş değişiklik`}</span>
        <button
          type="button"
          disabled={changes.length === 0}
          onClick={() => {
            setDraft(initial)
            setDomains(initial.allowed_recipient_domains.join(', '))
          }}
          className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised disabled:opacity-50"
        >
          Vazgeç
        </button>
        <button type="button" disabled={changes.length === 0} onClick={() => setConfirming(true)} className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
          Kaydet
        </button>
      </div>

      {confirming && (
        <Dialog title="Politika değişiklikleri kaydedilsin mi?" onClose={() => setConfirming(false)}>
          <ul className="m-0 mb-3 flex list-none flex-col gap-1.5 p-0 text-sm">
            {changes.map((k) => (
              <li key={k}>
                <span className="font-medium">{LABELS[k]}:</span> <span className="text-muted line-through">{show(k, initial[k])}</span> → {show(k, current[k])}
              </li>
            ))}
          </ul>
          <p className="m-0 mb-3 text-xs text-muted">Her değişiklik önceki ve yeni değeriyle denetim kaydına yazılır.</p>
          <span className="flex justify-end gap-2">
            <button type="button" onClick={() => setConfirming(false)} className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised">
              Vazgeç
            </button>
            <button type="button" onClick={save} className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg">
              Onayla ve kaydet
            </button>
          </span>
        </Dialog>
      )}
    </>
  )
}

/** Bağlantılar: Bağlantılar ekranıyla aynı veri; kapatma gerekçe ister. */
function ConnectorPolicies() {
  const queryClient = useQueryClient()
  const list = useQuery({ queryKey: ['connectors', 'policies'], queryFn: connectorsApi.list })
  const [closing, setClosing] = useState<ConnectorCard | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const toggle = async (connector: ConnectorCard, why?: string) => {
    setError(null)
    try {
      await (connector.enabled ? connectorsApi.disable(connector.key, why ?? '') : connectorsApi.enable(connector.key))
      setClosing(null)
      setReason('')
      await queryClient.invalidateQueries({ queryKey: ['connectors'] })
    } catch (e) {
      setError((e as Error).message)
    }
  }
  const data = (list.data ?? []).filter((c) => c.category !== 'setup')

  return (
    <Group title="Bağlantılar">
      <p className="m-0 text-xs text-muted">
        Kapatılan bağlantının araçları sohbette ve agentlarda kullanılamaz. Ayrıntılar <a href="#/connectors">Bağlantılar</a> ekranında.
      </p>
      {error && !closing && (
        <p role="alert" className="m-0 text-xs text-bad">
          {error}
        </p>
      )}
      <ul className="m-0 flex list-none flex-col p-0">
        {data.map((c) => (
          <li key={c.key} className="flex items-center gap-3 border-t border-line py-2 text-[13px] first:border-t-0">
            <a href={`#/connectors/${c.key}`} className="min-w-0 flex-1 truncate text-ink">
              {c.label}
            </a>
            <span className={`text-xs ${c.enabled ? 'text-ok' : 'text-muted'}`}>{c.enabled ? 'Açık' : 'Şirket genelinde kapalı'}</span>
            <button type="button" onClick={() => (c.enabled ? setClosing(c) : toggle(c))} className="rounded-lg border border-line px-2.5 py-1 text-xs hover:bg-raised">
              {c.enabled ? 'Kapat' : 'Aç'}
            </button>
          </li>
        ))}
      </ul>
      {closing && (
        <Dialog title={`${closing.label} kapatılsın mı?`} onClose={() => setClosing(null)}>
          <label className="mb-3 flex flex-col gap-1 text-sm">
            Gerekçe (zorunlu)
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} className="rounded-lg border border-line bg-surface p-2 text-sm" />
          </label>
          {error && (
            <p role="alert" className="m-0 mb-3 text-xs text-bad">
              {error}
            </p>
          )}
          <span className="flex justify-end gap-2">
            <button type="button" onClick={() => setClosing(null)} className="rounded-lg border border-line px-3 py-1.5 text-[13px] hover:bg-raised">
              Vazgeç
            </button>
            <button type="button" disabled={reason.trim().length < 3} onClick={() => toggle(closing, reason.trim())} className="rounded-lg bg-bad px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
              Kapat
            </button>
          </span>
        </Dialog>
      )}
    </Group>
  )
}

const INPUT = 'rounded-md border border-line bg-surface px-2 py-1 text-[13px] text-ink'

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
      <h2 className="m-0 text-[15px] font-medium">{title}</h2>
      {children}
    </section>
  )
}

function Radio({ name, checked, onChange, label, note }: { name: string; checked: boolean; onChange: () => void; label: string; note: string }) {
  return (
    <label className="flex items-baseline gap-2 text-[13px]">
      <input type="radio" name={name} checked={checked} onChange={onChange} className="accent-brand" />
      <span>
        {label} <span className="text-xs text-muted">{note}</span>
      </span>
    </label>
  )
}

function Check({ checked, onChange, label, note }: { checked: boolean; onChange: (v: boolean) => void; label: string; note?: string }) {
  return (
    <label className="flex items-baseline gap-2 text-[13px]">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="accent-brand" />
      <span>
        {label} {note && <span className="text-xs text-muted">{note}</span>}
      </span>
    </label>
  )
}

function Num({ label, value, onChange, min, max, step = 1 }: { label: string; value: number; onChange: (v: number) => void; min: number; max: number; step?: number }) {
  return (
    <label className="flex flex-col gap-1 text-xs text-muted">
      {label}
      <input type="number" value={value} min={min} max={max} step={step} onChange={(e) => onChange(Number(e.target.value))} className={`${INPUT} w-28`} />
    </label>
  )
}
