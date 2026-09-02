<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MfsVerifyService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    /**
     * Verify an MFS transaction against Digitrix MFS Verify API.
     *
     * @param  string  $provider  'bkash' | 'nagad' | 'rocket' | 'upay'
     * @param  string  $trxId  Alphanumeric transaction ID (e.g. 9H1A2B3C4D)
     * @param  float  $amount  Expected amount (e.g. 1500.00)
     * @param  string|null  $reference  Order number or reference
     * @param  string|null  $accountId  Optional account ID override
     * @return array{
     *     status: 'verified'|'pending_sync'|'failed',
     *     verified: bool,
     *     message: string,
     *     code?: string,
     *     retry_after_seconds?: int,
     *     data?: array,
     *     raw_response?: array
     * }
     */
    public function verifyTransaction(
        string $provider,
        string $trxId,
        float $amount,
        ?string $reference = null,
        ?string $accountId = null
    ): array {
        $mfsSettings = $this->settings->getGroup('mfs_gateway');

        if (! ($mfsSettings['enabled'] ?? false)) {
            throw new RuntimeException('MFS Automated Verification is currently disabled.');
        }

        $apiKey = trim((string) ($mfsSettings['api_key'] ?? ''));
        $apiSecret = trim((string) ($mfsSettings['api_secret'] ?? ''));
        $baseUrl = rtrim((string) ($mfsSettings['base_url'] ?? 'https://mfs.digitrixlabs.io'), '/');

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('MFS Gateway credentials (API Key or Secret) are missing.');
        }

        $providerKey = strtolower(trim($provider));
        $providerAccount = $mfsSettings['accounts'][$providerKey] ?? [];

        if (! ($providerAccount['enabled'] ?? false)) {
            throw new RuntimeException(sprintf('Payment method %s is currently not active for MFS payments.', strtoupper($providerKey)));
        }

        $resolvedAccountId = $accountId ?: trim((string) ($providerAccount['account_id'] ?? ''));

        if ($resolvedAccountId === '') {
            throw new RuntimeException(sprintf('Account ID for %s is not configured in MFS Gateway settings.', strtoupper($providerKey)));
        }

        $normalizedTrxId = strtoupper(trim($trxId));
        $formattedAmount = number_format($amount, 2, '.', '');
        $path = '/api/v1/transactions/verify';
        $method = 'POST';

        $payload = [
            'provider' => $providerKey,
            'account_id' => $resolvedAccountId,
            'trx_id' => $normalizedTrxId,
            'amount' => $formattedAmount,
            'reference' => $reference ?: ('ORDER-'.Str::upper(Str::random(6))),
            'payment_initiated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bodyHash = hash('sha256', $rawBody);
        $timestamp = Carbon::now('UTC')->toIso8601ZuluString();
        $nonce = bin2hex(random_bytes(16));
        $idempotencyKey = 'chk_'.bin2hex(random_bytes(12));

        // 1. Build Canonical String: METHOD\nPATH\nBODY_HASH\nTIMESTAMP\nNONCE
        $canonicalString = implode("\n", [
            $method,
            $path,
            $bodyHash,
            $timestamp,
            $nonce,
        ]);

        // 2. Compute HMAC-SHA256 Signature
        $signature = hash_hmac('sha256', $canonicalString, $apiSecret);

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => $apiKey,
                    'X-Timestamp' => $timestamp,
                    'X-Nonce' => $nonce,
                    'X-Signature' => $signature,
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->withBody($rawBody, 'application/json')
                ->post($baseUrl.$path);

            $status = $response->status();
            $body = $response->json() ?? [];

            Log::info('MFS verification API response', [
                'provider' => $providerKey,
                'trx_id' => $normalizedTrxId,
                'amount' => $formattedAmount,
                'http_status' => $status,
                'response' => $body,
            ]);

            // Success / Verified
            if ($status === 200 && ($body['success'] ?? false) && ($body['data']['verified'] ?? false)) {
                return [
                    'status' => 'verified',
                    'verified' => true,
                    'message' => 'Transaction verified and consumed successfully.',
                    'usage_id' => $body['data']['usage_id'] ?? null,
                    'transaction' => $body['data']['transaction'] ?? null,
                    'data' => $body['data'] ?? [],
                    'raw_response' => $body,
                ];
            }

            // HTTP 202 - SMS pending sync from gateway device
            if ($status === 202 || (($body['data']['status'] ?? '') === 'pending_sync')) {
                $retryAfter = (int) ($body['data']['retry_after_seconds'] ?? 30);

                return [
                    'status' => 'pending_sync',
                    'verified' => false,
                    'retry_after_seconds' => $retryAfter > 0 ? $retryAfter : 30,
                    'code' => $body['error']['code'] ?? 'TRANSACTION_PENDING_SYNC',
                    'message' => $body['error']['message'] ?? 'Payment confirmation SMS has not arrived on your physical gateway device yet. Please retry in 30 seconds.',
                    'data' => $body['data'] ?? [],
                    'raw_response' => $body,
                ];
            }

            // Failure / Already Consumed / Invalid
            $errorCode = $body['error']['code'] ?? 'TRANSACTION_VERIFICATION_FAILED';
            $errorMessage = $body['error']['message'] ?? 'Payment verification failed. Please check the Transaction ID and try again.';

            return [
                'status' => 'failed',
                'verified' => false,
                'code' => $errorCode,
                'message' => $errorMessage,
                'data' => $body['data'] ?? [],
                'raw_response' => $body,
            ];
        } catch (Exception $e) {
            Log::error('MFS Verification request exception', [
                'message' => $e->getMessage(),
                'provider' => $providerKey,
                'trx_id' => $normalizedTrxId,
            ]);

            return [
                'status' => 'failed',
                'verified' => false,
                'code' => 'MFS_NETWORK_ERROR',
                'message' => 'Could not connect to MFS Verification server: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Test gateway connection and API credentials.
     */
    public function testConnection(?array $overrideSettings = null): array
    {
        $settings = $overrideSettings ?: $this->settings->getGroup('mfs_gateway');

        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $apiSecret = trim((string) ($settings['api_secret'] ?? ''));
        $baseUrl = rtrim((string) ($settings['base_url'] ?? 'https://mfs.digitrixlabs.io'), '/');

        if ($apiKey === '' || $apiSecret === '') {
            return [
                'success' => false,
                'message' => 'API Key and Secret must be provided before testing.',
            ];
        }

        $path = '/api/v1/transactions/verify';
        $method = 'POST';
        $payload = [
            'provider' => 'bkash',
            'account_id' => 'test_account',
            'trx_id' => 'TEST000000',
            'amount' => '1.00',
            'reference' => 'TEST-PING',
            'payment_initiated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bodyHash = hash('sha256', $rawBody);
        $timestamp = Carbon::now('UTC')->toIso8601ZuluString();
        $nonce = bin2hex(random_bytes(16));
        $idempotencyKey = 'ping_'.bin2hex(random_bytes(12));

        $canonicalString = implode("\n", [$method, $path, $bodyHash, $timestamp, $nonce]);
        $signature = hash_hmac('sha256', $canonicalString, $apiSecret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => $apiKey,
                    'X-Timestamp' => $timestamp,
                    'X-Nonce' => $nonce,
                    'X-Signature' => $signature,
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->withBody($rawBody, 'application/json')
                ->post($baseUrl.$path);

            $status = $response->status();
            $body = $response->json() ?? [];

            if ($status === 401 || $status === 403 || ($body['error']['code'] ?? '') === 'UNAUTHORIZED') {
                return [
                    'success' => false,
                    'message' => $body['error']['message'] ?? 'Authentication failed. Please check your API Key and Secret.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Successfully connected to MFS Gateway API. Authentication signature verified.',
                'http_status' => $status,
                'server_response' => $body,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }
}
