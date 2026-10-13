<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir agent çalışmasına verilen "faydalı / faydalı değil" ve isteğe bağlı yorum. Kullanıcı başına bir kayıt.
 */
#[Fillable(['agent_run_id', 'user_id', 'rating', 'comment'])]
class AgentFeedback extends Model
{
    public const USEFUL = 'useful';

    public const NOT_USEFUL = 'not_useful';

    public const RATINGS = [self::USEFUL, self::NOT_USEFUL];

    protected $table = 'agent_feedback';

    public const UPDATED_AT = null;

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
