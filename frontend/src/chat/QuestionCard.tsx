import { useState } from 'react'
import type { AskAnswer, AskQuestion } from '../api'
import { CloseIcon } from '../ui/icons'

interface Props {
  questions: AskQuestion[]
  disabled?: boolean
  onSubmit: (answer: AskAnswer) => void
}

/**
 * Claude'un çoktan seçmeli sorusu (ask_user). Çoklu seçimde onay kutusu, tekli seçimde radyo; "Başka bir şey…"
 * serbest metin. Birden fazla soruda sayfa sayfa ilerler; son sayfada Devam cevapları gönderir.
 */
export function QuestionCard({ questions, disabled = false, onSubmit }: Props) {
  const [page, setPage] = useState(0)
  const [selected, setSelected] = useState<string[][]>(() => questions.map(() => []))
  const [other, setOther] = useState<string[]>(() => questions.map(() => ''))

  const q = questions[page]
  const last = page === questions.length - 1
  const name = `ask-${page}`

  const toggle = (label: string) =>
    setSelected((all) =>
      all.map((list, i) => {
        if (i !== page) return list
        if (!q.multi_select) return [label]
        return list.includes(label) ? list.filter((l) => l !== label) : [...list, label]
      }),
    )

  const next = () => {
    if (!last) {
      setPage(page + 1)
      return
    }
    onSubmit({
      answers: questions.map((question, i) => ({ question: question.question, selected: selected[i], other: other[i].trim() === '' ? null : other[i].trim() })),
    })
  }

  const answered = selected[page].length > 0 || other[page].trim() !== ''

  return (
    <section aria-label="Fortis'in sorusu" className="overflow-hidden rounded-2xl border border-line bg-surface shadow-[0_8px_24px_var(--shadow)]">
      {q.context && (
        <div className="flex items-start gap-2 bg-canvas px-4 py-2.5 text-xs text-ink-2">
          <p className="m-0 flex-1">{q.context}</p>
          <button type="button" aria-label="Soruyu atla" disabled={disabled} onClick={() => onSubmit({ skipped: true })} className="text-muted hover:text-ink">
            <CloseIcon width={14} height={14} />
          </button>
        </div>
      )}
      <fieldset className="m-0 flex flex-col gap-1 border-0 px-4 pt-3 pb-2">
        <legend className="mb-2 p-0 font-serif text-[17px] text-ink">{q.question}</legend>
        {q.options.map((option) => {
          const checked = selected[page].includes(option.label)
          return (
            <label key={option.label} className={`flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] ${checked ? 'bg-raised' : 'hover:bg-canvas'}`}>
              <input type={q.multi_select ? 'checkbox' : 'radio'} name={name} checked={checked} onChange={() => toggle(option.label)} className="accent-[var(--brand)]" />
              <span className="flex-1 text-ink">{option.label}</span>
              {option.source && <span className="text-[11px] text-faint">{option.source}</span>}
            </label>
          )
        })}
        {q.allow_other && (
          <input
            type="text"
            aria-label="Başka bir şey"
            placeholder="Başka bir şey…"
            value={other[page]}
            onChange={(e) => setOther((all) => all.map((v, i) => (i === page ? e.target.value : v)))}
            className="mt-1 h-9 rounded-lg border border-transparent bg-transparent px-2.5 text-[13px] text-ink placeholder:text-faint focus:border-line"
          />
        )}
      </fieldset>
      <div className="flex items-center gap-2 border-t border-line px-4 py-2.5">
        {questions.length > 1 && (
          <span className="font-mono text-xs text-muted">
            {page + 1} / {questions.length}
          </span>
        )}
        <span className="flex-1" />
        <button type="button" disabled={disabled} onClick={() => onSubmit({ skipped: true })} className="h-8 rounded-lg px-3 text-[13px] text-ink-2 hover:bg-raised">
          Atla
        </button>
        <button type="button" disabled={disabled || !answered} onClick={next} className="h-8 rounded-lg bg-ink px-3.5 text-[13px] font-medium text-bg disabled:opacity-50">
          Devam
        </button>
      </div>
    </section>
  )
}
