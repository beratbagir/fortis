import { GovernanceOverviewView } from '../governance/Overview'
import { href } from '../router'
import { UsagePage } from './UsagePage'

const TABS = [
  { key: 'overview', label: 'Genel bakış' },
  { key: 'usage', label: 'Kullanım' },
] as const

/** Yönetişim (#/governance): Genel bakış (admin) ve Kullanım. Politikalar ve Denetim kaydı A4.4'te. */
export function GovernancePage({ tab }: { tab: 'overview' | 'usage' }) {
  return (
    <div className="flex min-w-0 flex-1 flex-col">
      <nav aria-label="Yönetişim sekmeleri" className="flex gap-1 border-b border-line px-4 md:px-8">
        {TABS.map((t) => (
          <a
            key={t.key}
            href={href({ name: 'governance', tab: t.key })}
            aria-current={tab === t.key ? 'page' : undefined}
            className={`-mb-px border-b-2 px-2.5 py-2.5 text-[13px] no-underline ${tab === t.key ? 'border-ink font-medium text-ink' : 'border-transparent text-muted hover:text-ink'}`}
          >
            {t.label}
          </a>
        ))}
      </nav>
      {tab === 'overview' ? <GovernanceOverviewView /> : <UsagePage />}
    </div>
  )
}
