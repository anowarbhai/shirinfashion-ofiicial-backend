<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductPageSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('product_page'),
            'persisted' => StorefrontSetting::query()
                ->where('key', 'settings.product_page')
                ->exists(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reviewSettings' => ['required', 'array'],
            'reviewSettings.enableReviews' => ['required', 'boolean'],
            'reviewSettings.showAverageRating' => ['required', 'boolean'],
            'reviewSettings.allowGuestReviews' => ['required', 'boolean'],
            'shippingMethods' => ['required', 'array'],
            'shippingMethods.*.id' => ['required'],
            'shippingMethods.*.name' => ['required', 'string', 'max:255'],
            'shippingMethods.*.description' => ['nullable', 'string', 'max:500'],
            'shippingMethods.*.cost' => ['required', 'numeric', 'min:0'],
            'shippingMethods.*.isActive' => ['required', 'boolean'],
            'freeShippingEnabled' => ['required', 'boolean'],
            'freeShippingThreshold' => ['required', 'string', 'max:40'],
            'paymentMethods' => ['required', 'array'],
            'paymentMethods.*.id' => ['required', 'string', 'max:50'],
            'paymentMethods.*.name' => ['required', 'string', 'max:255'],
            'paymentMethods.*.description' => ['nullable', 'string', 'max:500'],
            'paymentMethods.*.active' => ['required', 'boolean'],
            'taxSettings' => ['required', 'array'],
            'taxSettings.enabled' => ['required', 'boolean'],
            'taxSettings.name' => ['required', 'string', 'max:80'],
            'taxSettings.type' => ['required', Rule::in(['percentage', 'fixed'])],
            'taxSettings.value' => ['required', 'string', 'max:40'],
            'cartDrawerStyle' => ['required', Rule::in(['style-1', 'style-2'])],
            'mobileStickyProductActions' => ['required', 'boolean'],
            'trustBadges' => ['required', 'array'],
            'trustBadges.enabled' => ['required', 'boolean'],
            'trustBadges.items' => ['required', 'array'],
            'trustBadges.items.*.id' => ['required', 'string', 'max:80'],
            'trustBadges.items.*.icon' => ['required', 'string', 'max:80'],
            'trustBadges.items.*.title' => ['required', 'string', 'max:80'],
            'trustBadges.items.*.subtitle' => ['nullable', 'string', 'max:120'],
            'trustBadges.items.*.active' => ['required', 'boolean'],
            'abandonedCheckoutCoupon' => ['required', 'array'],
            'abandonedCheckoutCoupon.enabled' => ['required', 'boolean'],
            'abandonedCheckoutCoupon.couponCode' => ['nullable', 'string', 'max:80'],
            'abandonedCheckoutCoupon.eyebrow' => ['required', 'string', 'max:120'],
            'abandonedCheckoutCoupon.title' => ['required', 'string', 'max:160'],
            'abandonedCheckoutCoupon.message' => ['required', 'string', 'max:500'],
            'abandonedCheckoutCoupon.buttonLabel' => ['required', 'string', 'max:80'],
            'abandonedCheckoutCoupon.closeLabel' => ['required', 'string', 'max:80'],
            'abandonedCheckoutCoupon.countdownMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $saved = $this->settings->saveGroup('product_page', $data, true);

        return response()->json([
            'message' => 'Product page settings saved successfully.',
            'data' => $saved,
        ]);
    }
}
