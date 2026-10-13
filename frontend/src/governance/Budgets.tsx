import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { governanceUsageApi, type BudgetInput, type BudgetRow, type BudgetsData } from '../api'
import { Dialog } from '../ui/Dialog'
import { usd } from './money'

interface Draft {
  limit: string
  warn: number
  hardStop: boolean
}

const toDraft = (b: BudgetRow | null): Draft => ({
  limit: b?.monthly_limit_usd ? String(b.monthly_limit_usd) : '',
  warn: b?.warn_at_percent ?? 80,
  hardStop: b?.hard_stop ?? false,
})
const same = (a: Draft, b: Draft) => a.limit.trim() === b.limit.trim() && a.warn === b.warn && a.hardStop === b.hardStop

/**
 * Bütçeler (admin): şirket ve departman aylık limiti, uyarı eşiği (admin'e Inbox uyarısı, ayda bir), "dolunca durdur"
 * (o kapsamdan yeni soru ve agent çalışması başlamaz; admin muaf), kredi değeri. Kaydetmeden önce değişiklikler onaylatılır.
 */
export function Budgets() {
  const budgets = useQuery({ queryKey: ['governance', 'budgets'], queryFn: governanceUsageApi.budgets })
  // Kayıttan sonra form sunucudaki değerlerle yeniden kurulur; sonuç mesajı bu yüzden burada tutulur.
  const [message, setMessage] = useState<Message | null>(null)
  if (!budgets.data) return null

  return <BudgetsForm key={budgets.dataUpdatedAt} data={budgets.data} message={message} setMessage={setMessage} />
}

type Message = { tone: 'ok' | 'bad'; text: string }

