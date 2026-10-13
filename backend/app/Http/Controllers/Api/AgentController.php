<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentDrafts;
use App\Agents\AgentRunner;
use App\Agents\AgentStats;
use App\Agents\RunScorer;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentEvaluation;
use App\Models\AgentFeedback;
use App\Models\AgentRun;
use App\Models\AgentRunScore;
use App\Models\AgentTrigger;
use App\Models\AgentVersion;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Settings\AppSettings;
use App\Services\Usage\Budget;
use App\Services\Usage\BudgetExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agentlar: Agent Kaydı (liste, sayılar, şablonlar), agent sayfası (genel bakış, sohbet, çalışmalar, yapılandırma,
 * sürümler), elle çalıştırma ve geri bildirim. Herkes agentları görür; düzenleme sahibi ve admin içindir. Çalışma
 * ayrıntısını sahibi, admin ve çalışmayı başlatan görür.
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentStats $stats,
        private readonly AgentDrafts $drafts,
    ) {}

    /** Agent Kaydı: sayılar, agentlar (kart ölçüleriyle) ve şablonlar. */
    public function index(Request $request): JsonResponse
    {
        $agents = Agent::query()->with(['owner', 'department', 'triggers'])->orderBy('name')->get();
        [$templates, $agents] = $agents->partition(fn (Agent $a) => $a->status === Agent::STATUS_TEMPLATE);
        $cards = $this->stats->cards($agents);

        return response()->json(['data' => [
            'counts' => [
                'live' => $agents->where('status', Agent::STATUS_LIVE)->count(),
                'pilot' => $agents->where('status', Agent::STATUS_PILOT)->count(),
                'draft' => $agents->where('status', Agent::STATUS_DRAFT)->count(),
                'paused' => $agents->where('status', Agent::STATUS_PAUSED)->count(),
                'template' => $templates->count(),
            ],
            'agents' => $agents->map(fn (Agent $a) => $this->card($a, $request->user()) + $cards[$a->id])->values(),
            'templates' => $templates->map(fn (Agent $a) => $this->card($a, $request->user()) + ['key' => $a->template_key])->values(),
        ]]);
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        abort_if($agent->status === Agent::STATUS_TEMPLATE, 404);
        $user = $request->user();
        $runs = $agent->runs()->with(['actions', 'trigger', 'score'])->where('is_test', false)
            ->unless(self::canManage($user, $agent), fn ($q) => $q->where('triggered_by_user_id', $user->id))
            ->latest('id')->limit(10)->get();

        return response()->json(['data' => $this->card($agent, $user) + [
            'version' => $agent->current_version,
            'conversation_id' => $agent->conversation_id,
            'can_manage' => self::canManage($user, $agent),
            'can_chat' => $this->canChat($user, $agent),
            'suggested_prompts' => $agent->suggested_prompts ?? [],
            'overview' => $this->stats->overview($agent),
            'recent_runs' => $runs->map(fn (AgentRun $r) => self::runRow($r))->values(),
            'draft' => self::canManage($user, $agent) ? self::editable($agent) : null,
        ]]);
    }

    /** Çalışmalar sekmesi: filtre (durum, tetikleyici, test). Sahibi/admin hepsini, diğerleri kendi başlattıklarını görür. */
    public function runs(Request $request, Agent $agent): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([AgentRun::QUEUED, AgentRun::RUNNING, AgentRun::WAITING_APPROVAL, AgentRun::WAITING_INPUT, AgentRun::SUCCEEDED, AgentRun::FAILED, AgentRun::CANCELLED])],
            'origin' => ['nullable', Rule::in(['manual', 'schedule', 'event', 'chat'])],
            'test' => ['nullable', Rule::in(['0', '1', 'all'])],
        ]);

        $runs = $agent->runs()->with(['actions', 'trigger', 'score'])
            ->unless(self::canManage($user, $agent), fn ($q) => $q->where('triggered_by_user_id', $user->id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(($validated['test'] ?? '0') !== 'all', fn ($q) => $q->where('is_test', ($validated['test'] ?? '0') === '1'))
            ->when($validated['origin'] ?? null, fn ($q, $origin) => match ($origin) {
                'chat' => $q->where('source', 'chat'),
                'manual' => $q->whereNull('source')->whereNull('trigger_id'),
                default => $q->whereNull('source')->whereHas('trigger', fn ($t) => $t->where('type', $origin)),
            })
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $runs->map(fn (AgentRun $r) => self::runRow($r))->values()]);
    }

    /** Şimdi çalıştır. Pilot ve canlı agentları herkes; taslak agentları sadece builder/admin test olarak çalıştırır. */
    public function run(Request $request, Agent $agent, AgentRunner $runner): JsonResponse
    {
        $validated = $request->validate(['input' => ['nullable', 'string', 'max:5000'], 'test' => ['sometimes', 'boolean']]);
        $user = $request->user();
        $test = (bool) ($validated['test'] ?? false);

        abort_if($test && ! $user->canBuild(), 403, 'Test çalıştırma builder ve admin içindir.');
        abort_if(! $test && ! in_array($agent->status, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true), 409, 'Sadece pilot veya canlı agent çalıştırılabilir; taslağı test olarak çalıştırın.');

        $input = trim((string) ($validated['input'] ?? ''))
            ?: ($agent->triggers()->where('type', '!=', AgentTrigger::MANUAL)->whereNotNull('input_template')->value('input_template') ?? 'Görevini şimdi yap.');
        try {
            $run = $runner->start($agent, $input, $user, $test);
        } catch (BudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        return response()->json(['data' => self::presentRun($run->refresh())], 201);
    }

    /** Agent'la sohbet: yeni bir sohbet çalışması açar; mesajlar sohbetin mesaj ucundan (SSE) gider. */
    public function chat(Request $request, Agent $agent, AgentRunner $runner): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canChat($user, $agent), 403, 'Bu agentla sohbet edemezsin: pilot veya canlı değil.');
        try {
            app(Budget::class)->assertChatAllowed($user);
        } catch (BudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        return response()->json(['data' => self::presentRun($runner->startChat($agent, $user))], 201);
    }

    /** Kullanıcının bu agentla sohbetleri (en yeni önce). */
    public function chats(Request $request, Agent $agent): JsonResponse
    {
        $runs = $agent->runs()->where('source', 'chat')->where('triggered_by_user_id', $request->user()->id)->latest('id')->limit(20)->get();

        return response()->json(['data' => $runs->map(fn (AgentRun $r) => [
            'id' => $r->id,
            'conversation_id' => $r->conversation_id,
            'title' => (string) ($r->conversation?->messages()->where('role', Message::ROLE_USER)->where('text', '!=', '')->orderBy('id')->value('text') ?? 'Yeni sohbet'),
            'started_at' => $r->started_at?->toIso8601String(),
            'is_test' => $r->is_test,
        ])->values()]);
    }

    public function showRun(Request $request, AgentRun $run): JsonResponse
    {
        abort_unless(self::canSeeRun($request->user(), $run), 404);

        return response()->json(['data' => self::presentRun($run, $request->user())]);
    }

    public function feedback(Request $request, AgentRun $run): JsonResponse
    {
        $user = $request->user();
        abort_unless(self::canSeeRun($user, $run), 404);
        $validated = $request->validate(['rating' => ['required', Rule::in(AgentFeedback::RATINGS)], 'comment' => ['nullable', 'string', 'max:1000']]);

        AgentFeedback::query()->updateOrCreate(['agent_run_id' => $run->id, 'user_id' => $user->id], $validated);
        Audit::record('agent.feedback', $run, meta: ['agent_id' => $run->agent_id, 'rating' => $validated['rating']], user: $user);

        return response()->json(['data' => self::presentRun($run, $user)]);
    }

    /** Yapılandırma için katalog: araçlar, olaylar, modeller, izinli alan adları, kanallar, departmanlar, sahip adayları. */
    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'tools' => collect(config('agents.tools'))->map(fn (array $t, string $name) => ['name' => $name] + $t)->values(),
            'events' => collect(config('agents.events'))->map(fn (array $e, string $name) => ['name' => $name, 'label' => $e['label'], 'default_min_delay_minutes' => $e['default_min_delay_minutes'] ?? null])->values(),
            'models' => collect(app(AppSettings::class)->models())->map(fn (array $m, string $id) => ['id' => $id, 'label' => $m['label']])->values(),
            'recipient_domains' => app(AppSettings::class)->recipientDomains(),
            'teams_channels' => array_keys(config('agents.teams_channels')),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'owners' => $request->user()->isAdmin()
                ? User::query()->where('is_active', true)->whereIn('role', [User::ROLE_BUILDER, User::ROLE_ADMIN])->orderBy('name')->get(['id', 'name', 'email'])
                : [],
        ]]);
    }

    /** Yapılandırmayı kaydet: Architect'le aynı doğrulama; her kayıt yeni sürüm (source = manual). */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $user = $request->user();
        abort_unless(self::canManage($user, $agent) && $user->canBuild(), 403, 'Bu agentı sadece sahibi veya admin düzenleyebilir.');
        abort_if($agent->status === Agent::STATUS_TEMPLATE, 404);
        $settings = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'base_version' => ['nullable', 'integer'],
        ]);

        abort_if(isset($settings['base_version']) && (int) $settings['base_version'] !== $agent->current_version, 409, "Bu agent sen düzenlerken değişti (şu an v{$agent->current_version}). Sayfayı yenileyip tekrar dene.");

        $owner = $agent->owner;
        if (isset($settings['owner_id']) && (int) $settings['owner_id'] !== $agent->owner_id) {
            abort_unless($user->isAdmin(), 403, 'Sahibi sadece admin değiştirebilir.');
            $owner = User::query()->findOrFail($settings['owner_id']);
            abort_unless($owner->is_active && $owner->canBuild(), 422, 'Sahip etkin bir builder veya admin olmalı.');
        }

        // Önce doğrula; geçmezse hiçbir şey (sahip, departman dahil) değişmez.
        ['errors' => $errors, 'draft' => $draft] = $this->drafts->validate($request->except(['department_id', 'owner_id', 'base_version']), $user, $owner);
        if ($errors !== []) {
            return response()->json(['message' => 'Yapılandırma kaydedilmedi.', 'errors' => $errors], 422);
        }

        $agent->owner()->associate($owner);
        if (array_key_exists('department_id', $settings)) {
            $agent->department_id = $settings['department_id'];
        }
        $agent = $this->drafts->apply($agent, $draft, $user, 'manual');

        return $this->show($request, $agent);
    }

    /** @return JsonResponse Sürümler (en yeni önce), snapshot'larıyla; fark arayüzde hesaplanır. */
    public function versions(Request $request, Agent $agent): JsonResponse
    {
        abort_unless(self::canManage($request->user(), $agent), 403);

        return response()->json(['data' => $agent->versions()->reorder('number', 'desc')->with('creator')->get()->map(fn (AgentVersion $v) => [
            'number' => $v->number,
            'source' => $v->source,
            'note' => $v->note,
            'created_at' => $v->created_at?->toIso8601String(),
            'created_by' => $v->creator?->name,
            'snapshot' => $v->snapshot,
            'current' => $v->number === $agent->current_version,
        ])->values()]);
    }

    /** Eski sürümü geri yükle: o sürümün içeriği yeni bir sürüm olarak kaydedilir (geçmiş silinmez). */
    public function restore(Request $request, Agent $agent, int $number): JsonResponse
    {
        $user = $request->user();
        abort_unless(self::canManage($user, $agent) && $user->canBuild(), 403);
        $version = $agent->versions()->where('number', $number)->firstOrFail();

        ['errors' => $errors, 'draft' => $draft] = $this->drafts->validate($version->snapshot, $user, $agent->owner);
        abort_if($errors !== [], 422, 'Bu sürüm artık geçerli değil: '.implode(' ', $errors));

        $agent = $this->drafts->apply($agent, $draft, $user, 'restore');
        $agent->versions()->where('number', $agent->current_version)->update(['note' => "v{$number} geri yüklendi"]);

        return $this->show($request, $agent);
    }

    /** Değerlendirme sekmesi: kurallar, açık mı, son 30 gün ortalama ve son puanlar. Herkes görür, sahibi/admin düzenler. */
    public function evaluation(Request $request, Agent $agent): JsonResponse
    {
        abort_if($agent->status === Agent::STATUS_TEMPLATE, 404);

        return response()->json(['data' => $this->presentEvaluation($agent, $request->user())]);
    }

    public function updateEvaluation(Request $request, Agent $agent): JsonResponse
    {
        $user = $request->user();
        abort_unless(self::canManage($user, $agent) && $user->canBuild(), 403, 'Değerlendirme kurallarını sadece sahibi veya admin değiştirebilir.');
        $validated = $request->validate([
            'criteria' => ['present', 'array', 'max:'.AgentEvaluation::MAX_CRITERIA],
            'criteria.*' => ['required', 'string', 'min:5', 'max:200'],
            'enabled' => ['required', 'boolean'],
        ]);

        $criteria = array_values(array_unique(array_map(fn (string $c) => trim($c), $validated['criteria'])));
        abort_if($validated['enabled'] && $criteria === [], 422, 'Değerlendirmeyi açmak için en az bir kural yaz.');

        $agent->evaluation()->updateOrCreate([], ['criteria' => $criteria, 'enabled' => $validated['enabled']]);
        Audit::record('agent.evaluation.update', $agent, meta: ['agent_id' => $agent->id, 'criteria' => count($criteria), 'enabled' => $validated['enabled']], user: $user);

        return response()->json(['data' => $this->presentEvaluation($agent->refresh(), $user)]);
    }

    /** Çalışmayı şimdi (yeniden) puanla: sahibi/admin; kurallar değiştikten sonra denemek için. */
    public function score(Request $request, AgentRun $run, RunScorer $scorer): JsonResponse
    {
        $user = $request->user();
        abort_unless($run->agent !== null && self::canManage($user, $run->agent), 404);
        abort_unless($run->agent->evaluation?->isActive(), 422, 'Bu agentın değerlendirmesi kapalı veya kuralı yok.');
        abort_if(trim((string) $run->output) === '', 422, 'Bu çalışmanın puanlanacak çıktısı yok.');

        try {
            $scorer->score($run);
        } catch (\RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        return response()->json(['data' => self::presentRun($run->refresh(), $user)]);
    }

    /** @return array<string, mixed> */
    private function presentEvaluation(Agent $agent, User $user): array
    {
        $evaluation = $agent->evaluation;
        $scores = AgentRunScore::query()->whereHas('run', fn ($q) => $q->where('agent_id', $agent->id)->where('is_test', false))
            ->where('created_at', '>=', now()->subDays(30))->with('run')->latest('id')->get();

        return [
            'criteria' => $evaluation?->criteria ?? [],
            'enabled' => $evaluation?->enabled ?? false,
            'can_edit' => self::canManage($user, $agent) && $user->canBuild(),
            'avg_score_30d' => $scores->isEmpty() ? null : (int) round($scores->avg('score')),
            'scored_runs_30d' => $scores->count(),
            'rule_pass_rates' => collect($evaluation?->criteria ?? [])->map(function (string $rule) use ($scores) {
                $results = $scores->flatMap(fn (AgentRunScore $s) => collect($s->details)->where('rule', $rule));

                return ['rule' => $rule, 'checked' => $results->count(), 'pass_pct' => $results->isEmpty() ? null : (int) round(100 * $results->where('passed', true)->count() / $results->count())];
            })->values(),
            'recent' => $scores->take(10)->map(fn (AgentRunScore $s) => ['run_id' => $s->agent_run_id, 'score' => $s->score, 'started_at' => $s->run?->started_at?->toIso8601String()])->values(),
        ];
    }

    public static function canManage(User $user, Agent $agent): bool
    {
        return $user->isAdmin() || $agent->owner_id === $user->id;
    }

    public static function canSeeRun(User $user, AgentRun $run): bool
    {
        return $user->isAdmin() || $run->agent?->owner_id === $user->id || $run->triggered_by_user_id === $user->id;
    }

    private function canChat(User $user, Agent $agent): bool
    {
        return in_array($agent->status, [Agent::STATUS_PILOT, Agent::STATUS_LIVE], true)
            || (in_array($agent->status, [Agent::STATUS_DRAFT, Agent::STATUS_PAUSED], true) && self::canManage($user, $agent) && $user->canBuild());
    }

    /** @return array<string, mixed> */
    private function card(Agent $agent, User $user): array
    {
        $catalog = config('agents.tools');

        return [
            'id' => $agent->id,
            'slug' => $agent->slug,
            'name' => $agent->name,
            'description' => $agent->description,
            'status' => $agent->status,
            'icon' => $agent->icon,
            'tools' => $agent->toolNames(),
            'systems' => collect($agent->tools ?? [])->map(fn (array $t) => $catalog[$t['name']]['system'] ?? null)->filter(fn (?string $s) => $s !== null && $s !== 'Fortis')->unique()->values(),
            'owner' => $agent->owner ? ['id' => $agent->owner->id, 'name' => $agent->owner->name, 'title' => $agent->owner->title] : null,
            'department' => $agent->department?->name,
            'department_id' => $agent->department_id,
            'trigger' => $agent->triggers->where('type', '!=', AgentTrigger::MANUAL)->map(fn (AgentTrigger $t) => AgentStats::triggerText($t))->implode(' · ') ?: 'Elle',
            'mine' => $agent->owner_id === $user->id,
            'created_at' => $agent->created_at?->toIso8601String(),
        ];
    }

    /**
     * Yapılandırma formu için taslak: onaylayıcılar kullanıcı kimliği yerine e-posta.
     *
     * @return array<string, mixed>
     */
    private static function editable(Agent $agent): array
    {
        $snapshot = AgentDrafts::snapshot($agent);
        $policy = $snapshot['approval_policy'] ?? ['approvers' => ['owner']];
        $policy['approvers'] = collect($policy['approvers'] ?? ['owner'])->map(fn ($a) => $a === 'owner' ? 'owner' : (User::query()->find((int) $a)?->email ?? null))->filter()->values()->all();
        $snapshot['approval_policy'] = $policy;
        $snapshot['triggers'] = array_values(array_filter($snapshot['triggers'], fn (array $t) => $t['type'] !== AgentTrigger::MANUAL));

        return $snapshot + ['department_id' => $agent->department_id, 'owner_id' => $agent->owner_id];
    }

    /** @return array<string, mixed> Çalışmalar tablosu satırı */
    private static function runRow(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'origin' => $run->origin(),
            'is_test' => $run->is_test,
            'attempts' => $run->attempts,
            'summary' => $run->summary,
            'started_at' => ($run->started_at ?? $run->created_at)?->toIso8601String(),
            'duration_seconds' => $run->started_at && $run->finished_at ? (int) $run->finished_at->diffInSeconds($run->started_at, true) : null,
            'cost_usd' => (float) $run->cost_usd,
            'actions_approved' => $run->actions->whereIn('status', [AgentAction::APPROVED, AgentAction::EXECUTED])->count(),
            'actions_total' => $run->actions->count(),
            'score' => $run->score?->score,
        ];
    }

    /** @return array<string, mixed> */
    public static function presentRun(AgentRun $run, ?User $viewer = null): array
    {
        $answer = $run->conversation?->messages()->where('role', Message::ROLE_ASSISTANT)->whereNotNull('meta')->latest('id')->first()?->meta['answer'] ?? null;
        $feedback = $run->feedback()->get();

        return [
            'id' => $run->id,
            'agent' => ['id' => $run->agent_id, 'name' => $run->agent?->name],
            'status' => $run->status,
            'origin' => $run->origin(),
            'is_test' => $run->is_test,
            'attempts' => $run->attempts,
            'input' => $run->input,
            'summary' => $run->summary,
            'output' => $run->output,
            'error' => $run->error,
            'notes' => $run->notes ?? [],
            'tool_calls_count' => $run->tool_calls_count,
            'cost_usd' => (float) $run->cost_usd,
            'conversation_id' => $run->conversation_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->started_at && $run->finished_at ? (int) $run->finished_at->diffInSeconds($run->started_at, true) : null,
            'trace' => $answer['trace'] ?? [],
            'score' => ($score = $run->score()->first()) ? ['score' => $score->score, 'details' => $score->details, 'model' => $score->model, 'created_at' => $score->created_at?->toIso8601String()] : null,
            'feedback' => [
                'useful' => $feedback->where('rating', AgentFeedback::USEFUL)->count(),
                'not_useful' => $feedback->where('rating', AgentFeedback::NOT_USEFUL)->count(),
                'mine' => $viewer ? $feedback->firstWhere('user_id', $viewer->id)?->rating : null,
            ],
            'actions' => $run->actions()->get()->map(fn (AgentAction $a) => ['id' => $a->id, 'type' => $a->type, 'status' => $a->status, 'requires_approval' => $a->requires_approval, 'payload' => $a->payload, 'reason' => $a->reason, 'result' => $a->result])->values(),
        ];
    }
}
