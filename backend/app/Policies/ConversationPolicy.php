<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Sohbeti sadece sahibi (ve admin) açar. Başkasının sohbeti "yok" (404) görünür.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $user->isAdmin() || $conversation->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
