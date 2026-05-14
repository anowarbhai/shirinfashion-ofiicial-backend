<?php

namespace App\Http\Resources\Mobile;

use App\Http\Resources\Mobile\Concerns\ResolvesMobileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SliderResource extends JsonResource
{
    use ResolvesMobileUrls;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eyebrow' => $this->eyebrow,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->mobileUrl($this->image_url, $request),
            'floating_image_url' => $this->mobileUrl($this->floating_image_url, $request),
            'badge_text' => $this->badge_text,
            'primary_button_label' => $this->primary_button_label,
            'primary_button_url' => $this->primary_button_url,
            'secondary_button_label' => $this->secondary_button_label,
            'secondary_button_url' => $this->secondary_button_url,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
