<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentSchedules;
use App\Agents\Inbox;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentTrigger;
use App\Models\User;
use App\Services\Auth\Audit;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Agent tetikleyicileri: zamanlama (Kestra) ve olay (APM uçuş değişiklikleri). Elle çalıştırma her agentta vardır,
 * ayrıca eklenmez. Agent sahibi ve admin yönetir. Kestra'ya aktarılamayan zamanlama kaydedilir; hata tetikleyicide
 * görünür ve sahibin Inbox'ına uyarı düşer.
 */
class AgentTriggerController extends Controller
{
    public function __construct(
        private readonly AgentSchedules $schedules,
        private readonly Inbox $inbox,
    ) {}

    public function index(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request->user(), $agent);

        return response()->json(['data' => $agent->triggers()->orderBy('id')->get()->map(self::present(...))->values()]);
    }

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request->user(), $agent);
        $validated = $this->validated($request, null);

        $trigger = $agent->triggers()->create($validated + ['enabled' => true]);
        Audit::record('agent.trigger.create', $trigger, meta: ['agent_id' => $agent->id, 'type' => $trigger->type, 'config' => $trigger->config]);
        $this->schedules->sync($trigger->setRelation('agent', $agent));

        return response()->json(['data' => self::present($trigger->refresh())], 201);
    }

    public function update(Request $request, AgentTrigger $trigger): JsonResponse
    {
        $this->authorizeAgent($request->user(), $trigger->agent);
        $validated = $this->validated($request, $trigger);

        $trigger->update($validated);
        Audit::record('agent.trigger.update', $trigger, meta: ['agent_id' => $trigger->agent_id, 'fields' => array_keys($validated)]);
        $this->schedules->sync($trigger);

        return response()->json(['data' => self::present($trigger->refresh())]);
    }

    public function destroy(Request $request, AgentTrigger $trigger): JsonResponse
    {
        $this->authorizeAgent($request->user(), $trigger->agent);
        abort_if($trigger->type === AgentTrigger::MANUAL, 422, 'Elle çalıştırma kaldırılamaz.');
        abort_unless($this->schedules->remove($trigger), 502, "Kestra'daki akış silinemedi; tetikleyici silinmedi. Kestra'ya ulaşılınca tekrar deneyin.");

        Audit::record('agent.trigger.delete', $trigger, meta: ['agent_id' => $trigger->agent_id, 'type' => $trigger->type]);
        $trigger->delete();
        $this->inbox->closeTriggerAlerts($trigger);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    public static function present(AgentTrigger $trigger): array
    {
        return [
            'id' => $trigger->id,
            'type' => $trigger->type,
            'config' => $trigger->config ?? [],
            'input_template' => $trigger->input_template,
            'enabled' => $trigger->enabled,
            'kestra_flow_id' => $trigger->kestra_flow_id,
            'synced_at' => $trigger->synced_at?->toIso8601String(),
            'sync_error' => $trigger->sync_error,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AgentTrigger $trigger): array
    {
        $type = $trigger?->type ?? $request->input('type');

        $validated = validator($request->all(), [
            'type' => [$trigger ? 'prohibited' : 'required', Rule::in([AgentTrigger::SCHEDULE, AgentTrigger::EVENT])],
            'config' => [$trigger ? 'sometimes' : 'required', 'array'],
            'config.cron' => [Rule::requiredIf($type === AgentTrigger::SCHEDULE && $request->has('config')), 'string', 'max:100'],
            'config.event' => [Rule::requiredIf($type === AgentTrigger::EVENT && $request->has('config')), Rule::in(array_keys(config('agents.events')))],
            'config.min_delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'input_template' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'enabled' => ['sometimes', 'boolean'],
        ])->after(function (Validator $v) use ($request, $type) {
            $cron = $request->input('config.cron');
            if ($type === AgentTrigger::SCHEDULE && is_string($cron) && ! CronExpression::isValidExpression($cron)) {
                $v->errors()->add('config.cron', 'Geçersiz cron ifadesi.');
            }
        })->validate();

        if (isset($validated['config'])) {
            $validated['config'] = $type === AgentTrigger::SCHEDULE
                ? ['cron' => trim($validated['config']['cron'])]
                : array_filter([
                    'event' => $validated['config']['event'],
                    'min_delay_minutes' => $validated['config']['event'] === 'flight.delayed'
                        ? (int) ($validated['config']['min_delay_minutes'] ?? config('agents.events')['flight.delayed']['default_min_delay_minutes'])
                        : null,
                ], fn ($value) => $value !== null);
        }

        return $validated;
    }

    private function authorizeAgent(User $user, Agent $agent): void
    {
        abort_unless($user->isAdmin() || $agent->owner_id === $user->id, 403, 'Bu agentın tetikleyicilerini sadece sahibi veya admin yönetebilir.');
    }
}
