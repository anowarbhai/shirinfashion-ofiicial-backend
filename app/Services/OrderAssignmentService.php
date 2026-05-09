<?php

namespace App\Services;

use App\Models\AssignmentCounter;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderAssignmentHistory;
use App\Models\ProductModeratorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAssignmentService
{
    public function assignOrder(Order $order): OrderAssignment
    {
        $statusType = $this->statusTypeForOrder($order);

        return $this->assignOrderByStatus($order, $statusType);
    }

    public function assignOrderByStatus(Order $order, string $statusType): OrderAssignment
    {
        return DB::transaction(function () use ($order, $statusType): OrderAssignment {
            $order = Order::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            $existing = $order->assignments()
                ->whereNull('order_item_id')
                ->whereIn('status', ['assigned', 'pending_manual_review', 'unassigned'])
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->order_status_type === $statusType && $statusType === 'incomplete') {
                return $existing;
            }

            $productAssignment = $this->assignByProduct($order, $statusType);

            if ($productAssignment) {
                return $productAssignment;
            }

            return $this->assignByRoundRobin($order, $statusType);
        });
    }

    public function assignProcessingOrder(Order $order): OrderAssignment
    {
        return $this->assignOrderByStatus($order, 'processing');
    }

    public function assignIncompleteOrder(Order $order): OrderAssignment
    {
        return $this->assignOrderByStatus($order, 'incomplete');
    }

    public function keepExistingModeratorForStatus(Order $order, string $statusType): ?OrderAssignment
    {
        return DB::transaction(function () use ($order, $statusType): ?OrderAssignment {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $assignment = $order->assignments()
                ->whereNull('order_item_id')
                ->where('status', 'assigned')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $assignment || ! $assignment->moderator_id) {
                return null;
            }

            $assignment->update([
                'order_status_type' => $statusType,
                'note' => trim(($assignment->note ? $assignment->note.' ' : '')."Order status moved to {$statusType}."),
            ]);

            $order->forceFill([
                'assigned_moderator_id' => $assignment->moderator?->user_id,
                'assignment_status' => 'assigned',
                'assignment_type' => $assignment->assigned_type,
                'assignment_status_type' => $statusType,
            ])->save();

            $this->createAssignmentHistory(
                $order,
                $assignment->moderator,
                $assignment->moderator,
                $statusType,
                'status_converted',
                null,
                "Kept existing moderator when order moved to {$statusType}.",
            );

            return $assignment->fresh(['moderator.user']);
        });
    }

    public function assignByProduct(Order $order, string $statusType): ?OrderAssignment
    {
        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return null;
        }

        /** @var EloquentCollection<int, ProductModeratorAssignment> $productAssignments */
        $productAssignments = ProductModeratorAssignment::query()
            ->with('moderator.user')
            ->whereIn('product_id', $productIds)
            ->get();

        if ($productAssignments->isEmpty()) {
            return null;
        }

        $activeModerators = $productAssignments
            ->map(fn (ProductModeratorAssignment $assignment) => $assignment->moderator)
            ->filter(fn (?Moderator $moderator): bool => $this->moderatorCanReceive($moderator))
            ->unique('id')
            ->values();

        if ($activeModerators->count() === 1) {
            return $this->writeAssignment(
                $order,
                $statusType,
                $activeModerators->first(),
                'product_specific',
                'assigned',
                null,
                'Product-specific moderator assignment.',
            );
        }

        if ($activeModerators->count() > 1) {
            return $this->markPendingManualReview(
                $order,
                'Order contains products assigned to different moderators.',
                $statusType,
            );
        }

        return $this->markPendingManualReview(
            $order,
            'Product-specific moderator is inactive or unavailable.',
            $statusType,
            'moderator_inactive',
        );
    }

    public function assignByRoundRobin(Order $order, string $statusType): OrderAssignment
    {
        $moderators = Moderator::query()
            ->active()
            ->orderBy('assignment_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($moderators->isEmpty()) {
            return $this->markPendingManualReview($order, 'No active moderators are available.', $statusType);
        }

        $counter = AssignmentCounter::query()
            ->where('order_status_type', $statusType)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            $counter = AssignmentCounter::query()->create([
                'order_status_type' => $statusType,
                'scope_type' => null,
                'scope_id' => null,
            ]);
            $counter->refresh();
        }

        $nextModerator = $this->getNextActiveModerator($statusType, $moderators, $counter->last_moderator_id);
        $counter->update(['last_moderator_id' => $nextModerator->id]);

        return $this->writeAssignment(
            $order,
            $statusType,
            $nextModerator,
            'auto_round_robin',
            'assigned',
            null,
            "Auto assigned by {$statusType} round-robin queue.",
        );
    }

    /**
     * @param EloquentCollection<int, Moderator>|null $moderators
     */
    public function getNextActiveModerator(
        string $statusType,
        ?EloquentCollection $moderators = null,
        ?int $lastModeratorId = null,
    ): Moderator {
        $moderators ??= Moderator::query()
            ->active()
            ->orderBy('assignment_order')
            ->orderBy('id')
            ->get();

        if ($moderators->isEmpty()) {
            throw ValidationException::withMessages([
                'moderator' => ['No active moderators are available.'],
            ]);
        }

        if (! $lastModeratorId) {
            return $moderators->first();
        }

        $lastIndex = $moderators->search(fn (Moderator $moderator): bool => $moderator->id === $lastModeratorId);

        if ($lastIndex === false) {
            return $moderators->first();
        }

        return $moderators->get(((int) $lastIndex + 1) % $moderators->count());
    }

    public function reassignOrder(int $orderId, int $newModeratorId, ?int $changedBy, ?string $note = null): OrderAssignment
    {
        return DB::transaction(function () use ($orderId, $newModeratorId, $changedBy, $note): OrderAssignment {
            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($orderId);
            $moderator = Moderator::query()->active()->lockForUpdate()->findOrFail($newModeratorId);

            return $this->writeAssignment(
                $order,
                $this->statusTypeForOrder($order),
                $moderator,
                'manual_reassign',
                'assigned',
                $changedBy,
                $note ?: 'Manually reassigned.',
            );
        });
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, OrderAssignment>
     */
    public function bulkReassignOrders(array $orderIds, int $newModeratorId, ?int $changedBy, ?string $note = null): array
    {
        $assignments = [];

        foreach (array_values(array_unique($orderIds)) as $orderId) {
            $assignments[] = $this->reassignOrder((int) $orderId, $newModeratorId, $changedBy, $note);
        }

        return $assignments;
    }

    public function markPendingManualReview(
        Order $order,
        string $reason,
        ?string $statusType = null,
        string $changeType = 'pending_manual_review',
    ): OrderAssignment {
        return $this->writeAssignment(
            $order,
            $statusType ?: $this->statusTypeForOrder($order),
            null,
            'auto_round_robin',
            'pending_manual_review',
            null,
            $reason,
            $changeType,
        );
    }

    public function createAssignmentHistory(
        Order $order,
        ?Moderator $previousModerator,
        ?Moderator $newModerator,
        string $statusType,
        string $changeType,
        ?int $changedBy = null,
        ?string $note = null,
    ): void {
        OrderAssignmentHistory::query()->create([
            'order_id' => $order->id,
            'previous_moderator_id' => $previousModerator?->id,
            'new_moderator_id' => $newModerator?->id,
            'changed_by' => $changedBy,
            'order_status_type' => $statusType,
            'change_type' => $changeType,
            'note' => $note,
        ]);
    }

    protected function writeAssignment(
        Order $order,
        string $statusType,
        ?Moderator $moderator,
        string $assignedType,
        string $assignmentStatus,
        ?int $changedBy = null,
        ?string $note = null,
        ?string $changeType = null,
    ): OrderAssignment {
        $previousAssignment = $order->assignments()
            ->whereNull('order_item_id')
            ->latest()
            ->lockForUpdate()
            ->first();
        $previousModerator = $previousAssignment?->moderator;

        if ($previousAssignment && $previousAssignment->moderator_id !== $moderator?->id) {
            $previousAssignment->update(['status' => 'reassigned']);
        }

        $assignment = OrderAssignment::query()->updateOrCreate(
            ['order_id' => $order->id, 'order_item_id' => null],
            [
                'moderator_id' => $moderator?->id,
                'order_status_type' => $statusType,
                'assigned_by' => $changedBy,
                'assigned_type' => $assignedType,
                'status' => $assignmentStatus,
                'note' => $note,
            ],
        );

        $order->forceFill([
            'assigned_moderator_id' => $moderator?->user_id,
            'assignment_status' => $assignmentStatus,
            'assignment_type' => $assignedType,
            'assignment_status_type' => $statusType,
        ])->save();

        $this->createAssignmentHistory(
            $order,
            $previousModerator,
            $moderator,
            $statusType,
            $changeType ?: $this->changeTypeFor($assignedType, $assignmentStatus),
            $changedBy,
            $note,
        );

        return $assignment->fresh(['moderator.user', 'assignedBy']);
    }

    protected function statusTypeForOrder(Order $order): string
    {
        return $order->status === 'incomplete' ? 'incomplete' : 'processing';
    }

    protected function moderatorCanReceive(?Moderator $moderator): bool
    {
        return $moderator !== null
            && $moderator->status === 'active'
            && $moderator->user !== null
            && ($moderator->user->status ?? 'active') === 'active';
    }

    protected function changeTypeFor(string $assignedType, string $assignmentStatus): string
    {
        if ($assignmentStatus === 'pending_manual_review') {
            return 'pending_manual_review';
        }

        return match ($assignedType) {
            'product_specific' => 'product_specific',
            'manual_reassign' => 'manual_reassign',
            default => 'auto_assign',
        };
    }
}
