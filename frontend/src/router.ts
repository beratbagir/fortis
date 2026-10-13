import { useEffect, useState } from 'react'

/** Henüz yapılmamış ekranlar: menüde görünür, "Yakında" sayfası açar. */
export const SOON_SCREENS = ['actions', 'connectors', 'artifacts', 'shared', 'memory', 'knowledge', 'skills'] as const
export type SoonScreen = (typeof SOON_SCREENS)[number]

export const AGENT_TABS = ['overview', 'chat', 'runs', 'config', 'evaluation', 'versions'] as const
export type AgentTab = (typeof AGENT_TABS)[number]

/**
 * Hash tabanlı yönlendirme: #/chat (Home), #/chat/12, #/chats, #/agents (Agent Kaydı), #/agents/5[/chat|runs[/12]|config|versions], #/build (yeni agent), #/build/agents/5 (Architect),
 * #/build/flows (akışlar), #/build/flows/12 (editör), #/runs, #/governance, #/inbox, #/inbox/7 …, #/profile, #/admin/users,
 * #/admin/departments, #/login?next=… . Eski #/usage → #/governance, #/flows/12 → #/build/flows/12.
 */
export type Route =
  | { name: 'chat'; conversationId: number | null }
  | { name: 'chats' }
  | { name: 'agents' }
  | { name: 'agent'; agentId: number; tab: AgentTab; runId: number | null }
  | { name: 'build' }
  | { name: 'architect'; agentId: number }
  | { name: 'flows' }
  | { name: 'editor'; flowId: number }
  | { name: 'runs'; executionId: string | null }
  | { name: 'governance' }
  | { name: 'inbox'; itemId: number | null }
  | { name: 'soon'; screen: SoonScreen }
  | { name: 'profile' }
  | { name: 'admin-users' }
  | { name: 'admin-departments' }
  | { name: 'login'; next: string | null }

const id = (part: string | undefined) => (part && /^\d+$/.test(part) ? Number(part) : null)

export function parseRoute(hash: string): Route {
  const [path, query = ''] = hash.replace(/^#\/?/, '').split('?')
  const parts = path.split('/').filter(Boolean)

  if ((SOON_SCREENS as readonly string[]).includes(parts[0])) {
    return { name: 'soon', screen: parts[0] as SoonScreen }
  }

  switch (parts[0]) {
    case 'chats':
      return { name: 'chats' }
    case 'agents': {
      const agentId = id(parts[1])
      if (agentId === null) return { name: 'agents' }
      const tab = (AGENT_TABS as readonly string[]).includes(parts[2]) ? (parts[2] as AgentTab) : 'overview'
      return { name: 'agent', agentId, tab, runId: tab === 'runs' ? id(parts[3]) : null }
    }
    case 'build':
      if (parts[1] === 'agents' && id(parts[2])) return { name: 'architect', agentId: id(parts[2])! }
      if (parts[1] === 'flows') return id(parts[2]) ? { name: 'editor', flowId: id(parts[2])! } : { name: 'flows' }
      return { name: 'build' }
    case 'flows':
      return id(parts[1]) ? { name: 'editor', flowId: id(parts[1])! } : { name: 'flows' }
    case 'runs':
      return { name: 'runs', executionId: parts[1] ?? null }
    case 'inbox':
      return { name: 'inbox', itemId: id(parts[1]) }
    case 'governance':
    case 'usage':
      return { name: 'governance' }
    case 'profile':
      return { name: 'profile' }
    case 'admin':
      return parts[1] === 'departments' ? { name: 'admin-departments' } : { name: 'admin-users' }
    case 'login':
      return { name: 'login', next: new URLSearchParams(query).get('next') }
    case 'chat':
      return { name: 'chat', conversationId: id(parts[1]) }
    default:
      return { name: 'chat', conversationId: null }
  }
}

export function href(route: Route): string {
  switch (route.name) {
    case 'chat':
      return route.conversationId ? `#/chat/${route.conversationId}` : '#/chat'
    case 'chats':
      return '#/chats'
    case 'agents':
      return '#/agents'
    case 'agent':
      return `#/agents/${route.agentId}${route.tab === 'overview' ? '' : `/${route.tab}`}${route.tab === 'runs' && route.runId ? `/${route.runId}` : ''}`
    case 'build':
      return '#/build'
    case 'architect':
      return `#/build/agents/${route.agentId}`
    case 'flows':
      return '#/build/flows'
    case 'editor':
      return `#/build/flows/${route.flowId}`
    case 'runs':
      return route.executionId ? `#/runs/${route.executionId}` : '#/runs'
    case 'governance':
      return '#/governance'
    case 'inbox':
      return route.itemId ? `#/inbox/${route.itemId}` : '#/inbox'
    case 'soon':
      return `#/${route.screen}`
    case 'profile':
      return '#/profile'
    case 'admin-users':
      return '#/admin/users'
    case 'admin-departments':
      return '#/admin/departments'
    case 'login':
      return route.next ? `#/login?next=${encodeURIComponent(route.next)}` : '#/login'
  }
}

export function navigate(route: Route) {
  window.location.hash = href(route)
}

/** Eski adresler yeni adlarına taşınır (yer imleri bozulmasın). */
function canonical(hash: string): string | null {
  if (/^#\/usage\b/.test(hash)) return '#/governance'
  if (/^#\/flows\b/.test(hash)) return hash.replace(/^#\/flows/, '#/build/flows')
  return null
}

export function useRoute(): Route {
  const [route, setRoute] = useState(() => parseRoute(window.location.hash))

  useEffect(() => {
    const onChange = () => {
      const moved = canonical(window.location.hash)
      if (moved) {
        window.history.replaceState(null, '', moved)
      }
      setRoute(parseRoute(window.location.hash))
    }
    onChange()
    window.addEventListener('hashchange', onChange)
    return () => window.removeEventListener('hashchange', onChange)
  }, [])

  return route
}
