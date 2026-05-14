<?php

namespace App\Http\Resources\Mobile;

use App\Http\Resources\Mobile\Concerns\ResolvesMobileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use ResolvesMobileUrls;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
                'slug' => $this->parent->slug,
            ] : null),
            'image_url' => $this->mobileUrl($this->image_url, $request),
            'description' => $this->description,
            'is_featured' => (bool) $this->is_featured,
            'products_count' => (int) ($this->products_count ?? 0),
        ];
    }
}
