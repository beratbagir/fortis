<?php

namespace App\Policies;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Sohbette üretilen artifact'ı sahibi (ve admin) görür.
 */
class ArtifactPolicy
{
    public function view(User $user, Artifact $artifact): Response
    {
        return $user->isAdmin() || $artifact->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
