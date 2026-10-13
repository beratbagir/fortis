<?php

namespace App\Agents;

use App\Ai\ApmResultShaper;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\FlightWatch;
use App\Services\Apm\ApmApi;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Olay tetikleyicileri: APM uçuş bacaklarını okur, son görülen halle karşılaştırır ve olay üretir.
 * - flight.delayed: kalkış rötarı arttı. Tetikleyici, rötar eşiğini (min_delay_minutes) bu taramada ilk kez geçtiyse çalışır.
 * - flight.cancelled: sefer iptal oldu.
 * İlk kez görülen uçuş sadece kaydedilir (ilk taramada bütün rötarlı uçuşlar için çalışma açılmasın).
 */
class FlightEvents
{
    private const MAX_PAGES = 20;

    public function __construct(
        private readonly ApmApi $apm,
        private readonly ApmResultShaper $shaper,
        private readonly AgentTriggers $triggers,
        private readonly Inbox $inbox,
    ) {}

    /** @return Collection<int, AgentTrigger> Açık olay tetikleyicileri (pilot veya canlı agentlarda). */
    public function activeTriggers(): Collection
    {
        return AgentTrigger::query()
            ->with('agent.owner', 'agent.department')
            ->where('type', AgentTrigger::EVENT)
            ->where('enabled', true)
            ->whereHas('agent', fn ($q) => $q->whereIn('status', [Agent::STATUS_PILOT, Agent::STATUS_LIVE]))
            ->get();
    }

    /**
     * @return array{legs: int, events: list<array{event: string, flight_no: string}>, runs: int, skipped: int}
     *
     * @throws RuntimeException APM'ye ulaşılamazsa (tetikleyici sahiplerine uyarı düştükten sonra).
     */
    public function scan(): array
    {
        $triggers = $this->activeTriggers();
        $summary = ['legs' => 0, 'events' => [], 'runs' => 0, 'skipped' => 0];

        // APM şirket genelinde kapatıldıysa olaylar da üretilmez.
        if ($triggers->isEmpty() || app(ConnectorRegistry::class)->isDisabled('apm')) {
            return $summary;
        }

        try {
            $legs = $this->legs();
        } catch (RuntimeException $e) {
            $triggers->each(fn (AgentTrigger $t) => $this->inbox->triggerAlert($t, "{$t->agent->name}: uçuş olayları okunamadı", "APM'ye ulaşılamadı ({$e->getMessage()}). Rötar ve iptal olayları bu düzelene kadar agentı tetiklemez."));

            throw $e;
        }
        $triggers->each(fn (AgentTrigger $t) => $this->inbox->closeTriggerAlerts($t));

        $summary['legs'] = count($legs);
        $known = FlightWatch::query()->whereIn('leg_id', array_keys($legs))->get()->keyBy('leg_id');

        foreach ($legs as $legId => $leg) {
            $before = $known->get($legId);
            $delay = max(0, (int) ($leg['dep_delay_min'] ?? 0));
            $cancelled = (bool) ($leg['cancelled'] ?? false);

            if ($before !== null) {
                if ($cancelled && ! $before->cancelled) {
                    $summary = $this->dispatch('flight.cancelled', $leg, $before->dep_delay_min, $triggers, $summary);
                }
                if ($delay > $before->dep_delay_min && ! $cancelled) {
                    $summary = $this->dispatch('flight.delayed', $leg, $before->dep_delay_min, $triggers, $summary);
                }
            }

            FlightWatch::query()->updateOrCreate(['leg_id' => $legId], [
                'flight_no' => $leg['flight_no'] ?? null,
                'dep' => $leg['dep'] ?? null,
                'arr' => $leg['arr'] ?? null,
                'std_utc' => $leg['std_utc'] ?? null,
                'dep_delay_min' => $delay,
                'cancelled' => $cancelled,
                'seen_at' => now(),
            ]);
        }

        FlightWatch::query()->where('seen_at', '<', now()->subDays(3))->delete();

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $leg
     * @param  Collection<int, AgentTrigger>  $triggers
     * @param  array{legs: int, events: list<array{event: string, flight_no: string}>, runs: int, skipped: int}  $summary
     * @return array{legs: int, events: list<array{event: string, flight_no: string}>, runs: int, skipped: int}
     */
    private function dispatch(string $event, array $leg, int $previousDelay, Collection $triggers, array $summary): array
    {
        $summary['events'][] = ['event' => $event, 'flight_no' => (string) ($leg['flight_no'] ?? '')];
        $delay = (int) ($leg['dep_delay_min'] ?? 0);

        foreach ($triggers as $trigger) {
            if (($trigger->config['event'] ?? null) !== $event) {
                continue;
            }
            if ($event === 'flight.delayed') {
                $threshold = (int) ($trigger->config['min_delay_minutes'] ?? config('agents.events')['flight.delayed']['default_min_delay_minutes']);
                if ($delay < $threshold || $previousDelay >= $threshold) {
                    continue;
                }
            }

            $result = $this->triggers->fire($trigger, self::describe($event, $leg, $previousDelay), ['event' => $event, 'flight_no' => $leg['flight_no'] ?? null, 'leg_id' => $leg['leg_id'] ?? null]);
            $result instanceof AgentRun ? $summary['runs']++ : $summary['skipped']++;
        }

        return $summary;
    }

    /** @param  array<string, mixed>  $leg */
    public static function describe(string $event, array $leg, int $previousDelay): string
    {
        $lines = [
            "Olay: {$event}",
            'Sefer: '.($leg['flight_no'] ?? '?').' '.($leg['dep'] ?? '?').'→'.($leg['arr'] ?? '?'),
            'Planlı kalkış (UTC): '.($leg['std_utc'] ?? '?'),
        ];
        if ($event === 'flight.delayed') {
            $lines[] = "Kalkış rötarı: {$leg['dep_delay_min']} dk (önceki tarama: {$previousDelay} dk)";
            if (! empty($leg['delay_codes'])) {
                $lines[] = 'Rötar kodları: '.(is_array($leg['delay_codes']) ? implode(', ', $leg['delay_codes']) : $leg['delay_codes']);
            }
        } else {
            $lines[] = 'İptal nedeni: '.($leg['cancel_reason'] ?? 'belirtilmemiş');
        }
        if (! empty($leg['aircraft_reg'])) {
            $lines[] = "Uçak: {$leg['aircraft_reg']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Pencere: şimdi − flight_watch_hours_back … şimdi + flight_watch_hours_ahead (UTC). Sayfalar leg_id ile birleştirilir;
     * yeni kayıt getirmeyen sayfada durulur (sayfa numarasının 0 veya 1'den başlaması fark etmez).
     *
     * @return array<string, array<string, mixed>>
     */
    private function legs(): array
    {
        $from = now('UTC')->subHours(config('agents.flight_watch_hours_back'))->format('Y-m-d\TH:i:s\Z');
        $to = now('UTC')->addHours(config('agents.flight_watch_hours_ahead'))->format('Y-m-d\TH:i:s\Z');
        $legs = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $read = $this->apm->read('GET', "/api/legs?dateFrom={$from}&dateTo={$to}&page={$page}");
            $shaped = $this->shaper->shape('GET', $read['operation_path'], $read['response']);
            $before = count($legs);

            foreach ($shaped['rows'] as $row) {
                if (! empty($row['leg_id'])) {
                    $legs[(string) $row['leg_id']] = $row;
                }
            }

            if (count($legs) === $before || ($shaped['total_rows'] > 0 && count($legs) >= $shaped['total_rows'])) {
                break;
            }
        }

        return $legs;
    }
}
