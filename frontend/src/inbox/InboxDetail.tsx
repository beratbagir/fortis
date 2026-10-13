import { useState } from 'react'
import type { AskAnswer, DraftPatch, InboxDetail, InboxItemDetail } from '../api'
import { AnswerMarkdown } from '../chat/AnswerParts'
import { QuestionCard } from '../chat/QuestionCard'
import { href } from '../router'
import { LockIcon } from '../ui/icons'
import { TYPE_LABEL, TYPE_TONE, parseRecipients, recipientAllowed, time } from './inboxUtils'

type ActionDetail = Extract<InboxDetail, { kind: 'action' }>

const ACTION_TITLE: Record<string, string> = {
  send_email: 'E-posta gönderimi',
  post_teams: 'Teams mesajı',
  deliver_report: 'Rapor teslimi',
}

const ACTION_STATUS: Record<ActionDetail['action']['status'], string> = {
  pending: 'Onay bekliyor',
  approved: 'Onaylandı',
  executed: 'Gönderildi',
  rejected: 'Reddedildi',
  failed: 'Gönderilemedi',
  preview: 'Test çalıştırması · gönderilmez',
}

interface Props {
  item: InboxItemDetail
  busy: boolean
  error: string | null
  onApprove: () => void
  onReject: (reason: string | null) => void
  onAnswer: (answer: AskAnswer) => void
  onDraft: (patch: DraftPatch) => Promise<unknown>
  onDone: () => void
}

/** Inbox öğesinin detayı: onay (önizleme + ne olur + karar), agent sorusu, akış onayı veya bilgi. */
export function InboxDetailView(props: Props) {
  const { item } = props
  const detail = Array.isArray(item.detail) ? null : item.detail

  return (
    <article className="flex flex-col gap-4">
      <header className="flex flex-col gap-1.5">
        <span className="flex flex-wrap items-center gap-2 text-xs text-muted">
          <span className={`rounded px-1.5 py-px text-[10px] font-medium ${TYPE_TONE[item.type]}`}>{TYPE_LABEL[item.type]}</span>
          {item.source_label && <span>{item.source_label}</span>}
          <span className="font-mono">{time(item.created_at)}</span>
          {item.status === 'done' && <span className="rounded bg-ok-soft px-1.5 py-px text-[10px] text-ok">Tamamlandı</span>}
        </span>
        <h1 className="m-0 font-serif text-[26px] font-normal tracking-tight">{item.title}</h1>
        {item.body && <p className="m-0 text-sm text-ink-2">{item.body}</p>}
      </header>

      {props.error && (
        <p role="alert" className="m-0 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
          {props.error}
        </p>
      )}

      {detail?.kind === 'action' && <ActionApproval detail={detail} {...props} />}
      {detail?.kind === 'run' && <RunQuestion detail={detail} {...props} />}
      {detail?.kind === 'execution' && <ExecutionApproval detail={detail} {...props} />}
      {!detail && item.status !== 'done' && (
        <div>
          <button type="button" onClick={props.onDone} className="rounded-lg border border-line px-3 py-1.5 text-sm hover:bg-raised">
            Tamamlandı olarak işaretle
          </button>
        </div>
      )}
    </article>
  )
}

function WhatHappens({ steps }: { steps: string[] }) {
  return (
    <section aria-labelledby="what-happens" className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4">
      <h2 id="what-happens" className="m-0 text-[13px] font-medium">
        Onaylarsan ne olur
      </h2>
      <ol className="m-0 flex list-decimal flex-col gap-1.5 pl-4 text-[13px] text-ink-2">
        {steps.map((s, i) => (
          <li key={i}>{s}</li>
        ))}
      </ol>
    </section>
  )
}

function RejectForm({ busy, onReject }: { busy: boolean; onReject: (reason: string | null) => void }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')

  if (!open) {
    return (
      <button type="button" disabled={busy} onClick={() => setOpen(true)} className="rounded-lg border border-line px-3 py-2 text-sm text-bad hover:bg-bad-soft disabled:opacity-60">
        Reddet
      </button>
    )
  }

  return (
    <form
      className="flex flex-col gap-2"
      onSubmit={(e) => {
        e.preventDefault()
        onReject(reason.trim() === '' ? null : reason.trim())
      }}
    >
      <label className="flex flex-col gap-1 text-xs text-muted">
        Ret gerekçesi (agent bir sonraki çalışmada dikkate alır)
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} maxLength={1000} className="rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink" />
      </label>
      <span className="flex gap-2">
        <button type="submit" disabled={busy} className="rounded-lg bg-bad px-3 py-1.5 text-sm font-medium text-on-brand disabled:opacity-60">
          Reddet
        </button>
        <button type="button" onClick={() => setOpen(false)} className="rounded-lg border border-line px-3 py-1.5 text-sm hover:bg-raised">
          Vazgeç
        </button>
      </span>
    </form>
  )
}

