<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent'ın değerlendirme kuralları (ör. "Her rötar nedeni bir rötar koduna dayanıyor"). Açıksa her çalışmadan sonra
 * hızlı model çıktıyı bu kurallara göre puanlar (agent_run_scores).
 */
#[Fillable(['agent_id', 'criteria', 'enabled'])]
class AgentEvaluation extends Model
{
    public const MAX_CRITERIA = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['criteria' => 'array', 'enabled' => 'boolean'];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isActive(): bool
    {
        return $this->enabled && ($this->criteria ?? []) !== [];
    }
}
