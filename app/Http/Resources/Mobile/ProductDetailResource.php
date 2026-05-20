<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;

class ProductDetailResource extends ProductSummaryResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'highlights' => $this->highlights ?? [],
            'ingredients' => $this->ingredients ?? [],
            'skin_types' => $this->skin_types ?? [],
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'attributes' => ProductAttributeResource::collection(
                $this->whenLoaded('attributeTerms', fn () => $this->attributeTerms, collect())
            ),
            'reviews' => ProductReviewResource::collection(
                $this->whenLoaded('reviews', fn () => $this->reviews, collect())
            ),
        ];
    }
}
