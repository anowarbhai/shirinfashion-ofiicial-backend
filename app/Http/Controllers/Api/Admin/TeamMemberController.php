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
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'admin_role_id' => ['required', 'integer', 'exists:admin_roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending', 'blocked'])],
        ]);

        $adminRole = AdminRole::query()->findOrFail($validated['admin_role_id']);

        $member = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
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

    protected function resolveTeamMember(mixed $param): User
    {
        if ($param instanceof User && $param->exists && $param->id) {
            return $param;
        }

        $id = is_numeric($param) ? (int) $param : null;
        if (! $id) {
            $routeParam = request()->route('team_member') ?? request()->route('teamMember');
            $id = is_numeric($routeParam) ? (int) $routeParam : null;
        }

        return User::query()
            ->where('role', 'admin')
            ->findOrFail($id);
    }

    public function show(mixed $teamMember): JsonResponse
    {
        $member = $this->resolveTeamMember($teamMember);

        return response()->json([
            'data' => $member->load('adminRole:id,name,slug'),
        ]);
    }

    public function update(Request $request, mixed $teamMember): JsonResponse
    {
        $member = $this->resolveTeamMember($teamMember);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($member->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'admin_role_id' => ['required', 'integer', 'exists:admin_roles,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending', 'blocked'])],
        ]);

        if ($member->adminRole?->slug === 'super-admin') {
            unset($validated['admin_role_id'], $validated['status']);
        }

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
            $validated['password_set_at'] = now();
        } else {
            unset($validated['password']);
        }

        $before = $member->only(['name', 'email', 'phone', 'admin_role_id', 'status']);
        $member->update($validated);
        $updated = $member->fresh('adminRole:id,name,slug');

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

    public function destroy(Request $request, mixed $teamMember): JsonResponse
    {
        $member = $this->resolveTeamMember($teamMember);

        if ($member->adminRole?->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin user cannot be deleted.',
            ], 422);
        }

        $name = $member->name;
        $role = $member->adminRole?->name;
        $id = $member->id;
        $member->delete();

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
