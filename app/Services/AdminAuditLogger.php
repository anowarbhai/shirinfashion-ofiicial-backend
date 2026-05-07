<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class AdminAuditLogger
{
    public function log(
        Request $request,
        string $action,
        string $description,
        ?object $subject = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        try {
            $actor ??= $request->user() instanceof User ? $request->user() : null;
            $actorRole = $actor?->adminRole?->name ?? ($actor?->role ? str($actor->role)->title()->toString() : null);

            AdminAuditLog::query()->create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_role' => $actorRole,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->id ?? null,
                'subject_name' => $subject?->name ?? $subject?->title ?? $subject?->order_number ?? null,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            AdminAuditLog::query()
                ->where('created_at', '<', Carbon::now()->subDays(30))
                ->delete();
        } catch (Throwable) {
            // Audit logging should never block the admin action itself.
        }
    }
}
