import { flowsApi, type FeatureRequest } from '../api'

const REQUEST_STATUS: Record<FeatureRequest['status'], string> = {
  open: 'Açık',
  planned: 'Planlandı',
  done: 'Yapıldı',
  dismissed: 'Kapatıldı',
}

const dateFormat = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'short' })
const formatDate = (iso: string | null) => (iso ? dateFormat.format(new Date(iso)) : '—')

interface RequestsProps {
  data: Awaited<ReturnType<typeof flowsApi.featureRequests>>
  onChanged: (request: FeatureRequest) => void
  onOpenConversation: (id: number) => void
}

export function FeatureRequests({ data, onChanged, onOpenConversation }: RequestsProps) {
  const setStatus = async (id: number, status: FeatureRequest['status']) => onChanged((await flowsApi.setFeatureRequestStatus(id, status)).data)

  return (
    <div className="mt-4 space-y-4">
      <p className="text-sm text-muted">Kullanıcıların istediği ama katalogda olmadığı için yapılamayan şeyler. Katalog genişletme kararları için.</p>

      {data.top_capabilities.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {data.top_capabilities.map((c) => (
            <span key={c.missing_capability} className="rounded-full bg-ink px-2.5 py-1 text-xs text-bg">
              {c.missing_capability} · {c.count}
            </span>
          ))}
        </div>
      )}

      {data.data.length === 0 && <p className="text-sm text-muted">Henüz kayıt yok.</p>}

      <ul className="space-y-2">
        {data.data.map((r) => (
          <li key={r.id} className="rounded-xl border border-line bg-surface px-4 py-3 text-sm">
            <div className="flex items-start gap-3">
              <div className="flex-1">
                <div>{r.request}</div>
                <div className="mt-0.5 text-xs text-muted">
                  Eksik: <span className="font-medium text-ink-2">{r.missing_capability}</span>
                  {r.reason && ` · ${r.reason}`} · {formatDate(r.created_at)}
                </div>
              </div>
              <select
                value={r.status}
                onChange={(e) => setStatus(r.id, e.target.value as FeatureRequest['status'])}
                className="rounded-md border border-line-strong px-2 py-1 text-xs"
              >
                {Object.entries(REQUEST_STATUS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
            {r.conversation && (
              <button type="button" onClick={() => onOpenConversation(r.conversation!.id)} className="mt-1 text-xs text-ink-2 hover:text-ink">
                Sohbete git
              </button>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
