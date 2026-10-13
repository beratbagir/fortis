<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir cevaba verilen 👍 / 👎 ve isteğe bağlı yorum. Kullanıcı başına bir kayıt.
 */
#[Fillable(['message_id', 'user_id', 'rating', 'comment'])]
class MessageFeedback extends Model
{
    public const RATINGS = ['up', 'down'];

    protected $table = 'message_feedback';

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
