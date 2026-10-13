<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VoiceTranscription;
use App\Services\Voice\TranscriptFilter;
use App\Services\Voice\VoiceException;
use App\Services\Voice\WhisperClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Sesli komut: ses → metin. Metin sadece arayüze döner, chat kutusuna yazılır; hiçbir şey otomatik gönderilmez.
 * Ses dosyası geçici klasöre taşınır, Whisper'a gider ve her durumda silinir.
 */
class VoiceController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('voice.enabled'),
            'max_seconds' => (int) config('voice.max_seconds'),
        ]);
    }

    public function transcribe(Request $request, WhisperClient $whisper, TranscriptFilter $filter): JsonResponse
    {
        abort_unless(config('voice.enabled'), 404);

        $request->validate([
            'audio' => ['required', 'file', 'max:'.config('voice.max_kilobytes'), 'mimetypes:'.implode(',', config('voice.mime_types'))],
        ], [
            'audio.max' => 'Ses kaydı en fazla 5 MB olabilir.',
            'audio.mimetypes' => 'Bu ses türü desteklenmiyor.',
        ]);

        $audio = $request->file('audio');
        $filename = 'audio.'.($audio->guessExtension() ?? 'webm');
        $dir = config('voice.tmp_dir');
        File::ensureDirectoryExists($dir);
        $path = null;

        try {
            $path = $audio->move($dir, Str::random(32).'.audio')->getPathname();
            $result = $whisper->transcribe($path, $filename);
        } catch (VoiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } finally {
            if ($path !== null) {
                File::delete($path);
            }
        }

        $text = $filter->clean($result['text']);

        $transcription = VoiceTranscription::create([
            'duration_s' => $result['duration_s'],
            'processing_ms' => $result['processing_ms'],
            'char_count' => mb_strlen($text),
        ]);

        if ($text === '') {
            return response()->json(['text' => '', 'reason' => 'no_speech', 'transcription_id' => $transcription->id]);
        }

        return response()->json([
            'text' => $text,
            'duration_s' => $result['duration_s'],
            'processing_ms' => $result['processing_ms'],
            'transcription_id' => $transcription->id,
        ]);
    }
}
