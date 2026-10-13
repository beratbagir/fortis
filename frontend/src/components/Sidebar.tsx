import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type ComponentType, type SVGProps } from 'react'
import { api, inboxApi } from '../api'
import { useAuth } from '../auth/useAuth'
import { usageApi } from '../editor/api'
import { t } from '../i18n'
import { href, type Route } from '../router'
import { useTheme } from '../theme'
import { money } from '../ui/format'
import {
  CheckSquareIcon, ChevronIcon, ClockIcon, CloseIcon, GridIcon, HammerIcon, InboxIcon, KnowledgeIcon, LayersIcon, LogoutIcon, MenuIcon, MoonIcon,
  PlugIcon, PlusIcon, RunsIcon, SettingsIcon, SidebarIcon, StarIcon, SunIcon, UsageIcon, UserIcon, UsersIcon, ChatIcon,
} from '../ui/icons'
import { Avatar, Logo } from '../ui/Logo'

type Icon = ComponentType<SVGProps<SVGSVGElement>>

interface Item {
  key: string
  label: string
  icon: Icon
  route: Route
  soon?: boolean
  /** Okunmamış/bekleyen sayısı (ör. Inbox'ta aksiyon gerekenler). */
  badge?: number
}

const COLLAPSED_KEY = 'fortis.sidebar.collapsed'

/** Menüde hangi öğe seçili görünür. */
function section(route: Route): string {
  switch (route.name) {
    case 'editor':
    case 'flows':
    case 'architect':
      return 'build'
    case 'soon':
      return route.screen
    case 'chat':
      return 'chats'
    case 'agent':
      return 'agents'
    default:
      return route.name
  }
}

function mainItems(canBuild: boolean, inboxCount: number): Item[] {
  return [
    { key: 'chats', label: t('nav.chats'), icon: ChatIcon, route: { name: 'chats' } },
    { key: 'inbox', label: t('nav.inbox'), icon: InboxIcon, route: { name: 'inbox', itemId: null }, badge: inboxCount },
    { key: 'actions', label: t('nav.actions'), icon: CheckSquareIcon, route: { name: 'soon', screen: 'actions' }, soon: true },
    { key: 'agents', label: t('nav.agents'), icon: GridIcon, route: { name: 'agents' } },
    ...(canBuild ? [{ key: 'build', label: t('nav.build'), icon: HammerIcon, route: { name: 'build' } } as Item] : []),
    { key: 'connectors', label: t('nav.connectors'), icon: PlugIcon, route: { name: 'soon', screen: 'connectors' }, soon: true },
    { key: 'artifacts', label: t('nav.artifacts'), icon: LayersIcon, route: { name: 'soon', screen: 'artifacts' }, soon: true },
  ]
}

function workspaceItems(): Item[] {
  return [
    { key: 'shared', label: t('nav.shared'), icon: UsersIcon, route: { name: 'soon', screen: 'shared' }, soon: true },
    { key: 'governance', label: t('nav.governance'), icon: UsageIcon, route: { name: 'governance' } },
    { key: 'runs', label: t('nav.runs'), icon: RunsIcon, route: { name: 'runs', executionId: null } },
    { key: 'memory', label: t('nav.memory'), icon: ClockIcon, route: { name: 'soon', screen: 'memory' }, soon: true },
    { key: 'knowledge', label: t('nav.knowledge'), icon: KnowledgeIcon, route: { name: 'soon', screen: 'knowledge' }, soon: true },
    { key: 'skills', label: t('nav.skills'), icon: StarIcon, route: { name: 'soon', screen: 'skills' }, soon: true },
  ]
}

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1'
  } catch {
    return false
  }
}

/**
 * Ana menü. Geniş ekranda solda (daraltılabilir, tercih tarayıcıda saklanır); telefonda üst çubuk + çekmece.
 * Editörde tuvale yer açmak için kendiliğinden daralır.
 */
