<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\Auth\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Departman yönetimi (admin): liste, ekle, yeniden adlandır. Kısa ad (slug) ilk adla oluşur ve değişmez.
 */
class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Department::query()->withCount('users')->orderBy('name')->get()->map(self::present(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')]]);

        $slug = Str::slug($validated['name']);
        abort_if($slug === '' || Department::query()->where('slug', $slug)->exists(), 422, 'Bu adla bir departman zaten var.');

        $department = Department::create(['name' => $validated['name'], 'slug' => $slug]);
        Audit::record('department.create', $department, meta: ['name' => $department->name]);

        return response()->json(['data' => self::present($department->loadCount('users'))], 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($department->id)]]);

        $old = $department->name;
        $department->update($validated);
        Audit::record('department.update', $department, meta: ['from' => $old, 'to' => $department->name]);

        return response()->json(['data' => self::present($department->loadCount('users'))]);
    }

    /**
     * @return array{id: int, name: string, slug: string, users_count: int}
     */
    public static function present(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'slug' => $department->slug,
            'users_count' => (int) $department->users_count,
        ];
    }
}
