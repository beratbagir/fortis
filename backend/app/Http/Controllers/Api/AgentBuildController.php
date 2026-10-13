<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentDrafts;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Connectors\Connectors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Oluştur ekranı ve Architect (Faz A3.3): yeni agent (boş / şablondan / kopyala), sohbetten agent'a, Architect
 * görünümü (taslak, plan, özet kutuları, son test) ve durum geçişleri (pilota al, canlıya al, duraklat).
 * Agent kurmak builder ve admin içindir; bir agent'ı sahibi veya admin düzenler.
 */
class AgentBuildController extends Controller
{
    /** Araç kataloğundaki sistem adı → bağlantı anahtarı (config/connectors.php). */
    private const SYSTEM_CONNECTORS = ['IBS' => 'ibs', 'APM' => 'apm', 'SuccessFactors' => 'successfactors', 'Teams' => 'teams'];

    public function __construct(
        private readonly AgentDrafts $drafts,
        private readonly Connectors $connectors,
    ) {}

    /** Oluştur ekranı: örnekler, şablonlar, departmanlar, kopyalanabilecek agentlar. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'examples' => collect(config('agents.examples'))
                ->filter(fn (array $e) => collect($e['requires'] ?? [])->every(fn (string $key) => $this->connectors->isConfigured($key)))
                ->pluck('text')->values(),
            'templates' => Agent::query()->where('status', Agent::STATUS_TEMPLATE)->with('triggers')->orderBy('id')->get()->map(fn (Agent $t) => [
                'key' => $t->template_key,
                'name' => $t->name,
                'description' => $t->description,
                'icon' => $t->icon,
                'systems' => self::systems($t),
                'trigger' => self::triggerLabel($t),
                'available' => $this->available($t),
            ])->values(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'default_department_id' => $user->department_id,
            'agents' => Agent::query()->where('status', '!=', Agent::STATUS_TEMPLATE)
                ->unless($user->isAdmin(), fn ($q) => $q->where('owner_id', $user->id))
                ->orderBy('name')->get(['id', 'name', 'status', 'icon']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['blank', 'template', 'copy'])],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'template_key' => ['required_if:mode,template', 'nullable', 'string'],
            'source_agent_id' => ['required_if:mode,copy', 'nullable', 'integer'],
        ]);

        $agent = match ($validated['mode']) {
            'template' => $this->drafts->copy(
                Agent::query()->where('status', Agent::STATUS_TEMPLATE)->where('template_key', $validated['template_key'])->firstOrFail(),
                $user,
                $validated['department_id'] ?? null,
            ),
            'copy' => $this->drafts->copy($this->editable($user, Agent::query()->findOrFail($validated['source_agent_id'])), $user, $validated['department_id'] ?? null),
            default => $this->drafts->blank($user, $validated['department_id'] ?? null),
        };

        $this->architectConversation($agent, $user);

        return response()->json(['data' => $this->present($agent->refresh(), $user)], 201);
    }

    /**
     * Sohbetten agent'a ("Agent olarak kaydet" veya otomasyon önerisi): boş taslak + Architect sohbeti; sohbetin
     * soruları ve kullanılan veri araçları Architect'e ilk mesaj olarak gider (`seed`, arayüz gönderir).
     */
    public function fromConversation(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'action_title' => ['nullable', 'string', 'max:300'],
        ]);

        $source = Conversation::query()->findOrFail($validated['conversation_id']);
        abort_unless($user->isAdmin() || $source->user_id === $user->id, 404);

        $agent = $this->drafts->blank($user, null);
        $this->architectConversation($agent, $user);

        $questions = $source->messages()->where('role', Message::ROLE_USER)->where('text', '!=', '')->orderBy('id')->limit(5)->pluck('text')
            ->map(fn (string $q) => '- '.Str::limit(Str::squish($q), 300))->implode("\n");
        $tools = $source->messages()->where('role', Message::ROLE_ASSISTANT)->orderBy('id')->get()
            ->flatMap(fn (Message $m) => collect($m->content)->where('type', 'tool_use')->filter(fn (array $b) => str_starts_with($b['name'], 'query_')))
            ->map(fn (array $b) => "- {$b['name']}".(isset($b['input']['purpose']) ? ': '.Str::limit((string) $b['input']['purpose'], 120) : ''))
            ->unique()->take(8)->implode("\n");

        $seed = trim(($validated['action_title'] ?? null ? "{$validated['action_title']}\n\n" : '')
            ."Bu sohbetteki işi düzenli yapan bir agent kur.\n\nSohbette sorulanlar:\n{$questions}"
            .($tools !== '' ? "\n\nCevap için kullanılan veri sorguları:\n{$tools}" : ''));

        Audit::record('agent.create', $agent, meta: ['agent_id' => $agent->id, 'from' => "conversation:{$source->id}"], user: $user);

        return response()->json(['data' => $this->present($agent->refresh(), $user) + ['seed' => $seed]], 201);
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        $user = $request->user();
        $this->editable($user, $agent);

        if ($agent->conversation_id === null) {
            $this->architectConversation($agent, $user);
        }

        return response()->json(['data' => $this->present($agent->refresh(), $user)]);
    }

    /** Pilota al (taslak/duraklatılmış → pilot), canlıya al (pilot → canlı), duraklat, devam ettir. */
    public function status(Request $request, Agent $agent): JsonResponse
    {
        $user = $request->user();
        $this->editable($user, $agent);
        $validated = $request->validate(['status' => ['required', Rule::in([Agent::STATUS_PILOT, Agent::STATUS_LIVE, Agent::STATUS_PAUSED])]]);
        $target = $validated['status'];
        $from = $agent->status;

        if ($target === Agent::STATUS_PILOT) {
            abort_unless(in_array($from, [Agent::STATUS_DRAFT, Agent::STATUS_PAUSED], true), 409, 'Sadece taslak veya duraklatılmış agent pilota alınır.');
            ['errors' => $errors] = $this->drafts->validate(AgentDrafts::snapshot($agent), $user, $agent->owner);
            abort_if($agent->current_version === 0 || $errors !== [], 422, 'Taslak eksik: '.($errors === [] ? 'Architect henüz bir taslak kaydetmedi.' : implode(' ', $errors)));
        }
        if ($target === Agent::STATUS_LIVE) {
            abort_unless($user->canBuild(), 403, 'Canlıya alma builder ve admin içindir.');
            abort_unless(in_array($from, [Agent::STATUS_PILOT, Agent::STATUS_PAUSED], true), 409, 'Önce pilota alınmalı.');
            abort_unless(self::hasSuccessfulPilotRun($agent), 422, 'Canlıya almak için en az bir başarılı pilot çalışması gerekiyor (test çalışmaları sayılmaz).');
        }
        if ($target === Agent::STATUS_PAUSED) {
            abort_unless(in_array($from, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true), 409, 'Sadece pilot veya canlı agent duraklatılır.');
        }

        $agent->update(['status' => $target]);
        Audit::record('agent.status', $agent, AuditLog::RESULT_ALLOWED, ['agent_id' => $agent->id, 'from' => $from, 'to' => $target], user: $user);

        return response()->json(['data' => $this->present($agent->refresh(), $user)]);
    }

    public static function hasSuccessfulPilotRun(Agent $agent): bool
    {
        return $agent->runs()->where('is_test', false)->where('status', AgentRun::SUCCEEDED)->exists();
    }

    /** @return array<string, mixed> */
    private function present(Agent $agent, User $user): array
    {
        $runs = $agent->runs();
        $lastTest = (clone $runs)->where('is_test', true)->latest('id')->first();
        $finishedCosts = (clone $runs)->whereNotNull('finished_at')->pluck('cost_usd')->map(fn ($c) => (float) $c);
        $catalog = config('agents.tools');

        return [
            'id' => $agent->id,
            'slug' => $agent->slug,
            'name' => $agent->name,
            'description' => $agent->description,
            'icon' => $agent->icon,
            'status' => $agent->status,
            'version' => $agent->current_version,
            'updated_at' => $agent->updated_at?->toIso8601String(),
            'conversation_id' => $agent->conversation_id,
            'instructions' => $agent->instructions,
            'model' => $agent->model,
            'tools' => collect($agent->tools ?? [])->map(fn (array $t) => $t + ['kind' => $catalog[$t['name']]['kind'] ?? 'read', 'system' => $catalog[$t['name']]['system'] ?? null, 'label' => $catalog[$t['name']]['label'] ?? $t['name']])->values(),
            'triggers' => $agent->triggers()->orderBy('id')->get()->map(fn (AgentTrigger $t) => AgentTriggerController::present($t))->values(),
            'approval_policy' => $agent->approval_policy,
            'plan' => $agent->plan ?? [],
            'suggested_prompts' => $agent->suggested_prompts ?? [],
            'reads' => self::systems($agent, 'read'),
            'writes' => self::systems($agent, 'write'),
            'estimated_cost_usd' => $finishedCosts->isEmpty() ? null : round($finishedCosts->avg(), 4),
            'last_test_run' => $lastTest ? AgentController::presentRun($lastTest) : null,
            'can_go_live' => $user->canBuild() && self::hasSuccessfulPilotRun($agent),
            'has_draft' => $agent->current_version > 0,
        ];
    }

    /** @return list<string> */
    private static function systems(Agent $agent, ?string $kind = null): array
    {
        $catalog = config('agents.tools');

        return collect($agent->tools ?? [])
            ->map(fn (array $t) => $catalog[$t['name']] ?? null)
            ->filter(fn (?array $t) => $t !== null && ($kind === null || $t['kind'] === $kind) && $t['system'] !== 'Fortis')
            ->pluck('system')->unique()->values()->all();
    }

    private static function triggerLabel(Agent $agent): ?string
    {
        $trigger = $agent->triggers->firstWhere('type', '!=', AgentTrigger::MANUAL);

        return match ($trigger?->type) {
            AgentTrigger::SCHEDULE => 'Zamanlama: '.$trigger->config['cron'],
            AgentTrigger::EVENT => 'Olay: '.$trigger->config['event'].(isset($trigger->config['min_delay_minutes']) ? " (≥ {$trigger->config['min_delay_minutes']} dk)" : ''),
            default => null,
        };
    }

    /** Şablon sadece okuduğu sistemler bu ortamda yapılandırılmışsa kurulabilir. */
    private function available(Agent $template): bool
    {
        return collect(self::systems($template))
            ->map(fn (string $system) => self::SYSTEM_CONNECTORS[$system] ?? null)
            ->filter()
            ->every(fn (string $key) => $key === 'teams' ? config('agents.teams_channels') !== [] : $this->connectors->isConfigured($key));
    }

    private function editable(User $user, Agent $agent): Agent
    {
        abort_if($agent->status === Agent::STATUS_TEMPLATE, 404);
        abort_unless($user->isAdmin() || $agent->owner_id === $user->id, 404);

        return $agent;
    }

    private function architectConversation(Agent $agent, User $user): Conversation
    {
        $conversation = Conversation::create([
            'user_id' => $agent->owner_id ?? $user->id,
            'title' => "Architect · {$agent->name}",
            'kind' => Conversation::KIND_ARCHITECT,
            'title_generated_at' => now(),
        ]);
        $agent->forceFill(['conversation_id' => $conversation->id])->saveQuietly();

        return $conversation;
    }
}
