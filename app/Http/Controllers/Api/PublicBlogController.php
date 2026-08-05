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
            ->published()
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

        $posts = $query->latest('published_at')->paginate($request->query('per_page', 9));

        return response()->json($posts);
    }

    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::query()
            ->published()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        $post->increment('views_count');

        $relatedPosts = BlogPost::query()
            ->published()
            ->with('category')
            ->where('id', '!=', $post->id)
            ->when($post->category_id, function ($q) use ($post) {
                $q->where('category_id', $post->category_id);
            })
            ->latest('published_at')
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
            ->whereHas('posts', function ($q) {
                $q->published();
            })
            ->withCount(['posts' => function ($q) {
                $q->published();
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }
}
