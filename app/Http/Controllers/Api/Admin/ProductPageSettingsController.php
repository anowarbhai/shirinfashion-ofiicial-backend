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
            'shippingMethods.*.id' => ['required', 'integer'],
            'shippingMethods.*.name' => ['required', 'string', 'max:255'],
            'shippingMethods.*.description' => ['nullable', 'string', 'max:500'],
            'shippingMethods.*.cost' => ['required', 'numeric', 'min:0'],
            'shippingMethods.*.isActive' => ['required', 'boolean'],
            'freeShippingEnabled' => ['required', 'boolean'],
            'freeShippingThreshold' => ['required', 'string', 'max:40'],
            'paymentMethods' => ['required', 'array'],
            'paymentMethods.*.id' => ['required', Rule::in(['cod', 'stripe', 'paypal'])],
            'paymentMethods.*.name' => ['required', 'string', 'max:255'],
            'paymentMethods.*.description' => ['nullable', 'string', 'max:500'],
            'paymentMethods.*.active' => ['required', 'boolean'],
            'taxSettings' => ['required', 'array'],
            'taxSettings.enabled' => ['required', 'boolean'],
            'taxSettings.name' => ['required', 'string', 'max:80'],
            'taxSettings.type' => ['required', Rule::in(['percentage', 'fixed'])],
            'taxSettings.value' => ['required', 'string', 'max:40'],
            'cartDrawerStyle' => ['required', Rule::in(['style-1', 'style-2'])],
        ]);

        $saved = $this->settings->saveGroup('product_page', $data, true);

        return response()->json([
            'message' => 'Product page settings saved successfully.',
            'data' => $saved,
        ]);
    }
}
