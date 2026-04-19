<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => StorefrontPage::query()
                ->latest('updated_at')
                ->get()
                ->map(fn (StorefrontPage $page): array => $this->transform($page)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $page = StorefrontPage::query()->create([
            'title' => trim((string) $validated['title']),
            'slug' => $this->resolveUniqueSlug(
                trim((string) ($validated['slug'] ?? '')),
                trim((string) $validated['title']),
            ),
            'status' => $validated['status'],
            'template' => $validated['template'],
            'excerpt' => $this->nullableString($validated['excerpt'] ?? null),
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null),
            'builder_json' => $validated['builder_json'] ?? [],
        ]);

        return response()->json([
            'message' => 'Page created successfully.',
            'data' => $this->transform($page),
        ], 201);
    }

    public function show(StorefrontPage $page): JsonResponse
    {
        return response()->json([
            'data' => $this->transform($page),
        ]);
    }

    public function update(Request $request, StorefrontPage $page): JsonResponse
    {
        $validated = $this->validatePayload($request, $page);

        $page->update([
            'title' => trim((string) $validated['title']),
            'slug' => $this->resolveUniqueSlug(
                trim((string) ($validated['slug'] ?? '')),
                trim((string) $validated['title']),
                $page->id,
            ),
            'status' => $validated['status'],
            'template' => $validated['template'],
            'excerpt' => $this->nullableString($validated['excerpt'] ?? null),
            'seo_title' => $this->nullableString($validated['seo_title'] ?? null),
            'seo_description' => $this->nullableString($validated['seo_description'] ?? null),
            'builder_json' => $validated['builder_json'] ?? [],
        ]);

        return response()->json([
            'message' => 'Page updated successfully.',
            'data' => $this->transform($page->fresh()),
        ]);
    }

    public function destroy(StorefrontPage $page): JsonResponse
    {
        $page->delete();

        return response()->json([
            'message' => 'Page deleted successfully.',
        ]);
    }

    private function validatePayload(Request $request, ?StorefrontPage $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('storefront_pages', 'slug')->ignore($page?->id),
            ],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'template' => ['required', Rule::in(['default', 'story', 'landing'])],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'builder_json' => ['nullable', 'array'],
        ]);
    }

    private function resolveUniqueSlug(string $inputSlug, string $fallbackTitle, ?int $ignoreId = null): string
    {
        $base = Str::slug($inputSlug !== '' ? $inputSlug : $fallbackTitle);
        $base = $base !== '' ? $base : 'page';
        $slug = $base;
        $suffix = 2;

        while (
            StorefrontPage::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }

    private function transform(StorefrontPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'template' => $page->template,
            'excerpt' => $page->excerpt,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'builder_json' => $page->builder_json ?? [],
            'updated_at' => optional($page->updated_at)?->toIso8601String(),
            'created_at' => optional($page->created_at)?->toIso8601String(),
        ];
    }
}
