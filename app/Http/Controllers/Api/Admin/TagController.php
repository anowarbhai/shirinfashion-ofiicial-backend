<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $tag = Tag::create([
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueSlug($validated['slug'] ?? $validated['name']),
        ]);

        return response()->json([
            'message' => 'Tag created successfully.',
            'data' => $tag->loadCount('products'),
        ], 201);
    }

    public function show(Tag $tag): JsonResponse
    {
        return response()->json([
            'data' => $tag->loadCount('products'),
        ]);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $tag->update([
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueSlug($validated['slug'] ?? $validated['name'], $tag->id),
        ]);

        return response()->json([
            'message' => 'Tag updated successfully.',
            'data' => $tag->fresh()->loadCount('products'),
        ]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }

    protected function resolveUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'tag';
        $candidate = $base;
        $suffix = 2;

        while (
            Tag::query()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
