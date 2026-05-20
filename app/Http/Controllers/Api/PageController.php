<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontPage;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    public function sitemap(): JsonResponse
    {
        return response()->json([
            'data' => StorefrontPage::query()
                ->select(['slug', 'updated_at'])
                ->where('status', 'published')
                ->orderBy('slug')
                ->get()
                ->map(fn (StorefrontPage $page): array => [
                    'slug' => $page->slug,
                    'updated_at' => optional($page->updated_at)?->toIso8601String(),
                ]),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = StorefrontPage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json([
            'data' => [
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
            ],
        ]);
    }
}
