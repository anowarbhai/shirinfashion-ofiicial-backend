<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Support\SensitiveSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaConversionsApiService
{
    public function sendPurchase(Order $order): void
    {
        if ($order->meta_purchase_sent_at) {
            return;
        }

        $settings = $this->settings();
        $targets = $this->resolveTargets($order, $settings);

        if ($targets === []) {
            return;
        }

        $payload = $this->purchasePayload($order);
        $allSucceeded = true;

        foreach ($targets as $target) {
            try {
                $request = Http::asJson()
                    ->acceptJson()
                    ->timeout(max(2, (int) config('services.meta.request_timeout', 8)))
                    ->retry(2, 250, fn (Throwable $exception): bool => $exception instanceof ConnectionException);

                $body = [
                    'data' => [$payload],
                    'access_token' => $target['access_token'],
                ];

                if ($target['test_event_code'] !== '') {
                    $body['test_event_code'] = $target['test_event_code'];
                }

                $response = $request->post($this->eventsUrl($target['pixel_id']), $body);

                if (! $response->successful()) {
                    $allSucceeded = false;
                    Log::warning('Meta CAPI Purchase request was rejected.', [
                        'order_id' => $order->id,
                        'pixel_id' => $target['pixel_id'],
                        'status' => $response->status(),
                        'error' => $response->json('error.message'),
                    ]);
                }
            } catch (Throwable $exception) {
                $allSucceeded = false;
                Log::warning('Meta CAPI Purchase request failed.', [
                    'order_id' => $order->id,
                    'pixel_id' => $target['pixel_id'],
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        if ($allSucceeded) {
            $order->forceFill(['meta_purchase_sent_at' => now()])->save();
        }
    }

    private function purchasePayload(Order $order): array
    {
        $items = $order->items->where('is_free_gift', false)->values();
        $contentIds = $items
            ->map(fn ($item): string => trim((string) ($item->sku ?: $item->product_id ?: $item->product_name)))
            ->filter()
            ->values();
        $contents = $items->map(fn ($item): array => [
            'id' => trim((string) ($item->sku ?: $item->product_id ?: $item->product_name)),
            'quantity' => max(1, (int) $item->quantity),
            'item_price' => round((float) $item->price, 2),
        ])->values()->all();

        return [
            'event_name' => 'Purchase',
            'event_time' => (int) ($order->placed_at?->timestamp ?? now()->timestamp),
            'event_id' => 'purchase:'.$order->order_number,
            'event_source_url' => $this->eventSourceUrl($order),
            'action_source' => 'website',
            'user_data' => $this->userData($order),
            'custom_data' => [
                'currency' => 'BDT',
                'value' => round((float) $order->grand_total, 2),
                'order_id' => (string) $order->order_number,
                'content_type' => 'product',
                'content_ids' => $contentIds->all(),
                'contents' => $contents,
                'num_items' => $items->sum(fn ($item): int => max(1, (int) $item->quantity)),
            ],
        ];
    }

    private function userData(Order $order): array
    {
        $phone = $this->normalizePhone($order->phone);
        $email = $this->realEmail($order->email);
        $nameParts = preg_split('/\s+/u', trim((string) $order->customer_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $nameParts[0] ?? null;
        $lastName = count($nameParts) > 1 ? $nameParts[array_key_last($nameParts)] : null;
        $city = $order->shipping_address['city'] ?? null;

        return array_filter([
            'em' => $email ? [$this->hash($email)] : null,
            'ph' => $phone ? [$this->hash($phone)] : null,
            'fn' => $firstName ? [$this->hash($firstName)] : null,
            'ln' => $lastName ? [$this->hash($lastName)] : null,
            'ct' => $city ? [$this->hash((string) $city)] : null,
            'country' => [$this->hash('bd')],
            'external_id' => $phone ? [$this->hash($phone)] : null,
            'client_ip_address' => $order->client_ip ?: null,
            'client_user_agent' => $order->meta_user_agent ?: null,
            'fbp' => $this->validBrowserId($order->meta_fbp),
            'fbc' => $this->validBrowserId($order->meta_fbc),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function resolveTargets(Order $order, array $settings): array
    {
        $campaignIds = $this->campaignPixelIds($order);
        $campaignPixels = collect($settings['campaign_pixels'] ?? [])
            ->filter(fn ($pixel): bool => is_array($pixel))
            ->filter(fn (array $pixel): bool => $campaignIds->contains((string) ($pixel['id'] ?? '')))
            ->filter(fn (array $pixel): bool => (bool) ($pixel['enabled'] ?? true))
            ->filter(fn (array $pixel): bool => $this->validPixelId($pixel['pixel_id'] ?? null))
            ->values();

        if ($campaignPixels->isNotEmpty()) {
            return $campaignPixels
                ->filter(fn (array $pixel): bool => (bool) ($pixel['capi_enabled'] ?? false))
                ->map(fn (array $pixel): array => $this->target($pixel))
                ->filter(fn (array $target): bool => $target['access_token'] !== '')
                ->values()->all();
        }

        if (
            ! (bool) ($settings['pixel_enabled'] ?? false)
            || ! (bool) ($settings['capi_enabled'] ?? false)
            || ! $this->validPixelId($settings['pixel_id'] ?? null)
        ) {
            return [];
        }

        $target = $this->target($settings);

        return $target['access_token'] !== '' ? [$target] : [];
    }

    private function campaignPixelIds(Order $order)
    {
        if ($order->meta_landing_page_slug) {
            $page = StorefrontPage::query()
                ->where('slug', $order->meta_landing_page_slug)
                ->where('status', 'published')
                ->first();

            if ($page) {
                return collect($page->campaign_facebook_pixel_ids ?? [])->map(fn ($id): string => (string) $id)->filter()->unique();
            }
        }

        $productIds = $order->items->pluck('product_id')->filter()->unique();

        return Product::query()
            ->whereIn('id', $productIds)
            ->get(['campaign_facebook_pixel_ids'])
            ->flatMap(fn (Product $product): array => $product->campaign_facebook_pixel_ids ?? [])
            ->map(fn ($id): string => (string) $id)
            ->filter()->unique()->values();
    }

    private function target(array $settings): array
    {
        return [
            'pixel_id' => trim((string) ($settings['pixel_id'] ?? '')),
            'access_token' => trim((string) ($settings['access_token'] ?? '')),
            'test_event_code' => trim((string) ($settings['test_event_code'] ?? '')),
        ];
    }

    private function settings(): array
    {
        $stored = StorefrontSetting::query()->where('key', 'facebook_marketing')->value('value');

        return SensitiveSettings::revealFacebook(is_array($stored) ? $stored : []);
    }

    private function eventsUrl(string $pixelId): string
    {
        $version = trim((string) config('services.meta.graph_api_version', 'v24.0'));
        $version = preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v24.0';

        return "https://graph.facebook.com/{$version}/{$pixelId}/events";
    }

    private function eventSourceUrl(Order $order): string
    {
        $candidate = trim((string) $order->meta_event_source_url);

        if (filter_var($candidate, FILTER_VALIDATE_URL) && in_array(parse_url($candidate, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $candidate;
        }

        return rtrim((string) config('sslcommerz.frontend_url', config('app.url')), '/').'/order-success';
    }

    private function realEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && ! str_ends_with($email, '@guest.checkout') ? $email : null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if (str_starts_with($digits, '880')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '88'.$digits;
        }

        return $digits !== '' ? $digits : null;
    }

    private function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    private function validPixelId(mixed $pixelId): bool
    {
        return preg_match('/^\d{8,20}$/', trim((string) $pixelId)) === 1;
    }

    private function validBrowserId(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[A-Za-z0-9._-]{5,255}$/', $value) ? $value : null;
    }
}
