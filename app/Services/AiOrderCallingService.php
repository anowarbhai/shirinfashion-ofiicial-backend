<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiOrderCallingService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function triggerForOrder(Order $order): void
    {
        $config = $this->settings->getGroup('ai_calling');

        if (! $this->shouldCall($order, $config)) {
            return;
        }

        $payload = $this->payload($order, $config);

        try {
            $response = Http::withToken((string) $config['api_token'])
                ->acceptJson()
                ->timeout((int) ($config['request_timeout'] ?? 20))
                ->post(rtrim((string) $config['api_base_url'], '/').'/calls/verify', $payload);

            $order->forceFill([
                'ai_call_status' => $response->successful() ? 'requested' : 'failed',
                'ai_call_response' => [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                    'payload' => $this->redactedPayload($payload),
                ],
                'ai_call_last_attempt_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $order->forceFill([
                'ai_call_status' => 'failed',
                'ai_call_response' => [
                    'error' => $exception->getMessage(),
                    'payload' => $this->redactedPayload($payload),
                ],
                'ai_call_last_attempt_at' => now(),
            ])->save();

            Log::warning('AI order confirmation call failed.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function callbackToken(Order $order): string
    {
        return hash_hmac('sha256', "{$order->id}|{$order->order_number}", config('app.key'));
    }

    public function sendTestCall(array $payload): array
    {
        $config = $this->settings->getGroup('ai_calling');
        $this->ensureConfigured($config);

        $storeName = (string) ($config['store_name'] ?: 'Shirin Fashion');
        $amount = (string) $payload['amount'];
        $voicePayload = array_filter([
            'phone_number' => $payload['phone_number'],
            'caller_id' => $config['caller_id'] ?: null,
            'customer_name' => $payload['customer_name'],
            'amount' => $amount,
            'store_name' => $storeName,
            'agent_extension' => $config['agent_extension'] ?: null,
            'custom_text' => $this->renderText(
                (string) $config['custom_text'],
                (string) $payload['customer_name'],
                'TEST-CALL',
                (string) $payload['product_names'],
                $storeName,
                $amount,
            ),
            'confirm_text' => $this->renderText(
                (string) $config['confirm_text'],
                (string) $payload['customer_name'],
                'TEST-CALL',
                (string) $payload['product_names'],
                $storeName,
                $amount,
            ),
            'cancel_text' => $this->renderText(
                (string) $config['cancel_text'],
                (string) $payload['customer_name'],
                'TEST-CALL',
                (string) $payload['product_names'],
                $storeName,
                $amount,
            ),
            'webhook_url' => $this->testCallbackUrl($config),
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = Http::withToken((string) $config['api_token'])
            ->acceptJson()
            ->timeout((int) ($config['request_timeout'] ?? 20))
            ->post(rtrim((string) $config['api_base_url'], '/').'/calls/verify', $voicePayload);

        if (! $response->successful()) {
            $providerMessage = $response->json('message')
                ?: $response->json('error')
                ?: $response->body();

            throw new RuntimeException(
                'AI calling provider rejected the test call request [HTTP '.$response->status().']'
                .($providerMessage ? ': '.Str::limit((string) $providerMessage, 240) : '.')
            );
        }

        return [
            'status' => $response->status(),
            'provider_response' => $response->json() ?? $response->body(),
            'payload' => $this->redactedPayload($voicePayload),
        ];
    }

    public function callbackUrl(Order $order, array $config): string
    {
        $baseUrl = rtrim((string) ($config['webhook_base_url'] ?: config('app.url')), '/');

        return "{$baseUrl}/api/ai-calling/order-confirmation/{$order->id}?token=".$this->callbackToken($order);
    }

    public function testCallbackUrl(array $config): string
    {
        $baseUrl = rtrim((string) ($config['webhook_base_url'] ?: config('app.url')), '/');

        return "{$baseUrl}/api/ai-calling/test-callback";
    }

    public function applyCallback(Order $order, string $status, array $payload = []): string
    {
        $config = $this->settings->getGroup('ai_calling');
        $normalized = Str::lower(trim($status));
        $newStatus = match ($normalized) {
            'confirmed', 'confirm', 'accepted', 'success', '1' => (string) $config['confirmed_status'],
            'rejected', 'reject', 'cancelled', 'canceled', 'cancel', 'failed', '2' => (string) $config['rejected_status'],
            default => '',
        };

        if ($newStatus === '') {
            $order->forceFill([
                'ai_call_status' => 'callback_unknown',
                'ai_call_response' => array_merge($order->ai_call_response ?? [], [
                    'callback' => $payload,
                ]),
                'ai_call_callback_at' => now(),
            ])->save();

            return (string) $order->status;
        }

        $order->forceFill([
            'status' => $newStatus,
            'ai_call_status' => $normalized === '1' || str_starts_with($normalized, 'confirm') || $normalized === 'success'
                ? 'confirmed'
                : 'rejected',
            'ai_call_response' => array_merge($order->ai_call_response ?? [], [
                'callback' => $payload,
            ]),
            'ai_call_callback_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        return $newStatus;
    }

    private function shouldCall(Order $order, array $config): bool
    {
        return (bool) ($config['enabled'] ?? false)
            && (string) ($config['api_token'] ?? '') !== ''
            && (string) ($config['api_base_url'] ?? '') !== ''
            && (string) $order->phone !== ''
            && (string) $order->status === 'processing'
            && (! ($config['cod_only'] ?? true) || $order->payment_method === 'cod');
    }

    private function ensureConfigured(array $config): void
    {
        if ((string) ($config['api_base_url'] ?? '') === '' || (string) ($config['api_token'] ?? '') === '') {
            throw new RuntimeException('AI calling API base URL and API token are required.');
        }
    }

    private function payload(Order $order, array $config): array
    {
        $storeName = (string) ($config['store_name'] ?: 'Shirin Fashion');
        $amount = number_format((float) $order->grand_total, 0).' BDT';

        return array_filter([
            'phone_number' => $order->phone,
            'caller_id' => $config['caller_id'] ?: null,
            'customer_name' => $order->customer_name,
            'amount' => $amount,
            'store_name' => $storeName,
            'agent_extension' => $config['agent_extension'] ?: null,
            'custom_text' => $this->render((string) $config['custom_text'], $order, $storeName, $amount),
            'confirm_text' => $this->render((string) $config['confirm_text'], $order, $storeName, $amount),
            'cancel_text' => $this->render((string) $config['cancel_text'], $order, $storeName, $amount),
            'webhook_url' => $this->callbackUrl($order, $config),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function render(string $template, Order $order, string $storeName, string $amount): string
    {
        return $this->renderText(
            $template,
            (string) $order->customer_name,
            (string) $order->order_number,
            $this->productNames($order),
            $storeName,
            $amount,
        );
    }

    private function renderText(
        string $template,
        string $customerName,
        string $orderNumber,
        string $productNames,
        string $storeName,
        string $amount,
    ): string {
        return strtr($template, [
            '{{customer_name}}' => $customerName,
            '{{order_number}}' => $orderNumber,
            '{{product_names}}' => $productNames,
            '{{amount}}' => $amount,
            '{{store_name}}' => $storeName,
        ]);
    }

    private function productNames(Order $order): string
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get(['product_name', 'quantity']);

        return $items
            ->map(function ($item): string {
                $name = trim((string) $item->product_name);
                $quantity = (int) $item->quantity;

                if ($name === '') {
                    return '';
                }

                return $quantity > 1 ? "{$name} quantity {$quantity}" : $name;
            })
            ->filter()
            ->join(', ');
    }

    private function redactedPayload(array $payload): array
    {
        unset($payload['webhook_url']);

        return $payload;
    }
}