export function Sidebar({ route, compact = false }: { route: Route; compact?: boolean }) {
  const [collapsed, setCollapsed] = useState(readCollapsed)
  // Çekmece açıldığı sayfaya bağlı: sayfa değişince kendiliğinden kapanır.
  const [drawerFor, setDrawerFor] = useState<Route | null>(null)
  const drawerOpen = drawerFor === route
  const setDrawerOpen = (open: boolean) => setDrawerFor(open ? route : null)

  const toggle = () => {
    const next = !collapsed
    setCollapsed(next)
    try {
      localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0')
    } catch {
      // Saklanamazsa bu oturumda geçerli.
    }
  }

  useEffect(() => {
    if (!drawerOpen) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setDrawerFor(null)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [drawerOpen])

  return (
    <>
      {/* Telefon: üst çubuk */}
      <div className="flex shrink-0 items-center gap-2 border-b border-line bg-canvas px-3 py-2 md:hidden">
        <button type="button" aria-label={t('nav.open_menu')} aria-expanded={drawerOpen} onClick={() => setDrawerOpen(true)} className="flex size-9 items-center justify-center rounded-lg text-ink-2 hover:bg-raised">
          <MenuIcon />
        </button>
        <Logo size={18} />
        <span className="text-[15px] font-semibold tracking-tight">fortis</span>
      </div>

      {drawerOpen && <div className="fixed inset-0 z-40 bg-overlay md:hidden" onClick={() => setDrawerOpen(false)} aria-hidden />}

      <nav
        aria-label={t('nav.main')}
        className={`z-50 flex shrink-0 flex-col border-r border-line bg-canvas text-[13px] max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:w-[272px] max-md:shadow-[8px_0_32px_var(--shadow)] max-md:transition-transform ${
          drawerOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'
        } ${compact || collapsed ? 'md:w-[60px]' : 'md:w-[236px]'}`}
      >
        <SidebarBody route={route} narrow={compact || collapsed} canCollapse={!compact} collapsed={collapsed} onToggle={toggle} onCloseDrawer={() => setDrawerOpen(false)} />
      </nav>
    </>
  )
}

function SidebarBody({ route, narrow, canCollapse, collapsed, onToggle, onCloseDrawer }: { route: Route; narrow: boolean; canCollapse: boolean; collapsed: boolean; onToggle: () => void; onCloseDrawer: () => void }) {
  const { user } = useAuth()
  const active = section(route)
  const inboxCount = useQuery({ queryKey: ['inbox', 'count'], queryFn: inboxApi.count, refetchInterval: 60_000 })
  // Telefonda çekmece her zaman geniş; daraltma sadece md ve üstünde.
  const hideOnNarrow = narrow ? 'md:hidden' : ''

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-2.5 pt-3.5 pb-3">
      <div className={`mb-2 flex items-center gap-2 px-1.5 ${narrow ? 'md:flex-col md:px-0' : ''}`}>
        <Logo size={18} />
        <span className={`flex-1 text-[15px] font-semibold tracking-tight ${hideOnNarrow}`}>fortis</span>
        {canCollapse && (
          <button
            type="button"
            onClick={onToggle}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            aria-pressed={collapsed}
            className="flex size-7 items-center justify-center rounded-md text-muted hover:bg-raised hover:text-ink max-md:hidden"
          >
            <SidebarIcon />
          </button>
        )}
        <button type="button" onClick={onCloseDrawer} aria-label={t('nav.close_menu')} className="flex size-7 items-center justify-center rounded-md text-muted hover:bg-raised md:hidden">
          <CloseIcon />
        </button>
      </div>

      <a
        href={href({ name: 'chat', conversationId: null })}
        title={narrow ? t('nav.new_chat') : undefined}
        className={`mb-2 flex items-center gap-2.5 rounded-lg px-2 py-1.5 font-medium text-brand-fg no-underline hover:bg-raised ${narrow ? 'md:justify-center md:px-0' : ''}`}
      >
        <span className="flex size-5 items-center justify-center rounded-full bg-brand text-on-brand">
          <PlusIcon width={12} height={12} />
        </span>
        <span className={hideOnNarrow}>{t('nav.new_chat')}</span>
      </a>

      {mainItems(user?.abilities.build ?? false, inboxCount.data?.action ?? 0).map((item) => (
        <NavItem key={item.key} item={item} active={active === item.key} narrow={narrow} />
      ))}

      <SectionTitle narrow={narrow}>{t('nav.workspace')}</SectionTitle>
      {workspaceItems().map((item) => (
        <NavItem key={item.key} item={item} active={active === item.key} narrow={narrow} />
      ))}

      {!narrow && <Recents activeId={route.name === 'chat' ? route.conversationId : null} />}

      <div className="flex-1" />

      {!narrow && <CreditsBox />}
      <ThemeSwitch narrow={narrow} />
      <UserButton narrow={narrow} />
    </div>
  )
}

