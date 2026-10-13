<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentRunner;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\VoiceTranscription;
use App\Services\Claude\AskAnswer;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\TitleGenerator;
use App\Services\Flows\Blocks\FlowGraph;
use App\Services\Flows\FlowManager;
use App\Services\Usage\Budget;
use App\Services\Usage\BudgetExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Kullanıcı mesajını kaydeder ve Claude'un yanıtını SSE ile akıtır.
 *
 * Olaylar: `user_message` (kaydedilen kullanıcı mesajı), `thinking`, `delta` {text}, `tool` {name, status},
 * `assistant_message` (kaydedilen her asistan turu ve akış kartı içeren araç sonucu), `error` {message}.
 * Akış `done` olayıyla biter.
 *
 * Bir sohbette aynı anda tek yanıt üretilir: iki yanıt aynı anda çalışırsa geçmiş birbirine karışır.
 * İkinci istek 409 alır.
 */
class ConversationMessageController extends Controller
{
    /** Bir yanıtın en uzun süresi; kilit en geç bu sürede kendiliğinden açılır. */
    private const REPLY_LOCK_SECONDS = 600;

    public function __invoke(StoreMessageRequest $request, Conversation $conversation, ClaudeChat $chat, Budget $budget): StreamedResponse|JsonResponse
    {
        try {
            $budget->assertChatAllowed();
        } catch (BudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        $lock = Cache::lock("conversation-reply:{$conversation->id}", self::REPLY_LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->json(['message' => 'Bu sohbette bir yanıt hâlâ hazırlanıyor. Bitince tekrar deneyin.'], 409);
        }

        $content = trim((string) $request->validated('content'));
        $answer = $request->validated('answer');
        $pending = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->latest('id')->first();
        $pending = $pending?->isAwaitingInput() ? $pending : null;

        if ($answer !== null && $pending === null) {
            $lock->release();

            return response()->json(['message' => 'Cevap bekleyen bir soru yok.'], 422);
        }

        // Bekleyen soru varsa (soru kartı) kullanıcının mesajı ask_user aracının sonucu olur; Claude kaldığı yerden devam eder.
        [$askResults, $visible] = $pending === null ? [[], $content] : AskAnswer::blocks($pending, $answer, $content);

        $userMessage = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => [
                ...$askResults,
                ...$this->systemNotes($conversation),
                ...$this->selectionNote($conversation, $request->validated('context.selected_task_id')),
                ...($answer === null ? [['type' => 'text', 'text' => $content]] : []),
            ],
            'text' => $visible,
            'meta' => ($pending->meta['ask']['cards'] ?? []) === [] ? null : ['flow_cards' => $pending->meta['ask']['cards']],
        ]);

        if ($pending !== null) {
            $pending->update(['status' => null]);
            if (($pending->meta['ask']['cards'] ?? []) !== []) {
                $chat->linkCards($pending->meta['ask']['cards'], $userMessage);
            }
        }

        if ($request->validated('voice') !== null) {
            VoiceTranscription::whereKey($request->validated('voice.transcription_id'))
                ->whereNull('was_edited')
                ->update(['was_edited' => $request->boolean('voice.edited')]);
        }

        if ($conversation->title === null) {
            $conversation->update(['title' => Str::limit(Str::squish($visible), 60)]);
        }