function ActionApproval({ detail, busy, onApprove, onReject, onDraft }: Props & { detail: ActionDetail }) {
  const { action, run } = detail
  const p = action.payload
  const [editing, setEditing] = useState(false)
  const pending = action.status === 'pending'
  const body = p.body_markdown ?? p.text_markdown ?? p.message_markdown ?? ''

  return (
    <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_300px]">
      <div className="flex flex-col gap-3">
        <span className="flex flex-wrap items-center gap-2 text-xs text-muted">
          <span className="font-medium text-ink-2">{ACTION_TITLE[action.type] ?? action.type}</span>
          <span>· {ACTION_STATUS[action.status]}</span>
          {run.agent.status === 'pilot' && <span className="rounded bg-warn-soft px-1.5 py-px text-[10px] text-warn">Pilot</span>}
        </span>
        {action.reason && <p className="m-0 text-sm text-ink-2">{action.reason}</p>}

        {editing ? (
          <DraftEditor detail={detail} busy={busy} onCancel={() => setEditing(false)} onSave={(patch) => onDraft(patch).then(() => setEditing(false))} />
        ) : (
          <section aria-label="Önizleme" className="overflow-hidden rounded-xl border border-line bg-surface">
            <dl className="m-0 grid grid-cols-[80px_minmax(0,1fr)] gap-x-3 gap-y-1.5 border-b border-line px-4 py-3 text-[13px]">
              {p.to && (
                <>
                  <dt className="text-muted">Alıcı</dt>
                  <dd className="m-0 break-all">{p.to.join(', ')}</dd>
                </>
              )}
              {p.cc && p.cc.length > 0 && (
                <>
                  <dt className="text-muted">Kopya</dt>
                  <dd className="m-0 break-all">{p.cc.join(', ')}</dd>
                </>
              )}
              {p.channel && (
                <>
                  <dt className="text-muted">Kanal</dt>
                  <dd className="m-0">#{p.channel}</dd>
                </>
              )}
              {p.subject && (
                <>
                  <dt className="text-muted">Konu</dt>
                  <dd className="m-0 font-medium">{p.subject}</dd>
                </>
              )}
            </dl>
            {p.intended && (
              <p className="m-0 border-b border-line bg-warn-soft px-4 py-2 text-xs text-ink-2">
                Pilotta gerçek alıcıya gitmez, sana gelir. Canlıda gidecek yer: {[...(p.intended.to ?? []), ...(p.intended.channel ? [`#${p.intended.channel}`] : [])].join(', ')}
              </p>
            )}
            <div className="prose-chat px-4 py-3 text-sm">
              <AnswerMarkdown text={body} />
            </div>
            {p.attach_report_id && <p className="m-0 border-t border-line px-4 py-2 text-xs text-muted">Ek: rapor dosyası</p>}
          </section>
        )}
      </div>

      <aside className="flex flex-col gap-3">
        <WhatHappens steps={detail.what_happens} />
        {pending && detail.can_decide && !editing && (
          <div className="flex flex-col gap-2">
            <button type="button" disabled={busy} onClick={onApprove} className="rounded-lg bg-brand px-3 py-2 text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
              {action.type === 'send_email' || action.type === 'deliver_report' ? 'Onayla ve gönder' : 'Onayla'}
            </button>
            <button type="button" disabled={busy} onClick={() => setEditing(true)} className="rounded-lg border border-line px-3 py-2 text-sm hover:bg-raised disabled:opacity-60">
              Taslağı düzenle
            </button>
            {run.conversation_id && (
              <a href={href({ name: 'chat', conversationId: run.conversation_id })} className="rounded-lg border border-line px-3 py-2 text-center text-sm text-ink no-underline hover:bg-raised">
                Agent'a sor
              </a>
            )}
            <RejectForm busy={busy} onReject={onReject} />
          </div>
        )}
        {pending && !detail.can_decide && <p className="m-0 text-xs text-muted">Bu aksiyonu onaylama yetkin yok.</p>}
        <p className="m-0 flex items-center gap-1.5 text-[11px] text-muted">
          <LockIcon /> {run.agent.name} · onay olmadan hiçbir şey gönderilmez
        </p>
      </aside>
    </div>
  )
}

