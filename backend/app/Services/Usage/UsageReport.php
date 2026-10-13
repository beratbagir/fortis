<?php

namespace App\Services\Usage;

use App\Models\AiCall;
use App\Models\Conversation;
use App\Models\Flow;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * Kullanım ve maliyet ekranı. Tüm tutarlar ai_calls.cost_usd toplamıdır (maliyet çağrı anında yazılır).
 *
 * Akış bazında dağılım: çağrının akışı ai_calls.flow_id, yoksa sohbetin konusu olan akış (editör veya
 * "chat'te değiştir"), yoksa sohbetten çıkan akış; hiçbiri yoksa sohbetin kendisi.
 */
class UsageReport
{
    public function __construct(
        private readonly KestraClient $kestra,
        private readonly Budget $budget,
        private readonly AppSettings $settings,
    ) {}

    /** @return array{0: Carbon, 1: Carbon} */
    public static function range(?string $month): array
    {
        $start = $month !== null ? Carbon::createFromFormat('!Y-m', $month) : now()->startOfMonth();

        return [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()];
    }

    /** @return array<string, mixed> */
    public function summary(?string $month): array
    {
        [$start, $end] = self::range($month);
        $totals = $this->totals($start, $end);
        [$prevStart, $prevEnd] = [$start->copy()->subMonthNoOverflow()->startOfMonth(), $start->copy()->subMonthNoOverflow()->endOfMonth()];
        $previous = $this->totals($prevStart, $prevEnd)['cost_usd'];

        return [
            'month' => $start->format('Y-m'),
            ...$totals,
            'avg_cost_per_call' => $totals['calls'] > 0 ? round($totals['cost_usd'] / $totals['calls'], 6) : 0.0,
            'flow_runs' => $this->flowRuns($start, $end),
            'flow_step_calls' => AiCall::query()->whereBetween('created_at', [$start, $end])->where('source', AiCall::SOURCE_FLOW_STEP)->count(),
            'previous_cost_usd' => $previous,
            'change_pct' => $previous > 0 ? round(($totals['cost_usd'] - $previous) / $previous * 100, 1) : null,
            'budget' => $start->isSameMonth(now()) ? $this->budget->status() : null,
            'blocks_flow_ai_steps' => $this->settings->blocksFlowAiSteps(),
        ];
    }

    /** @return list<array{date: string, chat_cost: float, flow_step_cost: float}> */
    public function daily(?string $month): array
    {
        [$start, $end] = self::range($month);

        $rows = AiCall::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) AS day, source, SUM(cost_usd) AS cost')
            ->groupBy('day', 'source')
            ->get();

