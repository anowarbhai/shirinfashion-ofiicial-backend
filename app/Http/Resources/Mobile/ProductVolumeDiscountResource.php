<?php

namespace App\Http\Resources\Mobile;

use App\Http\Resources\Mobile\Concerns\ResolvesMobileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVolumeDiscountResource extends JsonResource
{
    use ResolvesMobileUrls;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'flat_price' => (float) $this->flat_price,
            'extra_unit_price' => $this->extra_unit_price === null ? null : (float) $this->extra_unit_price,
            'label' => $this->label,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'free_product' => $this->whenLoaded('freeProduct', fn () => $this->freeProduct ? [
                'id' => $this->freeProduct->id,
                'name' => $this->freeProduct->name,
                'slug' => $this->freeProduct->slug,
                'thumbnail' => $this->mobileUrl($this->freeProduct->gallery[0] ?? null, $request),
            ] : null),
        ];
    }
}
