<?php

namespace App\Agents;

use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Jobs\RunAgentJob;
use App\Jobs\ScoreAgentRunJob;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\AiCall;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Claude\AskAnswer;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ClaudeException;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Agent çalıştırıcı. Yeni bir döngü yazılmaz: ClaudeChat'in araç döngüsü agent kipinde çalışır (sadece agent'ın
 * araçları, yazma araçları agent_actions üzerinden, sınırlar, agent sistem promptu). Çalışmanın konuşması bir
 * sohbette (kind = agent) tutulur; soru (ask_user) ve "Agent'a sor" bu sohbet üzerinden devam eder.
 */
class AgentRunner
{
    public function __construct(
        private readonly Inbox $inbox,
        private readonly AgentActions $actions,
    ) {}

    /** Çalışmayı açar ve kuyruğa atar. */
    public function start(Agent $agent, string $input, ?User $user, bool $test = false, ?AgentTrigger $trigger = null): AgentRun
    {
        $conversation = Conversation::create([
            'user_id' => $user?->id ?? $agent->owner_id,
            'title' => $agent->name.' · '.now('Europe/Istanbul')->format('d.m H:i'),
            'kind' => Conversation::KIND_AGENT,
            'model' => $agent->model === 'auto' ? null : $agent->model,
            'title_generated_at' => now(),
        ]);

        $run = $agent->runs()->create([
            'agent_version' => $agent->current_version,
            'trigger_id' => $trigger?->id,
            'triggered_by_user_id' => $user?->id,
            'conversation_id' => $conversation->id,
            'status' => AgentRun::QUEUED,
            'input' => $input,
            'is_test' => $test,
        ]);

        $this->audit($run, 'agent.run.start', AuditLog::RESULT_ALLOWED, ['test' => $test, 'trigger' => $trigger?->type ?? 'manual', 'trigger_id' => $trigger?->id]);
        RunAgentJob::dispatch($run->id)->onQueue(config('agents.queue'));

        return $run;
    }