        $days = [];
        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'chat_cost' => 0.0, 'flow_step_cost' => 0.0];
        }

        foreach ($rows as $row) {
            $key = $row->source === AiCall::SOURCE_FLOW_STEP ? 'flow_step_cost' : 'chat_cost';
            $days[$row->day][$key] = round($days[$row->day][$key] + (float) $row->cost, 6);
        }

        return array_values($days);
    }

    /** @return list<array<string, mixed>> */
    public function byModel(?string $month): array
    {
        [$start, $end] = self::range($month);

        return AiCall::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('model, COUNT(*) AS calls, SUM(input_tokens + cache_creation_input_tokens + cache_read_input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(cost_usd) AS cost')
            ->groupBy('model')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => [
                'model' => $row->model,
                'label' => $this->settings->modelLabel($row->model),
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd' => round((float) $row->cost, 6),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function byFlow(?string $month): array
    {
        [$start, $end] = self::range($month);

        $rows = AiCall::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('flow_id, conversation_id, source, model, COUNT(*) AS calls, SUM(input_tokens + cache_creation_input_tokens + cache_read_input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(cost_usd) AS cost')
            ->groupBy('flow_id', 'conversation_id', 'source', 'model')
            ->get();

        $conversations = Conversation::query()->whereIn('id', $rows->pluck('conversation_id')->filter()->unique())->get()->keyBy('id');
        $createdFrom = Flow::withTrashed()->whereIn('conversation_id', $conversations->keys())->orderBy('id')->get()->unique('conversation_id')->keyBy('conversation_id');
        $flowIds = $rows->pluck('flow_id')->merge($conversations->pluck('context_flow_id'))->merge($createdFrom->pluck('id'))->filter()->unique();
        $flows = Flow::withTrashed()->whereIn('id', $flowIds)->get()->keyBy('id');

        $groups = [];
        foreach ($rows as $row) {
            $conversation = $conversations->get($row->conversation_id);
            $flowId = $row->flow_id ?? $conversation?->context_flow_id ?? $createdFrom->get($row->conversation_id)?->id;
            $flow = $flowId !== null ? $flows->get($flowId) : null;

            [$key, $entry] = match (true) {
                $flow !== null => ["flow:{$flow->id}", ['type' => 'flow', 'flow_id' => $flow->id, 'conversation_id' => null, 'name' => $flow->title, 'flow_status' => $flow->trashed() ? 'deleted' : $flow->status]],
                $conversation !== null => ["conversation:{$conversation->id}", ['type' => 'conversation', 'flow_id' => null, 'conversation_id' => $conversation->id, 'name' => 'Sohbet: '.($conversation->title ?? 'Adsız sohbet'), 'flow_status' => null]],
                default => ['other', ['type' => 'other', 'flow_id' => null, 'conversation_id' => null, 'name' => 'Diğer (silinmiş sohbetler)', 'flow_status' => null]],
            };

            $groups[$key] ??= $entry + ['models' => [], 'calls' => 0, 'chat_calls' => 0, 'flow_step_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
            $group = &$groups[$key];
            $group['models'][$row->model] = ($group['models'][$row->model] ?? 0) + (float) $row->cost;
            $group['calls'] += (int) $row->calls;
            $group[$row->source === AiCall::SOURCE_FLOW_STEP ? 'flow_step_calls' : 'chat_calls'] += (int) $row->calls;
            $group['input_tokens'] += (int) $row->input_tokens;
            $group['output_tokens'] += (int) $row->output_tokens;
            $group['cost_usd'] = round($group['cost_usd'] + (float) $row->cost, 6);
            unset($group);
        }

        return collect($groups)
            ->map(function (array $group) {
                arsort($group['models']);
                $group['models'] = array_map(fn (string $model) => $this->settings->modelLabel($model), array_keys($group['models']));

                return $group;
            })
            ->sortByDesc('cost_usd')
            ->values()
            ->all();
    }

    /**
     * Akışlar listesindeki "bu ay maliyet": akış kaydı id'si => USD.
     *
     * @return array<int, float>
     */
    public function flowCosts(?string $month = null): array
    {
        return collect($this->byFlow($month))->where('type', 'flow')->mapWithKeys(fn (array $row) => [$row['flow_id'] => $row['cost_usd']])->all();
    }

    /** @return array{cost_usd: float, calls: int, input_tokens: int, output_tokens: int} */
    private function totals(Carbon $start, Carbon $end): array
    {
        $row = AiCall::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(input_tokens + cache_creation_input_tokens + cache_read_input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens, COALESCE(SUM(cost_usd), 0) AS cost')
            ->first();

        return [
            'cost_usd' => round((float) $row->cost, 6),
            'calls' => (int) $row->calls,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
        ];
    }

    /** Ay içinde başlayan akış çalışmaları (test çalışmaları hariç). Kestra'ya ulaşılamazsa null. */
    private function flowRuns(Carbon $start, Carbon $end): ?int
    {
        try {
            return $this->kestra->countExecutions((string) config('kestra.namespace'), $start, $end);
        } catch (KestraException) {
            return null;
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Akış / sohbet', 'Tür', 'Model', 'Çağrı', 'Girdi token', 'Çıktı token', 'Maliyet (USD)']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['name'],
                ['flow' => 'Akış', 'conversation' => 'Sohbet', 'other' => 'Diğer'][$row['type']],
                implode(', ', $row['models']),
                $row['calls'],
                $row['input_tokens'],
                $row['output_tokens'],
                number_format($row['cost_usd'], 6, '.', ''),
            ]);
        }

        rewind($out);

        return "\u{FEFF}".stream_get_contents($out);
    }
}
