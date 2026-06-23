<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class ProductPageSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->storefrontSettings(),
        ]);
    }

    private function storefrontSettings(): array
    {
        $settings = $this->settings->getGroup('product_page');
        $gatewaySettings = $this->settings->getGroup('payment_gateway');
        $sslCommerzReady = $this->sslCommerzIsReady($gatewaySettings);

        $settings['paymentMethods'] = collect($settings['paymentMethods'] ?? [])
            ->map(function (array $method) use ($sslCommerzReady): array {
                if (($method['id'] ?? null) === 'sslcommerz') {
                    $method['active'] = (bool) ($method['active'] ?? false) && $sslCommerzReady;
                }

                return $method;
            })
            ->values()
            ->all();

        return $settings;
    }

    private function sslCommerzIsReady(array $settings): bool
    {
        return filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && filled($settings['store_id'] ?? null)
            && filled($settings['store_password'] ?? null);
    }
}
