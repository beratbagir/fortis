<?php

namespace App\Services\Voice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Şirket içindeki Whisper servisiyle (infra/whisper) tek iletişim noktası. Ses dosyası ve metin loglanmaz.
 */
class WhisperClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly string $vocabulary,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('voice.whisper_url'), config('voice.timeout'), config('voice.vocabulary'));
    }

    /**
     * @return array{text: string, duration_s: float, processing_ms: int}
     *
     * @throws VoiceException
     */
    public function transcribe(string $path, string $filename): array
    {
        $stream = fopen($path, 'rb');

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->attach('file', $stream, $filename)
                ->post("{$this->baseUrl}/transcribe", ['prompt' => $this->vocabulary]);
        } catch (ConnectionException) {
            throw VoiceException::unavailable();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return match (true) {
            $response->successful() && is_string($response->json('text')) => [
                'text' => $response->json('text'),
                'duration_s' => (float) $response->json('duration_s', 0),
                'processing_ms' => (int) $response->json('processing_ms', 0),
            ],
            $response->status() === 413 => throw new VoiceException('Kayıt en fazla 30 saniye olabilir.', 413),
            $response->status() === 422 => throw new VoiceException('Ses kaydı okunamadı. Tekrar dene.', 422),
            default => throw VoiceException::unavailable(),
        };
    }
}
