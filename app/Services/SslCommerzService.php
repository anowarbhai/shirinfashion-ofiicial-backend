<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SslCommerzService
{
    public function initiate(Order $order): array
    {
        $this->ensureConfigured();

        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $productNames = $items
            ->pluck('product_name')
            ->filter()
            ->take(3)
            ->implode(', ');

        $payload = [
            'store_id' => config('sslcommerz.store_id'),
            'store_passwd' => config('sslcommerz.store_password'),
            'total_amount' => number_format((float) $order->grand_total, 2, '.', ''),
            'currency' => config('sslcommerz.currency', 'BDT'),
            'tran_id' => $order->order_number,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'ipn_url' => $this->callbackUrl('ipn'),
            'cus_name' => $order->customer_name ?: 'Customer',
            'cus_email' => $order->email ?: 'customer@shirinfashion.com.bd',
            'cus_add1' => $address['address'] ?? 'Bangladesh',
            'cus_city' => $address['city'] ?? 'Dhaka',
            'cus_state' => $address['city'] ?? 'Dhaka',
            'cus_postcode' => '1200',
            'cus_country' => $address['country'] ?? 'Bangladesh',
            'cus_phone' => $order->phone,
            'shipping_method' => 'YES',
            'ship_name' => $order->customer_name ?: 'Customer',
            'ship_add1' => $address['address'] ?? 'Bangladesh',
            'ship_city' => $address['city'] ?? 'Dhaka',
            'ship_state' => $address['city'] ?? 'Dhaka',
            'ship_postcode' => '1200',
            'ship_country' => $address['country'] ?? 'Bangladesh',
            'product_name' => $productNames ?: 'Shirin Fashion Order',
            'product_category' => 'Beauty',
            'product_profile' => 'general',
            'value_a' => (string) $order->id,
            'value_b' => (string) $order->order_number,
        ];

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post($this->baseUrl().'/gwprocess/v4/api.php', $payload)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('SSLCommerz payment session could not be created.', 0, $exception);
        }

        if (! is_array($response) || empty($response['GatewayPageURL'])) {
            throw new RuntimeException($response['failedreason'] ?? 'SSLCommerz did not return a payment URL.');
        }

        return $response;
    }

    public function validateTransaction(string $validationId): array
    {
        $this->ensureConfigured();

        try {
            $response = Http::timeout(20)
                ->get($this->baseUrl().'/validator/api/validationserverAPI.php', [
                    'val_id' => $validationId,
                    'store_id' => config('sslcommerz.store_id'),
                    'store_passwd' => config('sslcommerz.store_password'),
                    'format' => 'json',
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('SSLCommerz payment validation failed.', 0, $exception);
        }

        if (! is_array($response)) {
            throw new RuntimeException('SSLCommerz returned an invalid validation response.');
        }

        return $response;
    }

    public function isSuccessful(array $payload): bool
    {
        return in_array(strtoupper((string) ($payload['status'] ?? '')), ['VALID', 'VALIDATED'], true);
    }

    public function frontendUrl(string $path, array $query = []): string
    {
        $url = config('sslcommerz.frontend_url').'/'.ltrim($path, '/');

        return empty($query) ? $url : $url.'?'.http_build_query($query);
    }

    private function callbackUrl(string $action): string
    {
        return config('sslcommerz.callback_base_url')."/api/payments/sslcommerz/{$action}";
    }

    private function baseUrl(): string
    {
        return config('sslcommerz.sandbox')
            ? config('sslcommerz.sandbox_base_url')
            : config('sslcommerz.live_base_url');
    }

    private function ensureConfigured(): void
    {
        if (! config('sslcommerz.store_id') || ! config('sslcommerz.store_password')) {
            throw new RuntimeException('SSLCommerz credentials are not configured.');
        }
    }
}
