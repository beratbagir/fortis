<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentTrigger;
use App\Models\User;
use App\Services\Auth\Audit;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agent taslağı: Architect'in `propose_agent` önerisini doğrular ve kaydeder (her kayıt yeni sürüm), şablondan
 * veya var olan agenttan kopyalar. Doğrulama: araçlar katalogda, alıcılar izinli alan adlarında, Teams kanalları
 * tanımlı, tetikleyiciler geçerli (cron, olay), onay modu geçerli (`never` sadece admin), plan adım türleri bilinen.
 */
class AgentDrafts
{
    /** Claude'un "bana" için yazdığı yer tutucu; kayıtta agent sahibinin adresiyle değişir (adres Claude'a gitmez). */
    public const OWN_EMAIL_TOKEN = 'KULLANICI_EPOSTASI';

    private const APPROVAL_MODES = ['always', 'internal_only_auto', 'never'];

    public function __construct(private readonly AgentSchedules $schedules) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{errors: list<string>, draft: array<string, mixed>}
     */
    public function validate(array $input, User $actor, ?User $owner = null): array
    {
        $errors = [];
        $owner ??= $actor;
        $catalog = config('agents.tools');

        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $errors[] = 'name 3–80 karakter olmalı.';
        }
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 300) {
            $errors[] = 'description zorunlu, en fazla 300 karakter (kartta görünen 1–2 cümle).';
        }
        $instructions = trim((string) ($input['instructions'] ?? ''));
        if (mb_strlen($instructions) < 20 || mb_strlen($instructions) > 20000) {
            $errors[] = 'instructions 20–20.000 karakter olmalı.';
        }
        $icon = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', (string) ($input['icon'] ?? '')) ?? '', 0, 2));
        if (mb_strlen($icon) !== 2) {
            $icon = mb_strtoupper(collect(preg_split('/\s+/', $name) ?: [])->filter()->take(2)->map(fn (string $w) => mb_substr($w, 0, 1))->implode('')) ?: 'AG';
            $icon = mb_strlen($icon) === 2 ? $icon : mb_strtoupper(mb_substr($name.'G', 0, 2));
        }

        $model = (string) ($input['model'] ?? 'auto');
        if ($model !== 'auto' && ! array_key_exists($model, config('ai.models'))) {
            $errors[] = "model 'auto' veya izinli modellerden biri olmalı: ".implode(', ', array_keys(config('ai.models'))).'.';
        }

        // Araçlar ve kısıtları
        $tools = [];
        foreach ((array) ($input['tools'] ?? []) as $i => $tool) {
            $toolName = is_array($tool) ? (string) ($tool['name'] ?? '') : (string) $tool;
            if (! array_key_exists($toolName, $catalog)) {
                $errors[] = "tools[{$i}]: '{$toolName}' katalogda yok. Kullanılabilir araçlar: ".implode(', ', array_keys($catalog)).'.';

                continue;
            }
            if (collect($tools)->contains('name', $toolName)) {
                continue;
            }
            $constraints = is_array($tool) && is_array($tool['constraints'] ?? null) ? $tool['constraints'] : [];
            [$constraints, $toolErrors] = $this->constraints($toolName, $constraints, $owner);
            array_push($errors, ...array_map(fn (string $e) => "tools[{$i}] ({$toolName}): {$e}", $toolErrors));
            $tools[] = $constraints === [] ? ['name' => $toolName] : ['name' => $toolName, 'constraints' => $constraints];
        }
        if ($tools === [] && ! collect($errors)->contains(fn (string $e) => str_starts_with($e, 'tools['))) {
            $errors[] = 'En az bir araç seçilmeli.';
        }

        // Tetikleyiciler (elle çalıştırma her agentta vardır, ayrıca yazılmaz)
        $triggers = [];
        $rawTriggers = (array) ($input['triggers'] ?? []);
        if (count($rawTriggers) > 5) {
            $errors[] = 'En fazla 5 tetikleyici olabilir.';
        }
        foreach (array_slice($rawTriggers, 0, 5) as $i => $trigger) {
            $type = (string) ($trigger['type'] ?? '');
            $template = isset($trigger['input_template']) ? mb_substr(trim((string) $trigger['input_template']), 0, 5000) : null;

            if ($type === AgentTrigger::MANUAL) {
                continue;
            }
            if ($type === AgentTrigger::SCHEDULE) {
                $cron = trim((string) ($trigger['cron'] ?? ''));
                if (! CronExpression::isValidExpression($cron)) {
                    $errors[] = "triggers[{$i}]: geçersiz cron ifadesi '{$cron}' (ör. '0 6 * * *' her gün 06:00, saat dilimi Europe/Istanbul).";

                    continue;
                }
                $triggers[] = ['type' => $type, 'config' => ['cron' => $cron], 'input_template' => $template];
            } elseif ($type === AgentTrigger::EVENT) {
                $event = (string) ($trigger['event'] ?? '');
                if (! array_key_exists($event, config('agents.events'))) {
                    $errors[] = "triggers[{$i}]: bilinmeyen olay '{$event}'. Olaylar: ".implode(', ', array_keys(config('agents.events'))).'.';

                    continue;
                }
                $config = ['event' => $event];
                if ($event === 'flight.delayed') {
                    $minutes = (int) ($trigger['min_delay_minutes'] ?? config('agents.events')['flight.delayed']['default_min_delay_minutes']);
                    if ($minutes < 1 || $minutes > 1440) {
                        $errors[] = "triggers[{$i}]: min_delay_minutes 1–1440 olmalı.";

                        continue;
                    }
                    $config['min_delay_minutes'] = $minutes;
                }
                $triggers[] = ['type' => $type, 'config' => $config, 'input_template' => $template];
            } else {
                $errors[] = "triggers[{$i}]: tür 'schedule' veya 'event' olmalı (webhook bu fazda yok).";
            }
        }

        // Onay politikası
        $writeTools = collect($tools)->pluck('name')->filter(fn (string $n) => ($catalog[$n]['kind'] ?? null) === 'write')->values()->all();
        $rawPolicy = is_array($input['approval_policy'] ?? null) ? $input['approval_policy'] : [];
        $policy = ['approvers' => ['owner']];
        foreach ($writeTools as $tool) {
            $mode = (string) ($rawPolicy[$tool]['mode'] ?? 'always');
            if (! in_array($mode, self::APPROVAL_MODES, true)) {
                $errors[] = "approval_policy.{$tool}: mod always, internal_only_auto veya never olmalı.";

                continue;
            }
            if ($mode === 'never' && ! $actor->isAdmin()) {
                $errors[] = "approval_policy.{$tool}: 'never' (onaysız gönderim) sadece admin tarafından seçilebilir.";

                continue;
            }
            $policy[$tool] = ['mode' => $mode];
        }
        foreach ((array) ($rawPolicy['approvers'] ?? ['owner']) as $approver) {
            if ($approver === 'owner') {
                continue;
            }
            $user = match (true) {
                is_numeric($approver) => User::query()->whereKey((int) $approver)->where('is_active', true)->first(),
                is_string($approver) && str_contains($approver, '@') => User::query()->where('email', mb_strtolower(trim($approver)))->where('is_active', true)->first(),
                default => null,
            };
            if ($user === null) {
                $errors[] = "approval_policy.approvers: '{$approver}' Fortis'te etkin bir kullanıcı değil (owner veya kullanıcı e-postası).";

                continue;
            }
            $policy['approvers'][] = (string) $user->id;
        }
        $policy['approvers'] = array_values(array_unique($policy['approvers']));

        // Plan
        $types = array_keys(config('agents.plan_step_types'));
        $plan = [];
        $rawPlan = (array) ($input['plan'] ?? []);
        if (count($rawPlan) < 2 || count($rawPlan) > 12) {
            $errors[] = 'plan 2–12 adım olmalı.';
        }
        foreach (array_slice($rawPlan, 0, 12) as $i => $step) {
            $type = (string) ($step['type'] ?? '');
            $title = trim((string) ($step['title'] ?? ''));
            if (! in_array($type, $types, true) || $title === '') {
                $errors[] = "plan[{$i}]: type (".implode(', ', $types).') ve title zorunlu.';

                continue;
            }
            $plan[] = array_filter([
                'type' => $type,
                'title' => mb_substr($title, 0, 120),
                'detail' => isset($step['detail']) ? mb_substr(trim((string) $step['detail']), 0, 200) : null,
                'no' => $type === 'decide' && isset($step['no']) ? mb_substr(trim((string) $step['no']), 0, 120) : null,
            ], fn ($value) => $value !== null && $value !== '');
        }
        if ($writeTools !== [] && ! collect($plan)->contains('type', 'approve')) {
            $errors[] = 'Yazan araç varsa planda bir onay (approve) adımı olmalı: yazan aksiyonlar onaya gider.';
        }

        $prompts = array_values(array_slice(array_filter(array_map(fn ($p) => mb_substr(trim((string) $p), 0, 200), (array) ($input['suggested_prompts'] ?? []))), 0, 4));

        return ['errors' => $errors, 'draft' => [
            'name' => $name,
            'description' => $description,
            'icon' => $icon,
            'instructions' => $instructions,
            'model' => $model,
            'tools' => $tools,
            'triggers' => $triggers,
            'approval_policy' => $policy,
            'plan' => $plan,
            'suggested_prompts' => $prompts,
        ]];
    }

    /**
     * Doğrulanmış taslağı agent'a yazar: alanlar, tetikleyiciler (elle dışındakiler yeniden kurulur), yeni sürüm.
     *
     * @param  array<string, mixed>  $draft
     */
    public function apply(Agent $agent, array $draft, User $actor, string $source = 'architect'): Agent
    {
        DB::transaction(function () use ($agent, $draft, $actor, $source) {
            $agent->fill([
                'name' => $draft['name'],
                'description' => $draft['description'],
                'icon' => $draft['icon'],
                'instructions' => $draft['instructions'],
                'model' => $draft['model'],
                'tools' => $draft['tools'],
                'approval_policy' => $draft['approval_policy'],
                'plan' => $draft['plan'],
                'suggested_prompts' => $draft['suggested_prompts'],
            ]);
            if ($agent->status === Agent::STATUS_DRAFT && $agent->isDirty('name')) {
                $agent->slug = self::uniqueSlug($draft['name'], $agent->id);
            }
            $agent->save();

            $this->replaceTriggers($agent, $draft['triggers']);
            $this->saveVersion($agent, $actor, $source);
        });

        Audit::record('agent.draft.save', $agent, meta: ['agent_id' => $agent->id, 'version' => $agent->current_version, 'source' => $source, 'tools' => array_column($draft['tools'], 'name')], user: $actor);

        return $agent->refresh();
    }

    /** Şablondan veya var olan agenttan sahibine ait yeni taslak. */
    public function copy(Agent $source, User $owner, ?int $departmentId): Agent
    {
        $agent = DB::transaction(function () use ($source, $owner, $departmentId) {
            $agent = Agent::create([
                'slug' => self::uniqueSlug($source->name),
                'name' => $source->status === Agent::STATUS_TEMPLATE ? $source->name : "{$source->name} (kopya)",
                'description' => $source->description,
                'department_id' => $departmentId ?? $owner->department_id,
                'owner_id' => $owner->id,
                'status' => Agent::STATUS_DRAFT,
                'instructions' => $source->instructions,
                'model' => $source->model,
                'tools' => $source->tools,
                'approval_policy' => $source->approval_policy,
                'suggested_prompts' => $source->suggested_prompts,
                'plan' => $source->plan,
                'icon' => $source->icon,
                'template_key' => $source->template_key,
                'current_version' => 0,
            ]);
            $agent->triggers()->create(['type' => AgentTrigger::MANUAL]);
            $this->replaceTriggers($agent, $source->triggers()->where('type', '!=', AgentTrigger::MANUAL)->get()
                ->map(fn (AgentTrigger $t) => ['type' => $t->type, 'config' => $t->config, 'input_template' => $t->input_template])->all());
            $this->saveVersion($agent, $owner, $source->status === Agent::STATUS_TEMPLATE ? 'template' : 'copy');

            return $agent;
        });

        Audit::record('agent.create', $agent, meta: ['agent_id' => $agent->id, 'from' => $source->status === Agent::STATUS_TEMPLATE ? "template:{$source->template_key}" : "agent:{$source->id}"], user: $owner);

        return $agent;
    }

    /** Boş taslak: Architect sohbetiyle doldurulur. */
    public function blank(User $owner, ?int $departmentId): Agent
    {
        $agent = Agent::create([
            'slug' => self::uniqueSlug('yeni-agent'),
            'name' => 'Yeni agent',
            'department_id' => $departmentId ?? $owner->department_id,
            'owner_id' => $owner->id,
            'status' => Agent::STATUS_DRAFT,
            'instructions' => '',
            'tools' => [],
            'icon' => 'YA',
            'current_version' => 0,
        ]);
        $agent->triggers()->create(['type' => AgentTrigger::MANUAL]);
        Audit::record('agent.create', $agent, meta: ['agent_id' => $agent->id, 'from' => 'blank'], user: $owner);

        return $agent;
    }

    /**
     * Taslağın Claude'a giden hali: sahibin adresi yine yer tutucuya döner.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Agent $agent, bool $forClaude = false): array
    {
        $snapshot = [
            'name' => $agent->name,
            'description' => $agent->description,
            'icon' => $agent->icon,
            'instructions' => $agent->instructions,
            'model' => $agent->model,
            'tools' => $agent->tools ?? [],
            'triggers' => $agent->triggers()->orderBy('id')->get()->map(fn (AgentTrigger $t) => array_filter(['type' => $t->type, ...($t->config ?? []), 'input_template' => $t->input_template], fn ($v) => $v !== null))->values()->all(),
            'approval_policy' => $agent->approval_policy,
            'plan' => $agent->plan ?? [],
            'suggested_prompts' => $agent->suggested_prompts ?? [],
        ];

        $email = $agent->owner?->email;
        if ($forClaude && $email) {
            $snapshot = json_decode(str_replace(json_encode($email), json_encode(self::OWN_EMAIL_TOKEN), (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
        }

        return $snapshot;
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '-', 'tr') ?: 'agent';
        $base = mb_substr($base, 0, 60);
        $slug = $base;
        for ($i = 2; Agent::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function constraints(string $tool, array $constraints, User $owner): array
    {
        $errors = [];
        $clean = [];

        if (in_array($tool, ['send_email', 'deliver_report'], true) && isset($constraints['recipients'])) {
            $recipients = [];
            foreach ((array) $constraints['recipients'] as $email) {
                $email = trim((string) $email) === self::OWN_EMAIL_TOKEN ? (string) $owner->email : mb_strtolower(trim((string) $email));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! AgentActions::isInternal($email)) {
                    $errors[] = "alıcı '{$email}' izinli değil; sadece şu alan adları: ".implode(', ', config('agents.allowed_recipient_domains')).'.';

                    continue;
                }
                $recipients[] = $email;
            }
            if ($recipients !== []) {
                $clean['recipients'] = array_values(array_unique($recipients));
            }
        }
        if ($tool === 'post_teams' && isset($constraints['channels'])) {
            $known = array_keys(config('agents.teams_channels'));
            $channels = array_values(array_map('strval', (array) $constraints['channels']));
            $unknown = array_diff($channels, $known);
            if ($unknown !== []) {
                $errors[] = 'tanımlı olmayan Teams kanalı: '.implode(', ', $unknown).'. Tanımlı kanallar: '.(implode(', ', $known) ?: 'yok').'.';
            } else {
                $clean['channels'] = $channels;
            }
        }
        if (isset($constraints['max_per_run'])) {
            $max = (int) $constraints['max_per_run'];
            $max >= 1 && $max <= 20 ? $clean['max_per_run'] = $max : $errors[] = 'max_per_run 1–20 olmalı.';
        }
        if ($tool === 'query_warehouse' && isset($constraints['max_rows'])) {
            $rows = (int) $constraints['max_rows'];
            $rows >= 1 && $rows <= 1000 ? $clean['max_rows'] = $rows : $errors[] = 'max_rows 1–1000 olmalı.';
        }

        return [$clean, $errors];
    }

    /** @param  list<array{type: string, config: array<string, mixed>, input_template: string|null}>  $triggers */
    private function replaceTriggers(Agent $agent, array $triggers): void
    {
        foreach ($agent->triggers()->where('type', '!=', AgentTrigger::MANUAL)->get() as $old) {
            $this->schedules->remove($old->setRelation('agent', $agent));
            $old->delete();
        }
        if (! $agent->triggers()->where('type', AgentTrigger::MANUAL)->exists()) {
            $agent->triggers()->create(['type' => AgentTrigger::MANUAL]);
        }
        foreach ($triggers as $trigger) {
            $created = $agent->triggers()->create($trigger + ['enabled' => true]);
            $this->schedules->sync($created->setRelation('agent', $agent));
        }
    }

    private function saveVersion(Agent $agent, User $actor, string $source): void
    {
        $number = (int) $agent->versions()->max('number') + 1;
        $agent->versions()->create(['number' => $number, 'snapshot' => self::snapshot($agent), 'created_by' => $actor->id, 'source' => $source]);
        $agent->forceFill(['current_version' => $number])->saveQuietly();
    }
}