function SectionTitle({ narrow, children }: { narrow: boolean; children: string }) {
  return narrow ? <div className="my-2 border-t border-line max-md:hidden" /> : <p className="mt-4 mb-1 px-2 text-[11px] font-medium text-muted">{children}</p>
}

function NavItem({ item, active, narrow }: { item: Item; active: boolean; narrow: boolean }) {
  const Icon = item.icon

  return (
    <a
      href={href(item.route)}
      aria-current={active ? 'page' : undefined}
      title={narrow ? item.label : undefined}
      className={`flex items-center gap-2.5 rounded-lg px-2 py-1.5 no-underline ${active ? 'bg-raised font-medium text-ink' : 'text-ink-2 hover:bg-raised hover:text-ink'} ${
        narrow ? 'md:justify-center md:px-0 md:py-2' : ''
      }`}
    >
      <Icon className="shrink-0 text-muted" />
      <span className={`flex-1 truncate ${narrow ? 'md:sr-only' : ''}`}>{item.label}</span>
      {item.soon && <span className={`text-[10px] font-medium text-faint ${narrow ? 'md:hidden' : ''}`}>{t('nav.soon')}</span>}
      {!!item.badge && (
        <span aria-label={`${item.badge} bekleyen`} className={`min-w-5 rounded-full bg-brand px-1.5 text-center font-mono text-[10px] leading-[18px] text-on-brand ${narrow ? 'md:hidden' : ''}`}>
          {item.badge > 99 ? '99+' : item.badge}
        </span>
      )}
    </a>
  )
}

function Recents({ activeId }: { activeId: number | null }) {
  const conversations = useQuery({ queryKey: ['conversations', 'recent', activeId], queryFn: api.listConversations, staleTime: 15_000 })
  const recent = (conversations.data ?? []).slice(0, 5)

  return (
    <>
      <p className="mt-4 mb-1 px-2 text-[11px] font-medium text-muted">{t('nav.recents')}</p>
      {conversations.isSuccess && recent.length === 0 && <p className="px-2 py-1 text-xs text-faint">{t('nav.no_recents')}</p>}
      {recent.map((c) => (
        <a
          key={c.id}
          href={href({ name: 'chat', conversationId: c.id })}
          aria-current={c.id === activeId ? 'page' : undefined}
          className={`truncate rounded-lg px-2 py-1.5 no-underline ${c.id === activeId ? 'bg-raised text-ink' : 'text-ink-2 hover:bg-raised'}`}
        >
          {c.title ?? t('nav.new_chat')}
        </a>
      ))}
    </>
  )
}

/** Tasarımdaki "Credits left" kutusu. Kredi Faz A3'te; şimdilik bu ayın harcaması ve bütçe. */
function CreditsBox() {
  const summary = useQuery({ queryKey: ['usage', 'summary', 'current'], queryFn: () => usageApi.summary(), refetchInterval: 60_000 })
  const budget = summary.data?.budget
  const ratio = budget?.ratio ?? 0

  return (
    <a href={href({ name: 'governance' })} className="mb-2 flex flex-col gap-1.5 rounded-lg border border-line bg-surface p-2.5 text-ink no-underline max-md:hidden">
      <span className="flex items-baseline justify-between text-[11px] text-muted">
        {t('credits.title')}
        <span className="font-mono text-[13px] font-semibold text-ink">{summary.data ? money(summary.data.cost_usd) : '—'}</span>
      </span>
      {budget?.limit != null ? (
        <>
          <div className="h-1 rounded-full bg-line" aria-hidden>
            <div className={`h-full rounded-full ${ratio >= 1 ? 'bg-bad' : 'bg-ink'}`} style={{ width: `${Math.min(100, ratio * 100)}%` }} />
          </div>
          <span className="text-[11px] text-muted">{t('credits.of_budget', { limit: money(budget.limit, 0), pct: Math.round(ratio * 100) })}</span>
        </>
      ) : (
        summary.data && <span className="text-[11px] text-muted">{t('credits.no_limit')}</span>
      )}
    </a>
  )
}

