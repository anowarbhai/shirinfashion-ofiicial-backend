<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Slider::query()
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $slider = Slider::create($this->validated($request));

        return response()->json([
            'message' => 'Slider created successfully.',
            'data' => $slider,
        ], 201);
    }

    public function show(Slider $slider): JsonResponse
    {
        return response()->json([
            'data' => $slider,
        ]);
    }

    public function update(Request $request, Slider $slider): JsonResponse
    {
        $slider->update($this->validated($request));

        return response()->json([
            'message' => 'Slider updated successfully.',
            'data' => $slider->fresh(),
        ]);
    }

    public function destroy(Slider $slider): JsonResponse
    {
        $slider->delete();

        return response()->json([
            'message' => 'Slider deleted successfully.',
        ]);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'eyebrow' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string'],
            'image_url' => ['required', 'string', 'max:2048'],
            'floating_image_url' => ['nullable', 'string', 'max:2048'],
            'badge_text' => ['nullable', 'string', 'max:255'],
            'primary_button_label' => ['nullable', 'string', 'max:255'],
            'primary_button_url' => ['nullable', 'string', 'max:2048'],
            'secondary_button_label' => ['nullable', 'string', 'max:255'],
            'secondary_button_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
