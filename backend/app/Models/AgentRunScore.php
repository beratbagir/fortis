<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir çalışmanın değerlendirme puanı (0–100) ve kural başına geçti/kaldı + gerekçe. Çalışma başına bir kayıt.
 */
#[Fillable(['agent_run_id', 'score', 'details', 'model'])]
class AgentRunScore extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'score' => 'integer'];
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
