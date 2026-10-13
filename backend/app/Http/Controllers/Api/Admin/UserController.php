<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Kullanıcı yönetimi (admin). Kullanıcı silinmez, pasif yapılır. Şifre verilmezse geçici şifre üretilir,
 * yanıtta bir kez döner ve ilk girişte değiştirilmesi istenir.
 */
class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->with('department')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->rules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $password = $validated['password'] ?? Str::password(16, symbols: false);

        $user = User::create([
            ...Arr::except($validated, ['password']),
            'email' => mb_strtolower($validated['email']),
            'role' => $validated['role'] ?? User::ROLE_USER,
            'avatar_color' => $validated['avatar_color'] ?? Arr::random(User::AVATAR_COLORS),
            'password' => $password,
            'must_change_password' => true,
        ]);

        Audit::record('user.create', $user, meta: ['email' => $user->email, 'role' => $user->role]);

        return (new UserResource($user->load('department')))
            ->additional(['temporary_password' => isset($validated['password']) ? null : $password])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            ...$this->rules(),
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'reset_password' => ['sometimes', 'boolean'],
        ]);

        // Admin kendini pasif yapamaz veya rolünü düşüremez; aksi halde sistemde admin kalmayabilir.
        if ($user->is($request->user()) && ((isset($validated['is_active']) && ! $validated['is_active']) || (isset($validated['role']) && $validated['role'] !== User::ROLE_ADMIN))) {
            return response()->json(['message' => 'Kendi hesabınızı pasif yapamaz veya rolünüzü düşüremezsiniz.'], 422);
        }

        $temporary = null;
        $changes = Arr::except($validated, ['password', 'reset_password']);

        if (isset($changes['email'])) {
            $changes['email'] = mb_strtolower($changes['email']);
        }

        if (isset($validated['password']) || $request->boolean('reset_password')) {
            $temporary = $validated['password'] ?? Str::password(16, symbols: false);
            $changes += ['password' => $temporary, 'must_change_password' => true];
        }

        $user->fill($changes);
        $dirty = array_keys(Arr::except($user->getDirty(), ['password', 'remember_token']));
        $user->save();

        Audit::record('user.update', $user, meta: ['changed' => $dirty, 'password_reset' => $temporary !== null]);

        return (new UserResource($user->load('department')))
            ->additional(['temporary_password' => isset($validated['password']) ? null : $temporary])
            ->response();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::in(User::ROLES)],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar_color' => ['sometimes', 'nullable', 'string', Rule::in(User::AVATAR_COLORS)],
            'password' => ['sometimes', 'nullable', 'string', 'min:'.config('fortis.password_min_length')],
        ];
    }
}