function ThemeSwitch({ narrow }: { narrow: boolean }) {
  const { setTheme } = useAuth()
  const { resolved } = useTheme()

  if (narrow) {
    const next = resolved === 'dark' ? 'light' : 'dark'
    return (
      <button type="button" onClick={() => setTheme(next)} aria-label={`${t('theme.label')}: ${t(`theme.${next}`)}`} className="mb-1 flex h-8 items-center justify-center rounded-lg text-muted hover:bg-raised max-md:hidden">
        {resolved === 'dark' ? <SunIcon /> : <MoonIcon />}
      </button>
    )
  }

  return (
    <div role="group" aria-label={t('theme.label')} className="mb-2 grid grid-cols-2 gap-0.5 rounded-lg bg-raised p-0.5">
      {(['light', 'dark'] as const).map((value) => (
        <button
          key={value}
          type="button"
          aria-pressed={resolved === value}
          onClick={() => setTheme(value)}
          className={`flex h-7 items-center justify-center gap-1.5 rounded-md text-xs ${resolved === value ? 'bg-surface font-medium text-ink shadow-[0_1px_2px_var(--shadow)]' : 'text-muted hover:text-ink'}`}
        >
          {value === 'light' ? <SunIcon /> : <MoonIcon />}
          {t(`theme.${value}`)}
        </button>
      ))}
    </div>
  )
}

function UserButton({ narrow }: { narrow: boolean }) {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      window.removeEventListener('keydown', onKey)
    }
  }, [open])

  if (!user) return null

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={t('user_menu.open')}
        onClick={() => setOpen((o) => !o)}
        className={`flex w-full items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-left hover:bg-raised ${narrow ? 'md:justify-center md:px-0' : ''}`}
      >
        <Avatar name={user.name} color={user.avatar_color} />
        <span className={`flex min-w-0 flex-1 flex-col ${narrow ? 'md:hidden' : ''}`}>
          <span className="truncate text-[13px] font-medium text-ink">{user.name}</span>
          <span className="truncate text-[11px] text-muted">
            {t('app.org')} · {t(`role.${user.role}`)}
          </span>
        </span>
        <ChevronIcon open={open} className={`text-muted ${narrow ? 'md:hidden' : ''}`} />
      </button>
      {open && <UserMenu onClose={() => setOpen(false)} />}
    </div>
  )
}

function UserMenu({ onClose }: { onClose: () => void }) {
  const { user, logout, setTheme } = useAuth()
  const { preference } = useTheme()

  const link = (route: Route, label: string, Icon: Icon) => (
    <a role="menuitem" href={href(route)} onClick={onClose} className="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-ink-2 no-underline hover:bg-raised hover:text-ink">
      <Icon className="text-muted" />
      {label}
    </a>
  )

  return (
    <div role="menu" aria-label={t('user_menu.open')} className="absolute bottom-full left-0 z-50 mb-1.5 flex w-[220px] flex-col gap-0.5 rounded-xl border border-line bg-surface p-1.5 text-[13px] shadow-[0_12px_32px_var(--shadow)]">
      {link({ name: 'profile' }, t('user_menu.profile'), UserIcon)}
      <div className="flex items-center justify-between gap-2 px-2.5 py-1.5 text-ink-2">
        <span>{t('theme.label')}</span>
        <select aria-label={t('theme.label')} value={preference} onChange={(e) => setTheme(e.target.value as typeof preference)} className="rounded-md border border-line bg-surface px-1.5 py-0.5 text-xs text-ink">
          <option value="system">{t('theme.system')}</option>
          <option value="light">{t('theme.light')}</option>
          <option value="dark">{t('theme.dark')}</option>
        </select>
      </div>
      {user?.abilities.admin && (
        <>
          <div className="my-1 border-t border-line" />
          {link({ name: 'admin-users' }, t('user_menu.users'), UsersIcon)}
          {link({ name: 'admin-departments' }, t('user_menu.departments'), GridIcon)}
          {link({ name: 'governance' }, t('user_menu.settings'), SettingsIcon)}
        </>
      )}
      <div className="my-1 border-t border-line" />
      <button
        role="menuitem"
        type="button"
        onClick={() => {
          onClose()
          logout()
        }}
        className="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-ink-2 hover:bg-raised hover:text-ink"
      >
        <LogoutIcon className="text-muted" />
        {t('user_menu.logout')}
      </button>
    </div>
  )
}
