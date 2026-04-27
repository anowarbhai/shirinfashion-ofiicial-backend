<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
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

        $teamMember->update($validated);

        return response()->json([
            'message' => 'Admin user updated successfully.',
            'data' => $teamMember->fresh('adminRole:id,name,slug'),
        ]);
    }

    public function destroy(User $teamMember): JsonResponse
    {
        abort_unless($teamMember->role === 'admin', 404);

        if ($teamMember->adminRole?->slug === 'super-admin') {
            return response()->json([
                'message' => 'Super Admin user cannot be deleted.',
            ], 422);
        }

        $teamMember->delete();

        return response()->json([
            'message' => 'Admin user deleted successfully.',
        ]);
    }
}
