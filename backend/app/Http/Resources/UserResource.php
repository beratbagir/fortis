<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'title' => $this->title,
            'role' => $this->role,
            'avatar_color' => $this->avatar_color,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'theme' => $this->theme,
            'locale' => $this->locale ?? config('app.locale'),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'department' => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'slug' => $this->department->slug,
            ],
            'abilities' => [
                'build' => $this->canBuild(),
                'admin' => $this->isAdmin(),
            ],
        ];
    }
}
