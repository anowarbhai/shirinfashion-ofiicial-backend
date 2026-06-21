<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Moderator;
use App\Models\Product;
use App\Models\ProductModeratorAssignment;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductModeratorAssignmentController extends Controller
{
    public function __construct(protected AdminAuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAll = (bool) ($user?->hasAdminPermission('system.everything') || $user?->hasAdminPermission('moderator.view_all_moderator_orders'));
        $visibleModeratorIds = $canViewAll ? collect() : $this->visibleModeratorIds($request);

        return response()->json([
            'data' => Product::query()
                ->with(['moderatorAssignments.moderator.user:id,name,email,phone,status'])
                ->when(
                    ! $canViewAll,
                    fn ($query) => $query->whereHas(
                        'moderatorAssignments',
                        fn ($assignmentQuery) => $assignmentQuery->whereIn('moderator_id', $visibleModeratorIds),
                    ),
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'sku',
                    'is_active',
                    'hide_from_storefront',
                    'campaign_facebook_pixel_ids',
                    'campaign_google_tag_ids',
                ]),
            'moderators' => Moderator::query()
                ->active()
                ->with('user:id,name,email,phone,status')
                ->when(! $canViewAll, fn ($query) => $query->whereIn('id', $visibleModeratorIds))
                ->orderBy('assignment_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);
        $previousModeratorIds = $product->moderatorAssignments()
            ->pluck('moderator_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $payload = $request->validate([
            'moderator_id' => ['nullable', 'integer', 'exists:moderators,id'],
            'moderator_ids' => ['nullable', 'array'],
            'moderator_ids.*' => ['integer', 'exists:moderators,id'],
        ]);

        $moderatorIds = collect($payload['moderator_ids'] ?? [])
            ->when(
                isset($payload['moderator_id']) && $payload['moderator_id'],
                fn ($ids) => $ids->push((int) $payload['moderator_id']),
            )
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $this->authorizeModeratorIds($request, $moderatorIds->all());

        if ($moderatorIds->isEmpty()) {
            ProductModeratorAssignment::query()->where('product_id', $product->id)->delete();

            $this->auditLogger->log(
                $request,
                'product.moderator_assignments_changed',
                "Removed all moderator assignments from {$product->name}.",
                $product,
                ['before_moderator_ids' => $previousModeratorIds, 'after_moderator_ids' => []],
            );

            return response()->json([
                'message' => 'Product moderator assignment removed successfully.',
            ]);
        }

        ProductModeratorAssignment::query()
            ->where('product_id', $product->id)
            ->whereNotIn('moderator_id', $moderatorIds->all())
            ->delete();

        foreach ($moderatorIds as $moderatorId) {
            ProductModeratorAssignment::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'moderator_id' => $moderatorId,
                ],
                [
                    'assigned_by' => $request->user()?->id,
                ],
            );
        }

        $newModeratorIds = $moderatorIds->sort()->values()->all();
        $this->auditLogger->log(
            $request,
            'product.moderator_assignments_changed',
            "Updated moderator assignments for {$product->name}.",
            $product,
            [
                'before_moderator_ids' => $previousModeratorIds,
                'after_moderator_ids' => $newModeratorIds,
            ],
        );

        return response()->json([
            'message' => 'Product moderator assignments saved successfully.',
            'data' => $product->fresh('moderatorAssignments.moderator.user'),
        ]);
    }

    protected function visibleModeratorIds(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return collect([-1]);
        }

        return collect()
            ->when($user->moderatorProfile()->exists(), fn ($ids) => $ids->push($user->moderatorProfile()->value('id')))
            ->merge($user->managedModerators()->pluck('id'))
            ->filter()
            ->unique()
            ->values();
    }

    protected function authorizeProductAccess(Request $request, Product $product): void
    {
        $user = $request->user();

        if ($user?->hasAdminPermission('system.everything') || $user?->hasAdminPermission('moderator.view_all_moderator_orders')) {
            return;
        }

        $visibleModeratorIds = $this->visibleModeratorIds($request);

        abort_unless(
            $product->moderatorAssignments()->whereIn('moderator_id', $visibleModeratorIds)->exists(),
            403,
            'You can only manage products assigned to your moderator queue.',
        );
    }

    /**
     * @param array<int, int> $moderatorIds
     */
    protected function authorizeModeratorIds(Request $request, array $moderatorIds): void
    {
        if ($moderatorIds === []) {
            return;
        }

        $user = $request->user();

        if ($user?->hasAdminPermission('system.everything') || $user?->hasAdminPermission('moderator.view_all_moderator_orders')) {
            return;
        }

        $visibleModeratorIds = $this->visibleModeratorIds($request)->all();

        abort_unless(
            collect($moderatorIds)->every(fn (int $moderatorId): bool => in_array($moderatorId, $visibleModeratorIds, true)),
            403,
            'You can only assign products to moderators in your queue.',
        );
    }
}
