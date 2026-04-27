<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPermissionStoreRequest;
use App\Http\Requests\Admin\AdminPermissionUpdateRequest;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use Illuminate\Http\JsonResponse;

class AdminPermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = AdminPermission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get();

        $roles = AdminRole::query()
            ->with('permissions:id')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(function (AdminRole $role): array {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_system' => $role->is_system,
                    'is_active' => $role->is_active,
                    'permission_ids' => $role->permissions->pluck('id')->values(),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'permissions' => $permissions,
                'roles' => $roles,
            ],
        ]);
    }

    public function store(AdminPermissionStoreRequest $request): JsonResponse
    {
        $permission = AdminPermission::query()->create($request->validated());

        return response()->json([
            'message' => 'Permission created successfully.',
            'data' => $permission,
        ], 201);
    }

    public function update(AdminPermissionUpdateRequest $request, AdminPermission $permission): JsonResponse
    {
        $permission->update($request->validated());

        return response()->json([
            'message' => 'Permission updated successfully.',
            'data' => $permission->fresh(),
        ]);
    }

    public function destroy(AdminPermission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json([
            'message' => 'Permission deleted successfully.',
        ]);
    }

    public function updateRolePermissions(UpdateRolePermissionsRequest $request, AdminRole $role): JsonResponse
    {
        if ($role->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin permissions are permanent and cannot be changed.',
            ], 422);
        }

        $role->permissions()->sync($request->validated('permission_ids', []));

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'data' => [
                'role_id' => $role->id,
                'permission_ids' => $role->permissions()->pluck('admin_permissions.id')->values(),
            ],
        ]);
    }
}
