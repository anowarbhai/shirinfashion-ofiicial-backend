<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBlogController extends Controller
{
    public function posts(Request $request): JsonResponse
    {
        $query = BlogPost::query()
            ->with('category');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if ($categorySlug = $request->query('category')) {
            $query->whereHas('category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        $posts = $query->latest('id')->paginate($request->query('per_page', 9));

        return response()->json($posts);
    }

    public function show(string $slug): JsonResponse
    {
        $decodedSlug = urldecode($slug);

        $query = BlogPost::query()->with('category');

        $post = (clone $query)->where('slug', $slug)->first()
            ?? (clone $query)->where('slug', $decodedSlug)->first()
            ?? (clone $query)->whereRaw('LOWER(slug) = ?', [strtolower($slug)])->first()
            ?? (clone $query)->whereRaw('LOWER(slug) = ?', [strtolower($decodedSlug)])->first()
            ?? (clone $query)->where('slug', 'like', "%{$slug}%")->first();

        if (!$post && is_numeric($slug)) {
            $post = (clone $query)->where('id', (int) $slug)->first();
        }

        if (!$post) {
            return response()->json(['message' => 'Blog post not found.'], 404);
        }

        $post->increment('views_count');

        $relatedPosts = BlogPost::query()
            ->with('category')
            ->where('id', '!=', $post->id)
            ->when($post->category_id, function ($q) use ($post) {
                $q->where('category_id', $post->category_id);
            })
            ->latest('id')
            ->limit(3)
            ->get();

        return response()->json([
            'data' => $post,
            'related' => $relatedPosts,
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = BlogCategory::query()
            ->where('is_active', true)
            ->withCount('posts')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }
}
