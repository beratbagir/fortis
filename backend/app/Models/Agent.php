<?php

namespace App\Models;

use App\Agents\AgentSchedules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agent: talimat + izinli araçlar + model + tetikleyiciler + onay kuralları. Çalışınca Claude A1'deki araç
 * döngüsüyle işi her seferinde kendisi yapar; dışarıya yazan her aksiyon agent_actions üzerinden onaya gider.
 */
#[Fillable(['slug', 'name', 'description', 'department_id', 'owner_id', 'status', 'instructions', 'model', 'tools', 'approval_policy', 'suggested_prompts', 'plan', 'template_key', 'icon', 'current_version', 'conversation_id'])]
class Agent extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PILOT = 'pilot';

    public const STATUS_LIVE = 'live';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_TEMPLATE = 'template';

    /** Durum değişince (duraklatma, pilota/canlıya alma) Kestra'daki zamanlama akışları açılır/kapanır. */
    protected static function booted(): void
    {
        static::updated(function (Agent $agent) {
            if ($agent->wasChanged('status')) {
                app(AgentSchedules::class)->syncAgent($agent);
            }
        });
        static::deleted(function (Agent $agent) {
            $agent->triggers()->whereNotNull('kestra_flow_id')->get()->each(fn (AgentTrigger $t) => app(AgentSchedules::class)->remove($t->setRelation('agent', $agent)));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'approval_policy' => 'array',
            'suggested_prompts' => 'array',
            'plan' => 'array',
        ];
    }

    /** @return list<string> Agent'a verilen araç adları */
    public function toolNames(): array
    {
        return array_values(array_unique(array_column($this->tools ?? [], 'name')));
    }

    /** @return array<string, mixed> Araç kısıtları (alıcılar, en fazla kaç kez...) */
    public function toolConstraints(string $tool): array
    {
        return collect($this->tools ?? [])->firstWhere('name', $tool)['constraints'] ?? [];
    }

    /** @return array<string, mixed> */
    public function approvalPolicy(): array
    {
        return array_replace(config('agents.default_approval_policy'), $this->approval_policy ?? []);
    }

    public function isPilot(): bool
    {
        return $this->status === self::STATUS_PILOT;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasMany<AgentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class)->orderBy('number');
    }

    /** @return HasMany<AgentTrigger, $this> */
    public function triggers(): HasMany
    {
        return $this->hasMany(AgentTrigger::class);
    }

    /** @return HasOne<AgentEvaluation, $this> */
    public function evaluation(): HasOne
    {
        return $this->hasOne(AgentEvaluation::class);
    }
}
