<?php

namespace App\Models\Concerns;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Kayıt oluşurken sahibini doldurur: oturumdaki kullanıcı, yoksa kaydın bağlı olduğu sohbetin sahibi.
 * Modelde `department_id` kolonu varsa sahibin departmanı da yazılır. Açıkça verilen değerler değişmez.
 */
trait HasOwner
{
    protected static function bootHasOwner(): void
    {
        static::creating(function (Model $model) {
            /** @var Model&self $model */
            $column = $model->ownerColumn();

            if ($model->getAttribute($column) === null) {
                $conversationId = $model->getAttribute('conversation_id');

                $model->setAttribute($column, auth()->id() ?? ($conversationId === null ? null : Conversation::query()->whereKey($conversationId)->value('user_id')));
            }

            if (in_array('department_id', $model->getFillable(), true) && $model->getAttribute('department_id') === null && $model->getAttribute($column) !== null) {
                $model->setAttribute('department_id', User::query()->whereKey($model->getAttribute($column))->value('department_id'));
            }
        });
    }

    public function ownerColumn(): string
    {
        return 'user_id';
    }
}
