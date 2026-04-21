<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeMenuStoreRequest;
use App\Http\Requests\Admin\ThemeMenuUpdateRequest;
use App\Models\StorefrontMenu;
use App\Models\StorefrontMenuItem;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ThemeMenuController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function index(): JsonResponse
    {
        $menus = StorefrontMenu::query()
            ->with(['items.children'])
            ->orderBy('name')
            ->get()
            ->map(fn (StorefrontMenu $menu) => $this->serializeMenu($menu))
            ->values();

        return response()->json([
            'data' => [
                'menus' => $menus,
                'locations' => $this->themeSettings->menuLocations(),
                'available_links' => $this->themeSettings->availableLinks(),
            ],
        ]);
    }

    public function store(ThemeMenuStoreRequest $request): JsonResponse
    {
        $menu = DB::transaction(function () use ($request): StorefrontMenu {
            $menu = StorefrontMenu::query()->create(Arr::except($request->validated(), ['items']));
            $this->syncItems($menu, $request->validated('items', []));
            return $menu->fresh(['items.children']);
        });

        $this->themeSettings->flush();

        return response()->json([
            'message' => 'Menu created successfully.',
            'data' => $this->serializeMenu($menu),
        ], 201);
    }

    public function show(StorefrontMenu $menu): JsonResponse
    {
        $menu->load(['items.children']);

        return response()->json([
            'data' => $this->serializeMenu($menu),
        ]);
    }

    public function update(ThemeMenuUpdateRequest $request, StorefrontMenu $menu): JsonResponse
    {
        $menu = DB::transaction(function () use ($request, $menu): StorefrontMenu {
            $menu->update(Arr::except($request->validated(), ['items']));
            $this->syncItems($menu, $request->validated('items', []));
            return $menu->fresh(['items.children']);
        });

        $this->themeSettings->flush();

        return response()->json([
            'message' => 'Menu updated successfully.',
            'data' => $this->serializeMenu($menu),
        ]);
    }

    public function destroy(StorefrontMenu $menu): JsonResponse
    {
        $menu->delete();
        $this->themeSettings->flush();

        return response()->json([
            'message' => 'Menu deleted successfully.',
        ]);
    }

    private function syncItems(StorefrontMenu $menu, array $items): void
    {
        $existingIds = $menu->items()->pluck('id')->all();
        $keptIds = [];
        $resolvedIds = [];
        $pending = array_values($items);

        while ($pending !== []) {
            $progress = false;
            $remaining = [];

            foreach ($pending as $itemData) {
                $clientId = $itemData['id'] ?? null;
                $parentReference = $itemData['parent_id'] ?? null;
                $resolvedParentId = null;

                if ($parentReference !== null && $parentReference !== '') {
                    if (isset($resolvedIds[(string) $parentReference])) {
                        $resolvedParentId = $resolvedIds[(string) $parentReference];
                    } elseif (is_numeric($parentReference) && in_array((int) $parentReference, $existingIds, true)) {
                        $resolvedParentId = (int) $parentReference;
                    } else {
                        $remaining[] = $itemData;
                        continue;
                    }
                }

                $item = StorefrontMenuItem::query()->updateOrCreate(
                    [
                        'id' => is_numeric($clientId) ? (int) $clientId : null,
                    ],
                    [
                        'menu_id' => $menu->id,
                        'parent_id' => $resolvedParentId,
                        'title' => $itemData['title'],
                        'type' => $itemData['type'],
                        'reference_id' => $itemData['reference_id'] ?? null,
                        'url' => $itemData['url'] ?? null,
                        'target_blank' => (bool) $itemData['target_blank'],
                        'css_class' => $itemData['css_class'] ?? null,
                        'icon' => $itemData['icon'] ?? null,
                        'sort_order' => (int) $itemData['sort_order'],
                        'is_active' => (bool) $itemData['is_active'],
                    ],
                );

                if ($clientId !== null && $clientId !== '') {
                    $resolvedIds[(string) $clientId] = $item->id;
                }

                $keptIds[] = $item->id;
                $progress = true;
            }

            if (!$progress) {
                foreach ($remaining as $itemData) {
                    $item = StorefrontMenuItem::query()->updateOrCreate(
                        [
                            'id' => is_numeric($itemData['id'] ?? null) ? (int) $itemData['id'] : null,
                        ],
                        [
                            'menu_id' => $menu->id,
                            'parent_id' => null,
                            'title' => $itemData['title'],
                            'type' => $itemData['type'],
                            'reference_id' => $itemData['reference_id'] ?? null,
                            'url' => $itemData['url'] ?? null,
                            'target_blank' => (bool) $itemData['target_blank'],
                            'css_class' => $itemData['css_class'] ?? null,
                            'icon' => $itemData['icon'] ?? null,
                            'sort_order' => (int) $itemData['sort_order'],
                            'is_active' => (bool) $itemData['is_active'],
                        ],
                    );

                    $keptIds[] = $item->id;
                }

                break;
            }

            $pending = $remaining;
        }

        $deleteIds = array_diff($existingIds, $keptIds);

        if ($deleteIds !== []) {
            StorefrontMenuItem::query()
                ->whereIn('id', $deleteIds)
                ->delete();
        }
    }

    private function serializeMenu(StorefrontMenu $menu): array
    {
        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'location' => $menu->location,
            'is_active' => $menu->is_active,
            'items' => $menu->items
                ->whereNull('parent_id')
                ->sortBy('sort_order')
                ->values()
                ->map(fn (StorefrontMenuItem $item) => $this->serializeMenuItem($item))
                ->all(),
        ];
    }

    private function serializeMenuItem(StorefrontMenuItem $item): array
    {
        return [
            'id' => $item->id,
            'parent_id' => $item->parent_id,
            'title' => $item->title,
            'type' => $item->type,
            'reference_id' => $item->reference_id,
            'url' => $item->url,
            'target_blank' => $item->target_blank,
            'css_class' => $item->css_class,
            'icon' => $item->icon,
            'sort_order' => $item->sort_order,
            'is_active' => $item->is_active,
            'children' => $item->children
                ->sortBy('sort_order')
                ->values()
                ->map(fn (StorefrontMenuItem $child) => $this->serializeMenuItem($child))
                ->all(),
        ];
    }
}
