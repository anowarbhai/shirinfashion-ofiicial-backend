<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminRoleStoreRequest;
use App\Http\Requests\Admin\AdminRoleUpdateRequest;
use App\Models\AdminRole;
use Illuminate\Http\JsonResponse;

class AdminRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = AdminRole::query()
            ->withCount('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles,
        ]);
    }

    public function store(AdminRoleStoreRequest $request): JsonResponse
    {
        $role = AdminRole::query()->create([
            ...$request->validated(),
            'is_system' => false,
        ]);

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => $role->loadCount('permissions'),
        ], 201);
    }

    public function update(AdminRoleUpdateRequest $request, AdminRole $role): JsonResponse
    {
        if ($role->is_system) {
            $requestData = $request->validated();
            unset($requestData['slug']);
            $role->update($requestData);
        } else {
            $role->update($request->validated());
        }

        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => $role->fresh()->loadCount('permissions'),
        ]);
    }

    public function destroy(AdminRole $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }
}
