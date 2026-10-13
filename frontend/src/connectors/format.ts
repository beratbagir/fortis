import type { ConnectorCard, ConnectorScope } from '../api'
import { t } from '../i18n'

// Etiketler arayüz dilinde ve okunduğu anda üretilir (getter): dil çalışırken değişebilir.
const TONES: Record<ConnectorCard['status'], string> = {
  connected: 'bg-ok-soft text-ok',
  attention: 'bg-warn-soft text-warn',
  not_configured: 'bg-raised text-ink-2',
  disabled: 'bg-bad-soft text-bad',
}

export const CONNECTOR_STATUS = {} as Record<ConnectorCard['status'], [string, string]>
for (const status of Object.keys(TONES) as ConnectorCard['status'][]) {
  Object.defineProperty(CONNECTOR_STATUS, status, { enumerable: true, get: () => [t(`connectors.status.${status}`), TONES[status]] })
}

const SCOPES: ConnectorScope[] = ['read', 'write·approval', 'events']
const scopeKey = (scope: ConnectorScope) => (scope === 'write·approval' ? 'write_approval' : scope)
export const SCOPE_LABEL = {} as Record<ConnectorScope, string>
for (const scope of SCOPES) {
  Object.defineProperty(SCOPE_LABEL, scope, { enumerable: true, get: () => t(`connectors.scope.${scopeKey(scope)}`) })
}

export const CATEGORY_TITLE = {} as Record<ConnectorCard['category'], [string, string]>
for (const category of ['data', 'channel', 'setup'] as ConnectorCard['category'][]) {
  Object.defineProperty(CATEGORY_TITLE, category, { enumerable: true, get: () => [t(`connectors.category.${category}.title`), t(`connectors.category.${category}.note`)] })
}

/** "IT'ye iletmek için kopyala" metni: düz metin, gizli bilgi yok. */
export function setupText(c: { label: string; description: string | null; needs: string[] }): string {
  return [t('connectors.setup_text.title', { label: c.label }), c.description ?? '', '', ...c.needs.map((n) => `- ${n}`), '', t('connectors.setup_text.footer')].join('\n')
}
