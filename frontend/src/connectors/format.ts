import type { ConnectorCard, ConnectorScope } from '../api'

export const CONNECTOR_STATUS: Record<ConnectorCard['status'], [string, string]> = {
  connected: ['Bağlı', 'bg-ok-soft text-ok'],
  attention: ['Dikkat', 'bg-warn-soft text-warn'],
  not_configured: ['Kurulum gerekli', 'bg-raised text-ink-2'],
  disabled: ['Kapalı', 'bg-bad-soft text-bad'],
}

export const SCOPE_LABEL: Record<ConnectorScope, string> = { read: 'okuma', 'write·approval': 'yazma · onaylı', events: 'olaylar' }

export const CATEGORY_TITLE: Record<ConnectorCard['category'], [string, string]> = {
  data: ['Havayolu sistemleri', 'Veri kaynakları; Fortis sadece okur.'],
  channel: ['Teslim kanalları', 'Agent gönderimleri; her gönderim onaydan geçer.'],
  setup: ['Kurulabilir', 'Bir agent ihtiyaç duyunca IT ile kurulur.'],
}

/** "IT'ye iletmek için kopyala" metni: düz metin, gizli bilgi yok. */
export function setupText(c: { label: string; description: string | null; needs: string[] }): string {
  return [`Fortis — ${c.label} bağlantısı için gerekenler`, c.description ?? '', '', ...c.needs.map((n) => `- ${n}`), '', 'Kimlik bilgileri Fortis sunucusunun .env dosyasına eklenir; arayüzden girilmez.'].join('\n')
}
