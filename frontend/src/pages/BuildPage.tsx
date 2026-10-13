import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { architectPrompt, buildApi, type BuildData } from '../api'
import { href, navigate } from '../router'
import { AgentIcon, ArrowUpIcon } from '../ui/icons'

type Mode = 'blank' | 'template' | 'copy'

const MODES: { key: Mode; label: string }[] = [
  { key: 'blank', label: 'Boş' },
  { key: 'template', label: 'Şablondan' },
  { key: 'copy', label: "Var olan agent'ı kopyala" },
]

const VISIBLE_TEMPLATES = 3

/** Oluştur (#/build): yeni agent'ı düz dille tarif et; boş, şablondan veya kopyalayarak başla. Architect'e gider. */
export function BuildPage() {
  const build = useQuery({ queryKey: ['build'], queryFn: buildApi.index })
  const [text, setText] = useState('')
  const [mode, setMode] = useState<Mode>('blank')
  const [department, setDepartment] = useState<number | null | undefined>(undefined)
  const [templateKey, setTemplateKey] = useState('')
  const [sourceId, setSourceId] = useState<number | ''>('')
  const [allTemplates, setAllTemplates] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const data = build.data
  const departmentId = department === undefined ? (data?.default_department_id ?? null) : department
  const availableTemplates = (data?.templates ?? []).filter((t) => t.available)

  const start = async (body: Parameters<typeof buildApi.create>[0], prompt: string) => {
    setBusy(true)
    setError(null)
    try {
      const agent = await buildApi.create({ ...body, department_id: departmentId })
      if (prompt.trim() !== '') architectPrompt.set(agent.id, prompt.trim())
      navigate({ name: 'architect', agentId: agent.id })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Agent oluşturulamadı.')
      setBusy(false)
    }
  }

  const submit = () => {
    if (mode === 'template') {
      if (!templateKey) return setError('Bir şablon seç.')
      return start({ mode, template_key: templateKey }, text)
    }
    if (mode === 'copy') {
      if (sourceId === '') return setError('Kopyalanacak agentı seç.')
      return start({ mode, source_agent_id: sourceId }, text)
    }
    if (text.trim() === '') return setError("Agent'ın ne yapacağını yaz.")
    return start({ mode: 'blank' }, text)
  }

  return (
    <main className="flex-1 overflow-y-auto">
      <div className="mx-auto flex max-w-[680px] flex-col gap-6 px-4 py-10 md:py-16">
        <div className="flex justify-end">
          <a href={href({ name: 'flows' })} className="text-xs text-muted no-underline hover:text-ink">
            Akışlar →
          </a>
        </div>

        <header className="flex flex-col items-center gap-3 text-center">
          <span className="flex size-10 items-center justify-center rounded-lg bg-ink text-bg" aria-hidden>
            <AgentIcon />
          </span>
          <h1 className="m-0 font-serif text-[30px] font-normal tracking-tight md:text-[34px]">Yeni agent'ın ne yapsın?</h1>
          <p className="m-0 max-w-[460px] text-[13px] text-muted">İşi düz bir dille anlat. Architect adımları, sistem bağlantılarını ve onayları sen konuşurken hazırlar; akış çizmeyi öğrenmen gerekmez.</p>
        </header>

        <form
          className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-3 shadow-[0_4px_14px_var(--shadow)]"
          onSubmit={(e) => {
            e.preventDefault()
            submit()
          }}
        >
          <label className="flex">
            <span className="sr-only">Agent'ın görevi</span>
            <textarea
              value={text}
              onChange={(e) => setText(e.target.value)}
              rows={3}
              placeholder="Örn. Her sabah dünkü rötarları nedenleriyle özetle ve OCC ekibine e-postayla gönder…"
              className="flex-1 resize-none border-0 bg-transparent px-1 text-sm text-ink outline-none"
            />
          </label>
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <label className="flex items-center gap-1.5 rounded-md border border-line px-2 py-1 text-muted">
              Departman
              <select value={departmentId ?? ''} onChange={(e) => setDepartment(e.target.value === '' ? null : Number(e.target.value))} className="bg-transparent text-ink outline-none">
                <option value="">Seçilmedi</option>
                {data?.departments.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </select>
            </label>
            <span role="radiogroup" aria-label="Başlangıç" className="flex gap-1">
              {MODES.map((m) => (
                <button key={m.key} type="button" role="radio" aria-checked={mode === m.key} onClick={() => setMode(m.key)} className={`rounded-md px-2 py-1 ${mode === m.key ? 'bg-raised font-medium text-ink' : 'text-muted hover:text-ink'}`}>
                  {m.label}
                </button>
              ))}
            </span>
            <button type="submit" aria-label="Architect'i başlat" disabled={busy} className="ml-auto flex size-8 items-center justify-center rounded-lg bg-brand text-on-brand hover:bg-brand-hover disabled:opacity-60">
              <ArrowUpIcon />
            </button>
          </div>
          {mode === 'template' && (
            <label className="flex flex-col gap-1 text-xs text-muted">
              Şablon
              <select value={templateKey} onChange={(e) => setTemplateKey(e.target.value)} className="rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                <option value="">Seç…</option>
                {availableTemplates.map((t) => (
                  <option key={t.key} value={t.key}>
                    {t.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          {mode === 'copy' && (
            <label className="flex flex-col gap-1 text-xs text-muted">
              Kopyalanacak agent
              <select value={sourceId} onChange={(e) => setSourceId(e.target.value === '' ? '' : Number(e.target.value))} className="rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                <option value="">Seç…</option>
                {data?.agents.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </select>
              {data && data.agents.length === 0 && <span>Henüz kopyalanacak bir agent yok.</span>}
            </label>
          )}
        </form>

        {error && (
          <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
            {error}
          </p>
        )}

        {data && data.examples.length > 0 && (
          <section aria-labelledby="examples-title" className="flex flex-col items-center gap-2">
            <h2 id="examples-title" className="m-0 text-xs font-normal text-muted">
              Bir örnek dene
            </h2>
            {data.examples.slice(0, 3).map((example) => (
              <button
                key={example}
                type="button"
                onClick={() => {
                  setMode('blank')
                  setText(example)
                }}
                className="w-full rounded-lg bg-canvas px-3 py-2 text-center text-[13px] text-ink-2 hover:bg-raised"
              >
                {example}
              </button>
            ))}
          </section>
        )}

        {data && availableTemplates.length > 0 && <Templates templates={availableTemplates} all={allTemplates} onAll={() => setAllTemplates(true)} busy={busy} onPick={(key) => start({ mode: 'template', template_key: key }, '')} />}
      </div>
    </main>
  )
}

function Templates({ templates, all, onAll, busy, onPick }: { templates: BuildData['templates']; all: boolean; onAll: () => void; busy: boolean; onPick: (key: string) => void }) {
  const visible = all ? templates : templates.slice(0, VISIBLE_TEMPLATES)

  return (
    <section aria-labelledby="templates-title" className="flex flex-col gap-2">
      <div className="flex items-center text-xs">
        <h2 id="templates-title" className="m-0 flex-1 text-xs font-medium text-ink-2">
          Havacılık şablonları
        </h2>
        {!all && templates.length > VISIBLE_TEMPLATES && (
          <button type="button" onClick={onAll} className="text-muted hover:text-ink">
            {templates.length} şablona göz at
          </button>
        )}
      </div>
      <div className="grid gap-2 sm:grid-cols-3">
        {visible.map((t) => (
          <button key={t.key} type="button" disabled={busy} onClick={() => onPick(t.key)} className="flex flex-col gap-1 rounded-lg border border-line bg-surface px-3 py-2.5 text-left hover:border-line-strong hover:bg-canvas disabled:opacity-60">
            <span className="font-serif text-[15px] text-ink">{t.name}</span>
            <span className="text-[11px] text-muted">{t.systems.join(' + ')}</span>
            {t.trigger && <span className="font-mono text-[10px] text-faint">{t.trigger}</span>}
          </button>
        ))}
      </div>
    </section>
  )
}
