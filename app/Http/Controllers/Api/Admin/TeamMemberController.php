<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\User;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    public function __construct(protected AdminAuditLogger $auditLogger)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('role', 'admin')
                ->with('adminRole:id,name,slug')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'admin_role_id' => ['required', 'integer', 'exists:admin_roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending', 'blocked'])],
        ]);

        $adminRole = AdminRole::query()->findOrFail($validated['admin_role_id']);

        $member = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
            'admin_role_id' => $adminRole->id,
            'status' => $validated['status'],
            'marketing_opt_in' => false,
        ])->load('adminRole:id,name,slug');

        $this->auditLogger->log(
            $request,
            'team.member.created',
            "Created admin account {$member->name}.",
            $member,
            ['role' => $member->adminRole?->name, 'status' => $member->status],
        );

        return response()->json([
            'message' => 'Admin user created successfully.',
            'data' => $member,
        ], 201);
    }

    public function show(User $teamMember): JsonResponse
    {
        abort_unless($teamMember->role === 'admin', 404);

        return response()->json([
            'data' => $teamMember->load('adminRole:id,name,slug'),
        ]);
    }

    public function update(Request $request, User $teamMember): JsonResponse
    {
        abort_unless($teamMember->role === 'admin', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teamMember->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($teamMember->id)],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'admin_role_id' => ['required', 'integer', 'exists:admin_roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending', 'blocked'])],
        ]);

        if ($teamMember->adminRole?->slug === 'super-admin') {
            unset($validated['admin_role_id'], $validated['status']);
        }

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $before = $teamMember->only(['name', 'email', 'phone', 'admin_role_id', 'status']);
        $teamMember->update($validated);
        $updated = $teamMember->fresh('adminRole:id,name,slug');

        $this->auditLogger->log(
            $request,
            'team.member.updated',
            "Updated admin account {$updated->name}.",
            $updated,
            [
                'before' => $before,
                'after' => $updated->only(['name', 'email', 'phone', 'admin_role_id', 'status']),
            ],
        );

        return response()->json([
            'message' => 'Admin user updated successfully.',
            'data' => $updated,
        ]);
    }

    public function destroy(Request $request, User $teamMember): JsonResponse
    {
        abort_unless($teamMember->role === 'admin', 404);

        if ($teamMember->adminRole?->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin user cannot be deleted.',
            ], 422);
        }

        $name = $teamMember->name;
        $role = $teamMember->adminRole?->name;
        $id = $teamMember->id;
        $teamMember->delete();

        $this->auditLogger->log(
            $request,
            'team.member.deleted',
            "Deleted admin account {$name}.",
            null,
            ['deleted_user_id' => $id, 'deleted_user_name' => $name, 'role' => $role],
        );

        return response()->json([
            'message' => 'Admin user deleted successfully.',
        ]);
    }
}
