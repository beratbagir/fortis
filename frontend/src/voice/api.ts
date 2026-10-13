import { apiFetch } from '../http'

export interface VoiceConfig {
  enabled: boolean
  max_seconds: number
}

export interface Transcript {
  text: string
  transcription_id: number
  reason?: 'no_speech'
}

/** Mesajla birlikte gider: ölçüm kaydı ve kullanıcının metni göndermeden önce değiştirip değiştirmediği. */
export interface VoiceMeta {
  transcription_id: number
  edited: boolean
}

export const voiceApi = {
  config: async (): Promise<VoiceConfig> => {
    const response = await apiFetch('/api/voice/config', { headers: { Accept: 'application/json' } })
    return response.ok ? response.json() : { enabled: false, max_seconds: 30 }
  },

  transcribe: async (audio: Blob, signal?: AbortSignal): Promise<Transcript> => {
    const body = new FormData()
    body.append('audio', audio, audio.type.includes('mp4') ? 'voice.mp4' : 'voice.webm')
    const response = await apiFetch('/api/voice/transcribe', { method: 'POST', headers: { Accept: 'application/json' }, body, signal })
    const json = await response.json().catch(() => ({}))

    if (!response.ok) {
      if (response.status === 429) throw new Error('Çok sık denendi, biraz bekleyip tekrar dene.')
      if (response.status === 404) throw new Error('Sesli komut kapalı.')
      throw new Error(json.message ?? 'Sesli komut şu an kullanılamıyor, yazarak devam edebilirsin.')
    }

    return json as Transcript
  },
}
