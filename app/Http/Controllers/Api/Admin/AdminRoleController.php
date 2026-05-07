<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminRoleStoreRequest;
use App\Http\Requests\Admin\AdminRoleUpdateRequest;
use App\Models\AdminRole;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    public function __construct(protected AdminAuditLogger $auditLogger)
    {
    }

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

        $this->auditLogger->log($request, 'role.created', "Created role {$role->name}.", $role);

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => $role->loadCount('permissions'),
        ], 201);
    }

    public function update(AdminRoleUpdateRequest $request, AdminRole $role): JsonResponse
    {
        if ($role->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin role is permanent and cannot be edited.',
            ], 422);
        }

        $before = $role->only(['name', 'slug', 'description', 'is_active']);

        if ($role->is_system) {
            $requestData = $request->validated();
            unset($requestData['slug']);
            $role->update($requestData);
        } else {
            $role->update($request->validated());
        }

        $updated = $role->fresh();
        $this->auditLogger->log(
            $request,
            'role.updated',
            "Updated role {$updated->name}.",
            $updated,
            ['before' => $before, 'after' => $updated->only(['name', 'slug', 'description', 'is_active'])],
        );

        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => $updated->loadCount('permissions'),
        ]);
    }

    public function destroy(Request $request, AdminRole $role): JsonResponse
    {
        if ($role->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin role is permanent and cannot be deleted.',
            ], 422);
        }

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 422);
        }

        $metadata = ['role_id' => $role->id, 'name' => $role->name, 'slug' => $role->slug];
        $name = $role->name;
        $role->delete();

        $this->auditLogger->log($request, 'role.deleted', "Deleted role {$name}.", null, $metadata);

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }
}
