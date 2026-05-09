<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Moderator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModeratorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Moderator::query()
            ->with(['user:id,name,email,phone,status,admin_role_id', 'digitalMarketer:id,name,email,phone'])
            ->withCount([
                'assignments as assigned_processing_count' => fn ($query) => $query
                    ->where('order_status_type', 'processing')
                    ->where('status', 'assigned'),
                'assignments as assigned_incomplete_count' => fn ($query) => $query
                    ->where('order_status_type', 'incomplete')
                    ->where('status', 'assigned'),
            ])
            ->orderBy('assignment_order')
            ->orderBy('id');

        $user = $request->user();
        if (
            $user
            && ! $user->hasAdminPermission('system.everything')
            && ! $user->hasAdminPermission('moderator.view_all_moderator_orders')
            && $user->hasAdminPermission('moderator.manage_moderators')
        ) {
            $query->where('digital_marketer_id', $user->id);
        }

        return response()->json([
            'data' => $query->get(),
            'users' => User::query()
                ->where(function ($query): void {
                    $query->where('role', 'admin')->orWhereNotNull('admin_role_id');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'status', 'admin_role_id']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:moderators,user_id'],
            'digital_marketer_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'assignment_order' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $this->authorizeManager($request, $payload['digital_marketer_id'] ?? null);

        $moderator = Moderator::query()->create($payload);

        return response()->json([
            'message' => 'Moderator created successfully.',
            'data' => $moderator->load(['user', 'digitalMarketer']),
        ], 201);
    }

    public function update(Request $request, Moderator $moderator): JsonResponse
    {
        $payload = $request->validate([
            'digital_marketer_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'assignment_order' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $this->authorizeManager($request, $payload['digital_marketer_id'] ?? null);

        if (
            $moderator->status !== $payload['status']
            && ! (bool) $request->user()?->hasAdminPermission('moderator.activate_deactivate_moderator')
        ) {
            abort(403, 'You do not have permission to activate or deactivate moderators.');
        }

        $moderator->update($payload);

        return response()->json([
            'message' => 'Moderator updated successfully.',
            'data' => $moderator->fresh(['user', 'digitalMarketer']),
        ]);
    }

    protected function authorizeManager(Request $request, ?int $digitalMarketerId): void
    {
        $user = $request->user();

        abort_unless((bool) $user?->hasAdminPermission('moderator.manage_moderators'), 403);

        if ($user->hasAdminPermission('system.everything') || $user->hasAdminPermission('moderator.view_all_moderator_orders')) {
            return;
        }

        abort_unless($digitalMarketerId === $user->id, 403, 'You can only manage moderators under yourself.');
    }
}