function DraftEditor({ detail, busy, onCancel, onSave }: { detail: ActionDetail; busy: boolean; onCancel: () => void; onSave: (patch: DraftPatch) => void }) {
  const p = detail.action.payload
  const bodyKey = p.body_markdown !== undefined ? 'body_markdown' : p.text_markdown !== undefined ? 'text_markdown' : 'message_markdown'
  const [to, setTo] = useState((p.to ?? []).join(', '))
  const [subject, setSubject] = useState(p.subject ?? '')
  const [body, setBody] = useState(p[bodyKey] ?? '')

  const recipients = parseRecipients(to)
  const invalid = recipients.filter((r) => !recipientAllowed(r, detail.allowed_recipients, detail.recipient_domains))
  const canEditTo = p.to !== undefined && detail.editable_recipients

  return (
    <form
      aria-label="Taslağı düzenle"
      className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4"
      onSubmit={(e) => {
        e.preventDefault()
        if (invalid.length > 0 || (canEditTo && recipients.length === 0)) return
        onSave({ ...(canEditTo ? { to: recipients } : {}), ...(p.subject !== undefined ? { subject } : {}), [bodyKey]: body })
      }}
    >
      {p.to !== undefined && (
        <label className="flex flex-col gap-1 text-xs text-muted">
          Alıcı
          <input aria-label="Alıcı" value={to} disabled={!canEditTo} onChange={(e) => setTo(e.target.value)} aria-invalid={invalid.length > 0} className="rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink disabled:opacity-60" />
          {!canEditTo && <span>Pilot agent sadece sana gönderir; alıcı değiştirilemez.</span>}
          {canEditTo && (
            <span className={invalid.length > 0 ? 'text-bad' : ''}>
              {invalid.length > 0
                ? `İzinli olmayan alıcı: ${invalid.join(', ')}`
                : detail.allowed_recipients.length > 0
                  ? `İzinli alıcılar: ${detail.allowed_recipients.join(', ')}`
                  : `Sadece ${detail.recipient_domains.map((d) => `@${d}`).join(', ')} adresleri`}
            </span>
          )}
        </label>
      )}
      {p.subject !== undefined && (
        <label className="flex flex-col gap-1 text-xs text-muted">
          Konu
          <input aria-label="Konu" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={300} className="rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink" />
        </label>
      )}
      <label className="flex flex-col gap-1 text-xs text-muted">
        Metin
        <textarea aria-label="Metin" value={body} onChange={(e) => setBody(e.target.value)} rows={12} className="rounded-lg border border-line bg-surface px-2.5 py-2 font-mono text-[13px] text-ink" />
      </label>
      <span className="flex gap-2">
        <button type="submit" disabled={busy || invalid.length > 0} className="rounded-lg bg-brand px-3 py-1.5 text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
          Kaydet
        </button>
        <button type="button" onClick={onCancel} className="rounded-lg border border-line px-3 py-1.5 text-sm hover:bg-raised">
          Vazgeç
        </button>
      </span>
    </form>
  )
}

function RunQuestion({ detail, busy, onAnswer }: Props & { detail: Extract<InboxDetail, { kind: 'run' }> }) {
  return (
    <div className="flex max-w-2xl flex-col gap-3">
      {detail.questions ? (
        <QuestionCard questions={detail.questions} disabled={busy} onSubmit={onAnswer} />
      ) : (
        <p className="m-0 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink-2">{detail.run.error ?? detail.run.summary ?? 'Bu çalışma için bekleyen bir soru yok.'}</p>
      )}
      {detail.run.conversation_id && (
        <a href={href({ name: 'chat', conversationId: detail.run.conversation_id })} className="text-xs text-muted hover:text-ink">
          Çalışmanın sohbetini aç
        </a>
      )}
    </div>
  )
}

function ExecutionApproval({ detail, busy, onApprove, onReject }: Props & { detail: Extract<InboxDetail, { kind: 'execution' }> }) {
  const paused = detail.execution.state === 'PAUSED'

  return (
    <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_300px]">
      <section className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4 text-sm">
        <span className="font-medium">{detail.execution.flow ?? 'Akış'}</span>
        <span className="text-muted">Durum: {detail.execution.state}</span>
        <a href={href({ name: 'runs', executionId: detail.execution.id })} className="text-xs text-muted hover:text-ink">
          Çalışmanın ayrıntıları
        </a>
      </section>
      <aside className="flex flex-col gap-3">
        <WhatHappens steps={detail.what_happens} />
        {paused && detail.can_decide && (
          <div className="flex flex-col gap-2">
            <button type="button" disabled={busy} onClick={onApprove} className="rounded-lg bg-brand px-3 py-2 text-sm font-medium text-on-brand hover:bg-brand-hover disabled:opacity-60">
              Onayla ve devam et
            </button>
            <RejectForm busy={busy} onReject={onReject} />
          </div>
        )}
      </aside>
    </div>
  )
}