function BudgetsForm({ data, message, setMessage }: { data: BudgetsData; message: Message | null; setMessage: (m: Message | null) => void }) {
  const queryClient = useQueryClient()
  const initial = {
    org: toDraft(data.org),
    departments: Object.fromEntries(data.departments.map((d) => [d.id, toDraft(d.budget)])) as Record<number, Draft>,
  }
  const [org, setOrg] = useState<Draft>(initial.org)
  const [departments, setDepartments] = useState<Record<number, Draft>>(initial.departments)
  const [credit, setCredit] = useState(data.credit_usd_value ? String(data.credit_usd_value) : '')
  const [confirming, setConfirming] = useState(false)

  const changes: { label: string; input: BudgetInput }[] = [
    ...(same(org, initial.org)
      ? []
      : [
          {
            label: 'Şirket',
            input: {
              scope: 'org' as const,
              monthly_limit_usd: org.limit ? Number(org.limit) : null,
              warn_at_percent: org.warn,
              hard_stop: org.hardStop,
            },
          },
        ]),
    ...data.departments
      .filter((d) => !same(departments[d.id], initial.departments[d.id]))
      .map((d) => ({
        label: d.name,
        input: {
          scope: 'department' as const,
          scope_id: d.id,
          monthly_limit_usd: departments[d.id].limit ? Number(departments[d.id].limit) : null,
          warn_at_percent: departments[d.id].warn,
          hard_stop: departments[d.id].hardStop,
        },
      })),
  ]
  const creditChanged = credit.trim() !== (data.credit_usd_value ? String(data.credit_usd_value) : '')

  const save = async () => {
    setConfirming(false)
    setMessage(null)
    try {
      queryClient.setQueryData(
        ['governance', 'budgets'],
        await governanceUsageApi.saveBudgets(
          changes.map((c) => c.input),
          credit.trim() ? Number(credit) : null,
        ),
      )
      queryClient.invalidateQueries({ queryKey: ['governance'] })
      setMessage({ tone: 'ok', text: 'Bütçeler kaydedildi.' })
    } catch (e) {
      setMessage({ tone: 'bad', text: (e as Error).message })
    }
  }

  return (
    <section aria-labelledby="budgets-title" className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
      <div className="flex flex-wrap items-center gap-2">
        <h2 id="budgets-title" className="m-0 mr-auto text-[13px] font-medium">
          Bütçeler <span className="font-normal text-muted">· {data.month}</span>
        </h2>
        <button type="button" disabled={changes.length === 0 && !creditChanged} onClick={() => setConfirming(true)} className="rounded-lg bg-ink px-3 py-1.5 text-[13px] font-medium text-bg disabled:opacity-50">
          Kaydet
        </button>
      </div>
      <p className="m-0 text-xs text-muted">
        Uyarı eşiği aşılınca ve bütçe dolunca admin'e Inbox uyarısı gider (ayda bir). "Dolunca durdur" açıksa o kapsamdan yeni soru ve agent çalışması başlamaz; adminler muaf, devam eden çalışmalar bitirilir.
      </p>
      {message && (
        <p role={message.tone === 'bad' ? 'alert' : 'status'} className={`m-0 rounded-lg px-3 py-2 text-[13px] ${message.tone === 'bad' ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'}`}>
          {message.text}
        </p>
      )}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="text-left text-xs text-muted">
              <th className="py-1.5 pr-3 font-medium">Kapsam</th>
              <th className="px-3 py-1.5 text-right font-medium">Bu ay</th>
              <th className="px-3 py-1.5 font-medium">Aylık limit ($)</th>
              <th className="px-3 py-1.5 font-medium">Uyarı %</th>
              <th className="px-3 py-1.5 font-medium">Dolunca durdur</th>
            </tr>
          </thead>
          <tbody>
            <BudgetLine label="Şirket" spent={data.org.spent_usd} draft={org} onChange={setOrg} placeholder="limitsiz" />
            {data.departments.map((d) => (
              <BudgetLine key={d.id} label={d.name} spent={d.spent_usd} draft={departments[d.id]} onChange={(next) => setDepartments((all) => ({ ...all, [d.id]: next }))} placeholder="tanımsız" />
            ))}
          </tbody>
        </table>
      </div>
      <label className="flex flex-wrap items-center gap-2 text-xs text-muted">
        Kredi değeri ($ / kredi)
        <input
          aria-label="Kredi değeri"
          type="number"
          step="0.001"
          min="0"
          value={credit}
          onChange={(e) => setCredit(e.target.value)}
          placeholder="kapalı"
          className="w-28 rounded-md border border-line bg-surface px-2 py-1 text-[13px] text-ink"
        />
        <span>Tanımlıysa rakamlar kredi olarak da gösterilir; hesap her zaman dolardan.</span>
      </label>

      {confirming && (
        <Dialog title="Bütçe değişiklikleri kaydedilsin mi?" onClose={() => setConfirming(false)}>
          <ul className="m-0 mb-3 list-disc pl-5 text-sm text-ink-2">
            {changes.map((c) => (
              <li key={c.label}>
                {c.label}: {c.input.monthly_limit_usd ? `${usd(c.input.monthly_limit_usd)} / ay, uyarı %${c.input.warn_at_percent}, ${c.input.hard_stop ? 'dolunca durdur' : 'durdurmaz'}` : 'bütçe kaldırılacak'}
              </li>
            ))}
            {creditChanged && <li>Kredi değeri: {credit.trim() ? `$${credit}` : 'kapalı'}</li>}
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
    </section>
  )
}

function BudgetLine({ label, spent, draft, onChange, placeholder }: { label: string; spent: number; draft: Draft; onChange: (d: Draft) => void; placeholder: string }) {
  const limit = Number(draft.limit)
  const ratio = limit > 0 ? spent / limit : null

  return (
    <tr className="border-t border-line">
      <td className="py-2 pr-3">{label}</td>
      <td className="px-3 py-2 text-right font-mono text-xs">
        {usd(spent)}
        {ratio !== null && <span className={`ml-1 ${ratio >= 1 ? 'text-bad' : ratio * 100 >= draft.warn ? 'text-warn' : 'text-muted'}`}>%{Math.round(ratio * 100)}</span>}
      </td>
      <td className="px-3 py-2">
        <input
          aria-label={`${label} aylık limit`}
          type="number"
          min="0"
          step="1"
          value={draft.limit}
          placeholder={placeholder}
          onChange={(e) => onChange({ ...draft, limit: e.target.value })}
          className="w-28 rounded-md border border-line bg-surface px-2 py-1 text-[13px]"
        />
      </td>
      <td className="px-3 py-2">
        <input
          aria-label={`${label} uyarı yüzdesi`}
          type="number"
          min="1"
          max="100"
          value={draft.warn}
          disabled={!draft.limit}
          onChange={(e) => onChange({ ...draft, warn: Number(e.target.value) })}
          className="w-16 rounded-md border border-line bg-surface px-2 py-1 text-[13px] disabled:opacity-50"
        />
      </td>
      <td className="px-3 py-2">
        <input aria-label={`${label} dolunca durdur`} type="checkbox" checked={draft.hardStop} disabled={!draft.limit} onChange={(e) => onChange({ ...draft, hardStop: e.target.checked })} className="accent-brand" />
      </td>
    </tr>
  )
}
