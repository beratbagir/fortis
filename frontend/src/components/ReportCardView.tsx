import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { reportsApi, type ReportCard } from '../api'

const FORMAT_TONE: Record<ReportCard['format'], { bg: string; fg: string }> = {
  xlsx: { bg: 'var(--tone-green-bg)', fg: 'var(--tone-green-fg)' },
  csv: { bg: 'var(--tone-slate-bg)', fg: 'var(--tone-slate-fg)' },
  pdf: { bg: 'var(--tone-red-bg)', fg: 'var(--tone-red-fg)' },
}

function size(bytes: number | null): string {
  if (bytes === null) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} MB`
}

/**
 * Sohbetteki rapor kartı: Hazırlanıyor (3 sn'de bir yenilenir), Hazır (İndir), Hata (Tekrar dene),
 * Süresi doldu (Yeniden oluştur). İndir önce imzalı, kısa süreli bağlantıyı alır, sonra indirmeyi başlatır.
 */
export function ReportCardView({
  card,
  conversationId,
  open = (url) => window.location.assign(url),
}: {
  card: ReportCard
  conversationId: number | null
  /** İndirmeyi başlatır (testte değiştirilir). */
  open?: (url: string) => void
}) {
  const queryClient = useQueryClient()
  const live = useQuery({
    queryKey: ['report', card.id],
    queryFn: () => reportsApi.get(card.id),
    initialData: card,
    refetchInterval: (query) => (query.state.data?.status === 'queued' ? 3_000 : false),
  })
  const report = { ...card, ...live.data }
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const tone = FORMAT_TONE[report.format] ?? FORMAT_TONE.csv

  const download = async () => {
    if (conversationId === null) return
    setBusy(true)
    setError(null)
    try {
      const { url } = await reportsApi.downloadLink(report.id, conversationId)
      open(url)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const regenerate = async () => {
    setBusy(true)
    setError(null)
    try {
      queryClient.setQueryData(['report', card.id], await reportsApi.regenerate(report.id))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const details =
    report.status === 'ready'
      ? [report.row_count !== null && `${report.row_count.toLocaleString('tr-TR')} satır`, size(report.file_size), `${report.retention_days} gün saklanır`].filter(Boolean).join(' · ')
      : report.status === 'queued'
        ? `Hazırlanıyor${report.row_count !== null ? ` · ${report.row_count.toLocaleString('tr-TR')} satır` : ''}`
        : report.status === 'expired'
          ? 'Bu raporun süresi doldu.'
          : (report.error ?? 'Rapor bulunamadı.')

  return (
    <div className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 shadow-[0_2px_10px_var(--shadow)]" role="group" aria-label={`Rapor: ${report.title}`}>
      <span className="flex h-11 w-12 shrink-0 items-center justify-center rounded-xl text-xs font-extrabold uppercase" style={{ background: tone.bg, color: tone.fg }}>
        {report.format}
      </span>
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="truncate text-[15px] font-extrabold">{report.title}</span>
        <span className={`text-[13px] ${report.status === 'failed' || report.status === 'missing' ? 'text-bad' : 'text-muted'}`} aria-live="polite">
          {report.status === 'queued' && <span className="mr-1.5 inline-block size-3 animate-spin rounded-full border-2 border-line border-t-brand align-[-1px]" aria-hidden />}
          {details}
        </span>
        {error && (
          <span role="alert" className="text-xs text-bad">
            {error}
          </span>
        )}
      </div>
      {report.status === 'ready' && (
        <button type="button" disabled={busy || conversationId === null} onClick={download} className="h-10 shrink-0 rounded-xl bg-brand px-4 text-sm font-bold text-on-brand disabled:opacity-60">
          {busy ? 'Hazırlanıyor…' : 'İndir'}
        </button>
      )}
      {(report.status === 'failed' || report.status === 'expired') && (
        <button type="button" disabled={busy} onClick={regenerate} className="h-10 shrink-0 rounded-xl border border-line bg-surface px-4 text-sm font-bold disabled:opacity-60">
          {busy ? 'Oluşturuluyor…' : report.status === 'failed' ? 'Tekrar dene' : 'Yeniden oluştur'}
        </button>
      )}
    </div>
  )
}
