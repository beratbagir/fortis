<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Raporu sahibi (ve admin) görür ve indirir.
 */
class ReportPolicy
{
    public function view(User $user, Report $report): Response
    {
        return $user->isAdmin() || $report->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
