<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = isset($payload['date_from'])
            ? Carbon::parse($payload['date_from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $to = isset($payload['date_to'])
            ? Carbon::parse($payload['date_to'])->endOfDay()
            : Carbon::now()->endOfDay();
        $user = $request->user();
        $canViewAll = (bool) $user?->hasAdminPermission('audit.view.all');
        $search = trim((string) ($payload['search'] ?? ''));

        $query = AdminAuditLog::query()
            ->with('actor.adminRole:id,name,slug')
            ->whereBetween('created_at', [$from, $to])
            ->when(($payload['action'] ?? 'all') !== 'all', fn ($q) => $q->where('action', $payload['action']))
            ->when($canViewAll && ($payload['actor_id'] ?? 'all') !== 'all', fn ($q) => $q->where('actor_id', $payload['actor_id']))
            ->when(! $canViewAll, fn ($q) => $q->where('actor_id', $user?->id))
            ->when($canViewAll && ($payload['role'] ?? 'all') !== 'all', fn ($q) => $q->where('actor_role', $payload['role']))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($subQuery) use ($search) {
                    $subQuery->where('actor_name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('subject_name', 'like', "%{$search}%")
                        ->orWhere('subject_type', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%");
                });
            })
            ->latest('created_at');

        $perPage = (int) ($payload['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'scope' => $canViewAll ? 'all' : 'own',
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
