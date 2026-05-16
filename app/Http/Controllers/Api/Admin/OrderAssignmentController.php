<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderAssignment;
use App\Models\OrderAssignmentHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = OrderAssignment::query()
            ->with(['order.items', 'moderator.user', 'moderator.digitalMarketer', 'assignedBy'])
            ->whereNull('order_item_id')
            ->orderByDesc(
                DB::raw(
                    "(SELECT COALESCE(orders.placed_at, orders.completed_at, orders.last_activity_at, orders.created_at) FROM orders WHERE orders.id = order_assignments.order_id)"
                )
            )
            ->orderByDesc('id');
        $user = $request->user();

        if (
            $user
            && ! $user->hasAdminPermission('system.everything')
            && ! $user->hasAdminPermission('moderator.view_all_moderator_orders')
        ) {
            $moderator = $user->moderatorProfile()->first();

            if ($moderator && $user->hasAdminPermission('moderator.view_assigned_orders')) {
                $query->where('moderator_id', $moderator->id);
            } elseif ($user->hasAdminPermission('moderator.manage_moderators')) {
                $query->whereIn('moderator_id', $user->managedModerators()->pluck('id'));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('manager_id')) {
            $query->whereHas('moderator', fn ($moderatorQuery) => $moderatorQuery
                ->where('digital_marketer_id', (int) $request->query('manager_id')));
        }

        if ($request->filled('moderator_id')) {
            if ($request->query('moderator_id') === 'unassigned') {
                $query->whereNull('moderator_id');
            } else {
                $query->where('moderator_id', (int) $request->query('moderator_id'));
            }
        }

        if ($request->filled('order_status_type')) {
            $query->where('order_status_type', $request->query('order_status_type'));
        }

        if ($request->filled('assigned_type')) {
            $query->where('assigned_type', $request->query('assigned_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(max((int) $request->integer('per_page', 30), 1), 100);

        return response()->json(['data' => $query->paginate($perPage)]);
    }

    public function history(int $orderId): JsonResponse
    {
        return response()->json([
            'data' => OrderAssignmentHistory::query()
                ->with(['previousModerator.user', 'newModerator.user', 'changedBy'])
                ->where('order_id', $orderId)
                ->latest()
                ->get(),
        ]);
    }
}