    /**
     * Agent sayfasındaki sohbet: kuyruğa gitmeyen, mesaj ucuyla (SSE) yürüyen bir çalışma. Pilot/canlı değilse test sayılır
     * (yazan aksiyonlar önizlenir).
     */
    public function startChat(Agent $agent, User $user): AgentRun
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => "{$agent->name} · sohbet",
            'kind' => Conversation::KIND_AGENT,
            'model' => $agent->model === 'auto' ? null : $agent->model,
            'title_generated_at' => now(),
        ]);

        $run = $agent->runs()->create([
            'agent_version' => $agent->current_version,
            'triggered_by_user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'source' => 'chat',
            'status' => AgentRun::RUNNING,
            'input' => 'Sohbet',
            'started_at' => now(),
            'attempts' => 1,
            'is_test' => ! in_array($agent->status, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true),
        ]);
        $this->audit($run, 'agent.run.start', AuditLog::RESULT_ALLOWED, ['test' => $run->is_test, 'trigger' => 'chat']);

        return $run;
    }

    /**
     * Kuyruk işi: çalışmayı yürütür (ilk kez veya soru cevaplandıktan sonra devam).
     *
     * @throws ClaudeException 429 / 5xx: iş yeniden denensin diye
     */
    public function execute(AgentRun $run): void
    {
        if (! in_array($run->status, [AgentRun::QUEUED, AgentRun::RUNNING], true)) {
            return;
        }

        $run->update(['status' => AgentRun::RUNNING, 'started_at' => $run->started_at ?? now(), 'attempts' => $run->attempts + 1]);
        $conversation = $run->conversation;

        if (! $conversation->messages()->exists()) {
            $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => $run->input]], 'text' => $run->input]);
        }

        // Araçlar (sorgular, denetim kaydı) çalışmayı başlatan kişinin adıyla.
        $user = $run->ownerUser();
        $previous = Auth::user();
        if ($user !== null) {
            Auth::setUser($user);
        }

        try {
            $this->reply($run, fn () => null);
        } catch (ClaudeException $e) {
            $retryable = $e->getPrevious() instanceof RateLimitException || ($e->getPrevious() instanceof APIStatusException && $e->getPrevious()->status >= 500);
            if ($retryable) {
                $run->update(['status' => AgentRun::QUEUED]);

                throw $e;
            }
            $this->fail($run, $e->getMessage());
        } finally {
            if ($previous !== null) {
                Auth::setUser($previous);
            }
        }
    }

    /**
     * Agent kipinde bir cevap turu (kuyruktan veya "Agent'a sor" sohbetinden) ve sonucun işlenmesi.
     *
     * @param  Closure(string $event, mixed $data): void  $emit
     *
     * @throws ClaudeException
     */
    public function reply(AgentRun $run, Closure $emit): Message
    {
        $session = new AgentSession($run->agent, $run);
        $final = app(ClaudeChat::class)->forAgent($session)->reply($run->conversation, $emit);
        $this->finish($run->refresh(), $final);

        return $final;
    }

    /** Kuyruk işi tüm denemelerde başarısız oldu. */
    public function fail(AgentRun $run, string $error): void
    {
        $run->update(['status' => AgentRun::FAILED, 'error' => mb_substr($error, 0, 2000), 'finished_at' => now()] + $this->totals($run));
        $this->audit($run, 'agent.run.fail', AuditLog::RESULT_FAILED, ['error' => mb_substr($error, 0, 300)]);

        if (($owner = $run->agent->owner ?? $run->ownerUser()) !== null) {
            $this->inbox->alert($owner, $run, "{$run->agent->name} çalışması durdu", $error);
        }
    }

    /** Soru kartının cevabı (Inbox'tan): cevap konuşmaya eklenir, çalışma kuyruğa geri döner. */
    public function answer(AgentRun $run, ?array $answer, User $user): void
    {
        $pending = $run->conversation->messages()->where('role', Message::ROLE_ASSISTANT)->latest('id')->first();
        abort_unless($run->status === AgentRun::WAITING_INPUT && $pending?->isAwaitingInput(), 422, 'Bu çalışma cevap beklemiyor.');

        [$blocks, $visible] = AskAnswer::blocks($pending, $answer, '');
        $run->conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => $blocks, 'text' => $visible]);
        $pending->update(['status' => null]);

        $this->inbox->close(Inbox::SUBJECT_RUN, $run->id);
        Audit::record('agent.run.answer', $run, meta: ['skipped' => (bool) ($answer['skipped'] ?? false)], user: $user);

        $run->update(['status' => AgentRun::QUEUED]);
        RunAgentJob::dispatch($run->id)->onQueue(config('agents.queue'));
    }

    /** Bütün aksiyonlar sonuçlanınca onay bekleyen çalışma tamamlanır. */
    public function settle(AgentRun $run): void
    {
        if ($run->status === AgentRun::WAITING_APPROVAL && ! $run->actions()->where('status', AgentAction::PENDING)->exists()) {
            $run->update(['status' => AgentRun::SUCCEEDED, 'finished_at' => now()]);
            $this->audit($run, 'agent.run.finish', AuditLog::RESULT_ALLOWED, []);
        }
    }

    /** Döngü bitti: çıktı, özet, durum (soru / onay bekliyor / başarılı), maliyet. */
    private function finish(AgentRun $run, Message $final): void
    {
        if ($final->isAwaitingInput()) {
            $run->update(['status' => AgentRun::WAITING_INPUT] + $this->totals($run));
            if (($owner = $run->ownerUser()) !== null) {
                $this->inbox->question($owner, $run, $final->meta['ask']['questions'] ?? []);
            }

            return;
        }

        $output = trim((string) preg_replace('/:::actions\s*\n[\s\S]*?\n\s*:::/', '', $final->text));
        $output = trim((string) preg_replace('/\s?\[\^q:[A-Za-z0-9_]+\]/', '', $output));
        $pending = $run->actions()->where('status', AgentAction::PENDING)->exists();

        $run->update([
            'status' => $pending ? AgentRun::WAITING_APPROVAL : AgentRun::SUCCEEDED,
            'output' => $output,
            'summary' => mb_substr(trim(strtok($output, "\n") ?: ''), 0, 500),
            'finished_at' => $pending ? null : now(),
            'error' => null,
        ] + $this->totals($run));

        $this->audit($run, $pending ? 'agent.run.waiting_approval' : 'agent.run.finish', $pending ? AuditLog::RESULT_AWAITING_APPROVAL : AuditLog::RESULT_ALLOWED, []);

        // Değerlendirme açıksa çıktı kurallara göre puanlanır (A3.5).
        if (RunScorer::shouldScore($run->refresh()->load('agent.evaluation'))) {
            ScoreAgentRunJob::dispatch($run->id)->onQueue(config('agents.queue'));
        }
    }

    /** @return array{cost_usd: float} */
    private function totals(AgentRun $run): array
    {
        return ['cost_usd' => round((float) AiCall::query()->where('agent_run_id', $run->id)->sum('cost_usd'), 6)];
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(AgentRun $run, string $action, string $result, array $meta): void
    {
        Audit::record($action, $run, $result, $meta + ['agent_id' => $run->agent_id], actorType: AuditLog::ACTOR_AGENT, actorLabel: $run->agent->name, user: $run->ownerUser());
    }
}
