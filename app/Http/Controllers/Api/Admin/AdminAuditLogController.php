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
        ]);

        $from = isset($payload['date_from'])
            ? Carbon::parse($payload['date_from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $to = isset($payload['date_to'])
            ? Carbon::parse($payload['date_to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $logs = AdminAuditLog::query()
            ->with('actor.adminRole:id,name,slug')
            ->whereBetween('created_at', [$from, $to])
            ->when(($payload['action'] ?? 'all') !== 'all', fn ($query) => $query->where('action', $payload['action']))
            ->when(($payload['actor_id'] ?? 'all') !== 'all', fn ($query) => $query->where('actor_id', $payload['actor_id']))
            ->when(($payload['role'] ?? 'all') !== 'all', fn ($query) => $query->where('actor_role', $payload['role']))
            ->latest('created_at')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $logs,
        ]);
    }
}
