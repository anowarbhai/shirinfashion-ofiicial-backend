<?php

namespace App\Services;

use App\Support\BangladeshPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsGatewayService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function getBalance(): array
    {
        $config = $this->config();
        $provider = $this->resolvedProvider($config);

        if ($provider !== 'onecodesoft') {
            throw new RuntimeException('Balance check is currently available only for OneCodeSoft.');
        }

        $this->ensureReady($config);

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get($this->endpoint($config, 'get-balance'), [
                    'api_key' => $config['api_key'],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach the SMS provider right now.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Unable to fetch SMS balance from provider.');
        }

        $payload = $response->json();

        return [
            'provider' => 'onecodesoft',
            'balance' => (string) Arr::get($payload, 'balance', '0'),
            'estimate_sms' => (int) Arr::get($payload, 'estimate_sms', 0),
            'raw' => $payload,
        ];
    }

    public function sendTestMessage(string $number, string $message): array
    {
        return $this->sendMessage($number, $message);
    }

    public function sendMessage(string $number, string $message): array
    {
        $config = $this->config();
        $provider = $this->resolvedProvider($config);
        $number = $this->normalizeRecipient($number);
        $senderId = $this->normalizeSenderId((string) ($config['sender_id'] ?? ''));

        if ($provider !== 'onecodesoft') {
            throw new RuntimeException('Test message is currently available only for OneCodeSoft.');
        }

        $this->ensureReady($config);

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get($this->endpoint($config, 'send-sms'), [
                    'api_key' => $config['api_key'],
                    'type' => 'text',
                    'number' => $number,
                    'senderid' => $senderId,
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to send a test SMS right now.');
        }

        $payload = $response->json();

        if (! $response->successful()) {
            throw new RuntimeException($this->buildProviderErrorMessage($response->status(), $payload, $response->body()));
        }

        $statusCode = (string) (
            Arr::get($payload, 'response_code')
            ?? Arr::get($payload, 'status')
            ?? Arr::get($payload, 'code')
            ?? $response->status()
        );

        if (in_array($statusCode, ['1007', '400', '401', '403', '422', '500'], true)) {
            throw new RuntimeException($this->buildProviderErrorMessage($response->status(), $payload, $response->body()));
        }

        return [
            'provider' => 'onecodesoft',
            'status_code' => $statusCode,
            'message' => Arr::get($payload, 'message')
                ?? Arr::get($payload, 'response_message')
                ?? 'Test SMS request submitted successfully.',
            'raw' => $payload,
        ];
    }

    public function normalizeRecipient(string $number): string
    {
        try {
            return BangladeshPhone::normalizeToInternational($number);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage());
        }
    }

    public function normalizeSenderId(string $senderId): string
    {
        $cleaned = trim($senderId);

        if ($cleaned === '') {
            throw new RuntimeException('Sender ID is missing.');
        }

        if (preg_match('/^\+?[0-9\s-]+$/', $cleaned) === 1) {
            return preg_replace('/\D+/', '', $cleaned) ?? $cleaned;
        }

        return $cleaned;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->settings->getGroup('sms_integration');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureReady(array $config): void
    {
        if (! ($config['enabled'] ?? false)) {
            throw new RuntimeException('SMS integration is disabled.');
        }

        if (! ($config['api_key'] ?? null)) {
            throw new RuntimeException('Secret API key is missing.');
        }

        if (! ($config['sender_id'] ?? null)) {
            throw new RuntimeException('Sender ID is missing.');
        }

        if (! ($config['base_url'] ?? null)) {
            throw new RuntimeException('Base URL is missing.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function endpoint(array $config, string $path): string
    {
        return rtrim((string) $config['base_url'], '/').'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolvedProvider(array $config): string
    {
        $provider = strtolower((string) ($config['provider'] ?? 'custom'));
        $baseUrl = strtolower((string) ($config['base_url'] ?? ''));

        if ($provider === 'onecodesoft' || str_contains($baseUrl, 'sms.onecodesoft.com')) {
            return 'onecodesoft';
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function buildProviderErrorMessage(int $status, ?array $payload, string $rawBody): string
    {
        $providerMessage = Arr::get($payload, 'message')
            ?? Arr::get($payload, 'response_message')
            ?? Arr::get($payload, 'ErrorDescription')
            ?? Arr::get($payload, 'error')
            ?? null;

        $providerCode = Arr::get($payload, 'response_code')
            ?? Arr::get($payload, 'code')
            ?? Arr::get($payload, 'ErrorCode')
            ?? null;

        if ($providerMessage || $providerCode) {
            return trim(sprintf(
                'Provider rejected the test SMS request%s%s',
                $providerCode ? " [code: {$providerCode}]" : '',
                $providerMessage ? ": {$providerMessage}" : '.',
            ));
        }

        $body = trim($rawBody);

        if ($body !== '') {
            return sprintf('Provider rejected the test SMS request [HTTP %d]: %s', $status, mb_strimwidth($body, 0, 220, '...'));
        }

        return sprintf('Provider rejected the test SMS request [HTTP %d]. Please check your Sender ID, API key, and balance.', $status);
    }
}
