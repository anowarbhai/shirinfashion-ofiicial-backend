<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id' => $this->attribute->id,
                'name' => $this->attribute->name,
                'slug' => $this->attribute->slug,
            ] : null),
        ];
    }
}

