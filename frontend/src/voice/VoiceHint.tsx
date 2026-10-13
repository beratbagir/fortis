import { useQuery } from '@tanstack/react-query'
import { voiceApi } from './api'
import { isRecordingSupported } from './useVoiceRecorder'
import { setAutoSend, useAutoSend } from './settings'

/** Kutunun üstündeki "Sesten çevrildi" etiketi ve otomatik gönder geri sayımı. */
export function VoiceHint({ fromVoice, countdown }: { fromVoice: boolean; countdown: number | null }) {
  if (!fromVoice) return null

  return (
    <p className="m-0 flex flex-wrap items-center gap-x-2 px-1 text-xs font-semibold text-warn" aria-live="polite">
      <span>🎤 Sesten çevrildi, kontrol et</span>
      {countdown !== null && <span className="text-muted">{countdown} sn içinde gönderilecek. İptal için bir tuşa bas.</span>}
    </p>
  )
}

/** "Sesli komutu otomatik gönder" ayarı (bu tarayıcıda saklanır). */
export function AutoSendToggle() {
  const autoSend = useAutoSend()
  const config = useQuery({ queryKey: ['voice-config'], queryFn: voiceApi.config, staleTime: Infinity })
  if (!config.data?.enabled || !isRecordingSupported()) return null

  return (
    <label className="flex items-center gap-1.5">
      <input type="checkbox" checked={autoSend} onChange={(e) => setAutoSend(e.target.checked)} />
      Sesli komutu otomatik gönder
    </label>
  )
}
