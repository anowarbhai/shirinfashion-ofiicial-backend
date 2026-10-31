<?php

namespace App\Services;

use App\Support\BangladeshPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsGatewayService
{
    public function __construct(private readonly AdminSettingsService $settings) {}

    public function getBalance(): array
    {
        $config = $this->oneCodeSoftConfig();
        $response = $this->request($config, 'GET', 'balance');
        $payload = $this->successfulPayload($response, 'fetch SMS balance');

        return [
            'provider' => 'onecodesoft',
            'balance' => (string) Arr::get($payload, 'data.balance', '0'),
            'estimate_sms' => (int) Arr::get($payload, 'data.estimate_sms', 0),
            'raw' => $payload,
        ];
    }

    public function sendTestMessage(string $number, string $message): array
    {
        return $this->sendMessage($number, $message);
    }

    public function sendMessage(string $number, string $message): array
    {
        return $this->send('send-sms', $number, $message);
    }

    /**
     * Send an application-managed OTP through OneCodeSoft's custom OTP endpoint.
     * Verification remains local so existing login, checkout, and password-reset
     * sessions keep their current security and single-use guarantees.
     */
    public function sendOtpMessage(string $number, string $message): array
    {
        return $this->send('otp', $number, $message);
    }

    public function normalizeRecipient(string $number): string
    {
        try {
            return BangladeshPhone::normalizeToInternational($number);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function send(string $endpoint, string $number, string $message): array
    {
        $config = $this->oneCodeSoftConfig();
        $body = [
            'number' => $this->normalizeRecipient($number),
            'message' => $message,
        ];
        $senderId = trim((string) ($config['sender_id'] ?? ''));

        // Sender ID is optional in v3. Omitting it enables provider-managed
        // non-masking sender selection and OTP sender rotation.
        if ($endpoint === 'send-sms' && $senderId !== '') {
            $body['senderid'] = $senderId;
        }

        $response = $this->request($config, 'POST', $endpoint, $body, true);
        $payload = $this->successfulPayload($response, 'send SMS');
        $result = Arr::get($payload, 'data.results.0', []);
        $recipientStatus = strtolower((string) (
            Arr::get($result, 'status')
            ?? Arr::get($result, 'would_be')
            ?? 'sent'
        ));

        if ($recipientStatus !== 'sent') {
            $code = Arr::get($result, 'code');
            throw new RuntimeException(trim(sprintf(
                'SMS was not accepted for delivery%s: %s.',
                $code ? " [code: {$code}]" : '',
                $recipientStatus ?: 'failed'
            )));
        }

        return [
            'provider' => 'onecodesoft',
            'status_code' => $recipientStatus,
            'message' => (string) ($payload['message'] ?? 'SMS request processed.'),
            'message_id' => Arr::get($result, 'message_id')
                ?? Arr::get($payload, 'data.message_id'),
            'sms_count' => (int) (Arr::get($result, 'sms_count') ?? 0),
            'total_cost' => Arr::get($payload, 'data.total_cost'),
            'balance' => Arr::get($payload, 'data.balance'),
            'sandbox' => (bool) ($config['sandbox_mode'] ?? false),
            'raw' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function oneCodeSoftConfig(): array
    {
        $config = $this->settings->getGroup('sms_integration');

        if ($this->resolvedProvider($config) !== 'onecodesoft') {
            throw new RuntimeException('This SMS provider is not supported by the OneCodeSoft v3 integration.');
        }

        if (! ($config['enabled'] ?? false)) {
            throw new RuntimeException('SMS integration is disabled.');
        }

        if (blank($config['api_key'] ?? null)) {
            throw new RuntimeException('OneCodeSoft bearer token is missing.');
        }

        if (blank($config['base_url'] ?? null)) {
            throw new RuntimeException('OneCodeSoft base URL is missing.');
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $body
     */
    private function request(array $config, string $method, string $path, array $body = [], bool $allowSandbox = false): Response
    {
        $url = $this->endpoint(
            $config,
            $path,
            $allowSandbox && (bool) ($config['sandbox_mode'] ?? false)
        );

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withToken((string) $config['api_key'])
                ->timeout(20);

            return $method === 'GET'
                ? $request->get($url, $body)
                : $request->post($url, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to reach the SMS provider right now.');
        }
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response, string $action): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("OneCodeSoft returned an invalid response while trying to {$action}.");
        }

        if (! $response->successful() || ($payload['status'] ?? null) !== 'success') {
            $message = trim((string) ($payload['message'] ?? ''));
            throw new RuntimeException($message !== ''
                ? "OneCodeSoft rejected the request: {$message}"
                : "OneCodeSoft could not {$action} [HTTP {$response->status()}].");
        }

        return $payload;
    }

    /** @param array<string, mixed> $config */
    private function endpoint(array $config, string $path, bool $sandbox = false): string
    {
        $baseUrl = rtrim((string) $config['base_url'], '/');
        // Accept and upgrade legacy dashboard values that pointed directly at
        // the old sandbox base path.
        $baseUrl = preg_replace('#/sandbox$#i', '', $baseUrl) ?: $baseUrl;

        if (! preg_match('#/v3$#i', $baseUrl)) {
            $baseUrl .= '/v3';
        }

        return $baseUrl.($sandbox ? '/sandbox' : '').'/'.ltrim($path, '/');
    }

    /** @param array<string, mixed> $config */
    private function resolvedProvider(array $config): string
    {
        $provider = strtolower((string) ($config['provider'] ?? 'custom'));
        $baseUrl = strtolower((string) ($config['base_url'] ?? ''));

        if (
            $provider === 'onecodesoft'
            || str_contains($baseUrl, 'onecodesoft.com')
            || str_contains($baseUrl, 'ocs-api.top')
        ) {
            return 'onecodesoft';
        }

        return $provider;
    }
}