        return response()->stream(function () use ($chat, $conversation, $userMessage, $lock) {
            // Kullanıcı sayfayı kapatsa da yanıt tamamlanır: yarım kalan bir araç döngüsü geçmişi bozar.
            ignore_user_abort(true);

            $send = function (string $event, mixed $data): void {
                if (connection_aborted()) {
                    return;
                }

                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $send('user_message', (new MessageResource($userMessage))->resolve());

            try {
                $emit = function (string $event, mixed $data) use ($send): void {
                    $data instanceof Message
                        ? $send('assistant_message', (new MessageResource($data))->resolve())
                        : $send($event, $data);
                };

                // Agent çalışmasının sohbeti ("Agent'a sor"): agent'ın araçları ve kurallarıyla devam eder.
                $run = $conversation->isAgent() ? AgentRun::query()->where('conversation_id', $conversation->id)->first() : null;
                // Architect sohbeti: agent'ı kurar (propose_agent), sağdaki plan her kayıtta güncellenir.
                $architect = $conversation->isArchitect() ? Agent::query()->where('conversation_id', $conversation->id)->first() : null;
                $final = match (true) {
                    $run !== null => app(AgentRunner::class)->reply($run, $emit),
                    $architect !== null => $chat->forArchitect($architect)->reply($conversation, $emit),
                    default => $chat->reply($conversation, $emit),
                };

                // İlk cevaptan sonra bir kez: kısa başlık (hızlı model). Olmazsa ilk mesajın başı kalır.
                if (! $final->isAwaitingInput() && $conversation->title_generated_at === null && $final->text !== '') {
                    $question = (string) $conversation->messages()->where('role', Message::ROLE_USER)->where('text', '!=', '')->orderBy('id')->value('text');
                    app(TitleGenerator::class)->generate($conversation, $question, $final->text);
                }
            } catch (ClaudeException $e) {
                $send('error', ['message' => $e->getMessage()]);
            } finally {
                $lock->release();
            }

            $send('done', ['conversation' => ['id' => $conversation->id, 'title' => $conversation->refresh()->title]]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Editörde tuvalde bir blok seçiliyse Claude'a hangi blok olduğunu söyler ("bunu 180 dakika yap").
     * Blok güncel taslak sürümün grafından bulunur; bulunamazsa not eklenmez.
     *
     * @return list<array{type: string, text: string}>
     */
    private function selectionNote(Conversation $conversation, ?string $taskId): array
    {
        $yaml = $conversation->isEditor() ? $conversation->contextFlow?->latestVersion()?->yaml : null;

        if ($taskId === null || $yaml === null) {
            return [];
        }

        try {
            $graph = app(FlowGraph::class)->build((array) Yaml::parse($yaml));
        } catch (ParseException) {
            return [];
        }

        $node = collect([...$graph['triggers'], ...FlowGraph::flatten($graph)])->firstWhere('id', $taskId);

        if ($node === null) {
            return [];
        }

        $title = $node['title'] !== null && $node['title'] !== $node['id'] ? sprintf(', "%s"', $node['title']) : '';

        return [['type' => 'text', 'text' => "[Kullanıcı tuvalde şu bloğu seçmiş: {$node['id']} ({$node['label']}{$title})]"]];
    }

    private function editorNote(Flow $flow): string
    {
        $latest = $flow->latestVersion();
        $published = $flow->currentVersion?->isUploaded() ? $flow->currentVersion->number : null;

        return sprintf(
            '[Sistem notu: Bu sohbet akış editöründe açık. Akış: "%s" (%s.%s), güncel taslak sürüm: %s%s. Değişiklik istenirse önce get_flow ile güncel YAML\'ı al, aynı id ile propose_flow çağır. Öneri kaydedilmez; kullanıcı tuvalde farkı görüp Uygula veya Geri al der. Yayınlamak kullanıcının Yayınla butonuyla olur.]',
            $flow->title, $flow->namespace, $flow->flow_id, $latest->number ?? '?', $published === null ? ', henüz yayınlanmadı' : ", yayındaki sürüm: {$published}",
        );
    }

    /**
     * Architect: son mesajdan bu yana biten test çalışmasının sonucu (Claude'un taslağı buna göre iyileştirebilmesi için).
     *
     * @return array{type: string, text: string}|null
     */
    private function architectTestNote(Conversation $conversation, mixed $since): ?array
    {
        $agent = Agent::query()->where('conversation_id', $conversation->id)->first();
        $run = $agent?->runs()->where('is_test', true)->whereNotNull('finished_at')->when($since, fn ($q) => $q->where('finished_at', '>=', $since))->latest('id')->first();

        if ($run === null) {
            return null;
        }

        $actions = $run->actions()->get()->map(fn ($a) => "{$a->type}: ".($a->payload['subject'] ?? $a->payload['channel'] ?? ''))->implode('; ');

        return ['type' => 'text', 'text' => "[Sistem notu] Son veriyle test çalışması #{$run->id} bitti: {$run->status}. Özet: ".mb_substr((string) ($run->summary ?? $run->error ?? ''), 0, 600).($actions !== '' ? " Gönderilecekti (test, gönderilmedi): {$actions}." : '').' Maliyet: $'.number_format((float) $run->cost_usd, 3).'.'];
    }

    /**
     * Son kullanıcı mesajından bu yana bu sohbette olanları (yüklemeler, başlatılan veya biten çalışmalar)
     * Claude'a bildirir. Notlar yeni mesajın içine yazılır; geçmiş değişmez.
     *
     * @return list<array{type: string, text: string}>
     */
    private function systemNotes(Conversation $conversation): array
    {
        $since = $conversation->messages()->where('role', Message::ROLE_USER)->where('text', '!=', '')->latest('id')->value('created_at');

        // Editör sohbeti: her mesajda güncel sürüm bilgisi (tuvalde değişmiş olabilir).
        // "Bu akışı chat'te değiştir": ilk mesajda akışın mevcut YAML'ı bağlam olarak gider.
        $context = match (true) {
            $conversation->isEditor() && $conversation->contextFlow !== null => collect([$this->editorNote($conversation->contextFlow)]),
            $conversation->isArchitect() => collect([$this->architectTestNote($conversation, $since)])->filter(),
            $since === null && $conversation->contextFlow !== null => collect([app(FlowManager::class)->contextNote($conversation->contextFlow)]),
            default => collect(),
        };

        $uploads = FlowVersion::query()
            ->with('flow')
            ->whereIn('message_id', $conversation->messages()->select('id'))
            ->whereNotNull('uploaded_at')
            ->when($since, fn ($query) => $query->where('uploaded_at', '>=', $since))
            ->orderBy('uploaded_at')
            ->get()
            ->map(fn (FlowVersion $version) => "[Sistem notu: Kullanıcı \"{$version->title}\" akışının {$version->number}. sürümünü ({$version->flow->namespace}.{$version->flow->flow_id}) Kestra'ya yükledi. Kestra revision: {$version->kestra_revision}.]");

        $executions = Execution::query()
            ->with('flow')
            ->where('conversation_id', $conversation->id)
            ->when($since, fn ($query) => $query->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('ended_at', '>=', $since)))
            ->orderBy('id')
            ->get()
            ->map(fn (Execution $execution) => sprintf(
                '[Sistem notu: Kullanıcı "%s" akışını (%s) çalıştırdı. Çalışma id: %s. Son bilinen durum: %s.%s]',
                $execution->flow->title,
                $execution->flow->flow_id,
                $execution->kestra_execution_id,
                $execution->state,
                $execution->isFinished() ? '' : ' Güncel durum için get_execution kullan.',
            ));

        return $context->concat($uploads)->concat($executions)
            ->map(fn (string $text) => ['type' => 'text', 'text' => $text])
            ->values()
            ->all();
    }
}
