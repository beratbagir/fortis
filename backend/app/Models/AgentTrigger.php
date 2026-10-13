<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agent tetikleyicisi. manual: elle; schedule: Kestra'daki akış zamanı gelince iç uca istek atar (config.cron);
 * event: APM uçuş değişikliği olayı (config.event, flight.delayed için config.min_delay_minutes).
 */
#[Fillable(['agent_id', 'type', 'config', 'input_template', 'kestra_flow_id', 'enabled', 'synced_at', 'sync_error'])]
class AgentTrigger extends Model
{
    public const MANUAL = 'manual';

    public const SCHEDULE = 'schedule';

    public const EVENT = 'event';

    public const TYPES = [self::MANUAL, self::SCHEDULE, self::EVENT];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['config' => 'array', 'enabled' => 'boolean', 'synced_at' => 'datetime'];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'trigger_id');
    }

    /** Görev metni: tetikleyicinin şablonu; olayda {{event}} olay verisiyle değişir. */
    public function input(?string $event = null): string
    {
        $template = trim((string) $this->input_template) ?: 'Görevini şimdi çalıştır.';

        if ($event === null) {
            return $template;
        }

        return str_contains($template, '{{event}}') ? str_replace('{{event}}', $event, $template) : "{$template}\n\nOlay:\n{$event}";
    }
}
