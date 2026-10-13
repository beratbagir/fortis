import { lazy, Suspense, useEffect, type ReactNode } from 'react'
import { AuthProvider } from './auth/AuthContext'
import { useAuth } from './auth/useAuth'
import { Sidebar } from './components/Sidebar'
import { t } from './i18n'
import { AgentPage } from './pages/AgentPage'
import { AgentsPage } from './pages/AgentsPage'
import { BuildPage } from './pages/BuildPage'
import { DepartmentsPage } from './pages/admin/DepartmentsPage'
import { UsersPage } from './pages/admin/UsersPage'
import { ChatPage } from './pages/ChatPage'
import { ChatsPage } from './pages/ChatsPage'
import { ComingSoonPage } from './pages/ComingSoonPage'
import { FlowsListPage } from './pages/FlowsListPage'
import { ConnectorsPage } from './pages/ConnectorsPage'
import { InboxPage } from './pages/InboxPage'
import { LoginPage } from './pages/LoginPage'
import { PasswordForm, ProfilePage } from './pages/ProfilePage'
import { href, useRoute, type Route } from './router'
import { Logo } from './ui/Logo'

// Tuval ve Monaco büyük; sadece editör açılınca yüklenir.
const FlowEditorPage = lazy(() => import('./pages/FlowEditorPage'))
const ArchitectPage = lazy(() => import('./pages/ArchitectPage').then((m) => ({ default: m.ArchitectPage })))
const RunsPage = lazy(() => import('./pages/RunsPage').then((m) => ({ default: m.RunsPage })))
const UsagePage = lazy(() => import('./pages/UsagePage').then((m) => ({ default: m.UsagePage })))

export default function App() {
  return (
    <AuthProvider>
      <Shell />
    </AuthProvider>
  )
}

/** Girişten sonra dönülecek adres: #/login?next=… ; yoksa sohbet. */
function afterLogin(route: Route): string {
  const next = route.name === 'login' ? route.next : null
  return next && next.startsWith('#/') && !next.startsWith('#/login') ? next : '#/chat'
}

function Shell() {
  const route = useRoute()
  const { user } = useAuth()

  // Girişsiz her rota girişe gider; girişten sonra istenen sayfaya dönülür.
  useEffect(() => {
    if (!user && route.name !== 'login' && !window.location.hash.startsWith('#/login')) {
      window.location.replace(href({ name: 'login', next: window.location.hash || null }))
    }
    if (user && route.name === 'login') {
      window.location.replace(afterLogin(route))
    }
  }, [user, route])

  if (!user) {
    const error = new URLSearchParams(window.location.hash.split('?')[1] ?? '').get('error')
    return route.name === 'login' ? <LoginPage error={error} /> : null
  }

  if (user.must_change_password) {
    return (
      <main className="flex min-h-full items-center justify-center bg-canvas px-4 py-10">
        <div className="flex w-full max-w-[420px] flex-col gap-5 rounded-2xl border border-line bg-surface p-7">
          <Logo size={28} />
          <PasswordForm required />
        </div>
      </main>
    )
  }

  return (
    <div className="flex h-full flex-col md:flex-row">
      <Sidebar route={route} compact={route.name === 'editor' || route.name === 'architect'} />

      <div className="flex min-h-0 min-w-0 flex-1">
        <Suspense fallback={<p className="p-8 text-muted">{t('app.loading')}</p>}>
          <Page route={route} />
        </Suspense>
      </div>
    </div>
  )
}

function Page({ route }: { route: Route }) {
  const { user } = useAuth()
  const canBuild = user?.abilities.build ?? false
  const isAdmin = user?.abilities.admin ?? false

  switch (route.name) {
    case 'chat':
      return <ChatPage conversationId={route.conversationId} />
    case 'chats':
      return <ChatsPage />
    case 'agents':
      return <AgentsPage />
    case 'agent':
      return <AgentPage key={route.agentId} agentId={route.agentId} tab={route.tab} runId={route.runId} />
    case 'build':
      return canBuild ? <BuildPage /> : <Forbidden />
    case 'architect':
      return canBuild ? <ArchitectPage key={route.agentId} agentId={route.agentId} /> : <Forbidden />
    case 'flows':
      return canBuild ? <FlowsListPage /> : <Forbidden />
    case 'editor':
      return canBuild ? <FlowEditorPage key={route.flowId} flowId={route.flowId} /> : <Forbidden />
    case 'runs':
      return <RunsPage executionId={route.executionId} />
    case 'governance':
      return <UsagePage />
    case 'inbox':
      return <InboxPage itemId={route.itemId} />
    case 'connectors':
      return <ConnectorsPage key={route.key ?? 'list'} connectorKey={route.key} />
    case 'soon':
      return <ComingSoonPage screen={route.screen} />
    case 'profile':
      return <ProfilePage />
    case 'admin-users':
      return isAdmin ? <UsersPage /> : <Forbidden />
    case 'admin-departments':
      return isAdmin ? <DepartmentsPage /> : <Forbidden />
    case 'login':
      return null
  }
}

function Forbidden(): ReactNode {
  return (
    <main className="flex flex-1 items-center justify-center p-8">
      <p role="alert" className="m-0 text-sm text-muted">
        {t('error.forbidden')}
      </p>
    </main>
  )
}
