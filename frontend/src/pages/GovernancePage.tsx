import { useAuth } from '../auth/useAuth'
import { GovernanceAuditView } from '../governance/AuditLog'
import { GovernanceOverviewView } from '../governance/Overview'
import { GovernancePoliciesView } from '../governance/Policies'
import { GovernanceUsageView } from '../governance/Usage'
import { t } from '../i18n'
import { href, type GovernanceTab } from '../router'
import { UsagePage } from './UsagePage'

const TABS = ['overview', 'usage', 'policies', 'audit'] as const

/** Yönetişim (#/governance): Genel bakış, Kullanım, Politikalar, Denetim kaydı (admin). Admin olmayan sadece kendi Kullanım ekranını görür. */
export function GovernancePage({ tab }: { tab: GovernanceTab }) {
  const isAdmin = useAuth().user?.abilities.admin ?? false

  return (
    <div className="flex min-w-0 flex-1 flex-col">
      {isAdmin && (
        <nav aria-label={t('governance.tabs.label')} className="flex gap-1 border-b border-line px-4 md:px-8">
          {TABS.map((key) => (
            <a
              key={key}
              href={href({ name: 'governance', tab: key })}
              aria-current={tab === key ? 'page' : undefined}
              className={`-mb-px border-b-2 px-2.5 py-2.5 text-[13px] no-underline ${tab === key ? 'border-ink font-medium text-ink' : 'border-transparent text-muted hover:text-ink'}`}
            >
              {t(`governance.tabs.${key}`)}
            </a>
          ))}
        </nav>
      )}
      {!isAdmin ? <UsagePage /> : tab === 'usage' ? <GovernanceUsageView /> : tab === 'policies' ? <GovernancePoliciesView /> : tab === 'audit' ? <GovernanceAuditView /> : <GovernanceOverviewView />}
    </div>
  )
}
