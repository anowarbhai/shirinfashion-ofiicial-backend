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
        $baseUrl = rtrim((string) ($mfsSettings['base_url'] ?? 'https://mfsapi.digitrixlabs.io'), '/');
        $keyVersion = trim((string) ($mfsSettings['key_version'] ?? '1')) ?: '1';

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
        $timestamp = Carbon::now('UTC')->toIso8601ZuluString();
        $nonce = bin2hex(random_bytes(16));
        $idempotencyKey = 'shirin_'.bin2hex(random_bytes(12));

        $payload = [
            'provider' => $providerKey,
            'account_id' => $resolvedAccountId,
            'trx_id' => $normalizedTrxId,
            'amount' => $formattedAmount,
            'payment_initiated_at' => $timestamp,
        ];

        if ($reference) {
            $payload['reference'] = $reference;
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bodyHash = hash('sha256', $rawBody);

        // 1. Build Canonical String: METHOD\nPATH\nBODY_HASH\nTIMESTAMP\nNONCE
        $canonicalString = implode("\n", [
            $method,
            $path,
            $bodyHash,
            $timestamp,
            $nonce,
        ]);

        // 2. Compute HMAC-SHA256 Signature with Base64URL decoded secret buffer
        $secretBuffer = self::decodeBase64Url($apiSecret);
        $signature = hash_hmac('sha256', $canonicalString, $secretBuffer);

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => $apiKey,
                    'X-Key-Version' => $keyVersion,
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
        $baseUrl = rtrim((string) ($settings['base_url'] ?? 'https://mfsapi.digitrixlabs.io'), '/');
        $keyVersion = trim((string) ($settings['key_version'] ?? '1')) ?: '1';

        if ($apiKey === '' || $apiSecret === '') {
            return [
                'success' => false,
                'message' => 'API Key and Secret must be provided before testing.',
            ];
        }

        // 1. Health check: verify gateway server is reachable
        try {
            $healthResponse = Http::timeout(8)->get($baseUrl.'/');
            if (! $healthResponse->successful()) {
                return [
                    'success' => false,
                    'message' => "MFS Gateway server responded with HTTP {$healthResponse->status()} at {$baseUrl}. Please check the Base URL.",
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Unable to connect to MFS Gateway at {$baseUrl}: ".$e->getMessage(),
            ];
        }

        // 2. Authentication check: send authenticated request to verify API credentials
        $path = '/api/v1/transactions';
        $method = 'GET';
        $timestamp = Carbon::now('UTC')->toIso8601ZuluString();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', '');
        $canonicalString = implode("\n", [$method, $path, $bodyHash, $timestamp, $nonce]);
        $secretBuffer = self::decodeBase64Url($apiSecret);
        $signature = hash_hmac('sha256', $canonicalString, $secretBuffer);

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-API-Key' => $apiKey,
                    'X-Key-Version' => $keyVersion,
                    'X-Timestamp' => $timestamp,
                    'X-Nonce' => $nonce,
                    'X-Signature' => $signature,
                ])
                ->get($baseUrl.$path);

            $status = $response->status();
            $body = $response->json() ?? [];

            if ($status === 200) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to MFS Gateway API. Authentication signature verified and active.',
                    'http_status' => $status,
                    'server_response' => $body,
                ];
            }

            $errorCode = $body['error']['code'] ?? '';
            $rawMsg = $body['error']['message'] ?? ($body['message'] ?? '');

            if ($status === 401) {
                $friendlyMessage = match ($errorCode) {
                    'INVALID_API_KEY' => "Authentication failed: API Key was rejected by Digitrix MFS (INVALID_API_KEY). Either the API Key ('{$apiKey}') does not exist, the API client is inactive/revoked, or Key Version ('{$keyVersion}') does not match in your Digitrix MFS dashboard.",
                    'INVALID_SIGNATURE' => 'Authentication failed: Invalid API Secret or HMAC signature mismatch (INVALID_SIGNATURE). Please re-copy the API Secret from your Digitrix MFS dashboard and ensure your server clock is synchronized.',
                    'API_REQUEST_REPLAYED' => 'Authentication failed: API request replayed. Please retry in a few seconds.',
                    default => 'Authentication failed: '.($rawMsg ?: 'Please verify your API Key, Secret and Key Version.'),
                };

                return [
                    'success' => false,
                    'message' => $friendlyMessage,
                    'code' => $errorCode,
                ];
            }

            if ($status === 403) {
                $friendlyMessage = match ($errorCode) {
                    'TENANT_SUSPENDED' => 'Authentication failed: Merchant account is suspended on Digitrix MFS. Please renew subscription.',
                    'FEATURE_BLOCKED' => 'Authentication failed: The API Verification feature is disabled for your tenant on Digitrix MFS.',
                    'IP_PROHIBITED' => 'Authentication failed: Origin IP address is not permitted for this API client in Digitrix MFS.',
                    default => 'Access forbidden by Digitrix MFS gateway: '.($rawMsg ?: 'HTTP 403 Forbidden'),
                };

                return [
                    'success' => false,
                    'message' => $friendlyMessage,
                    'code' => $errorCode,
                ];
            }

            // If 404/405 on GET /api/v1/transactions, fallback to testing POST /api/v1/transactions/verify
            if ($status === 404 || $status === 405) {
                $verifyPath = '/api/v1/transactions/verify';
                $verifyMethod = 'POST';
                $verifyNonce = bin2hex(random_bytes(16));
                $verifyPayload = [
                    'provider' => 'bkash',
                    'account_id' => 'test_account',
                    'trx_id' => 'TEST000000',
                    'amount' => '1.00',
                    'reference' => 'TEST-PING',
                    'payment_initiated_at' => $timestamp,
                ];
                $verifyRawBody = json_encode($verifyPayload, JSON_UNESCAPED_SLASHES);
                $verifyBodyHash = hash('sha256', $verifyRawBody);
                $verifyCanonical = implode("\n", [$verifyMethod, $verifyPath, $verifyBodyHash, $timestamp, $verifyNonce]);
                $verifySig = hash_hmac('sha256', $verifyCanonical, $secretBuffer);

                $verifyResp = Http::timeout(12)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-API-Key' => $apiKey,
                        'X-Key-Version' => $keyVersion,
                        'X-Timestamp' => $timestamp,
                        'X-Nonce' => $verifyNonce,
                        'X-Signature' => $verifySig,
                        'Idempotency-Key' => 'ping_'.bin2hex(random_bytes(12)),
                    ])
                    ->withBody($verifyRawBody, 'application/json')
                    ->post($baseUrl.$verifyPath);

                $vStatus = $verifyResp->status();
                $vBody = $verifyResp->json() ?? [];

                if ($vStatus !== 401 && $vStatus !== 403) {
                    return [
                        'success' => true,
                        'message' => 'Successfully connected to MFS Gateway API. Authentication signature verified and active.',
                        'http_status' => $vStatus,
                    ];
                }

                $vErrorCode = $vBody['error']['code'] ?? '';
                $vRawMsg = $vBody['error']['message'] ?? '';

                return [
                    'success' => false,
                    'message' => $vErrorCode === 'INVALID_API_KEY'
                        ? "Authentication failed: API Key was rejected by Digitrix MFS (INVALID_API_KEY). Either the API Key ('{$apiKey}') does not exist, the API client is inactive, or Key Version ('{$keyVersion}') does not match."
                        : ($vRawMsg ?: 'Authentication failed.'),
                    'code' => $vErrorCode,
                ];
            }

            return [
                'success' => false,
                'message' => "MFS Gateway returned HTTP {$status}: ".($rawMsg ?: 'Unexpected response'),
                'code' => $errorCode,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Decode a base64url-encoded string (RFC 4648 § 5) to raw binary bytes, matching Node.js Buffer.from(str, 'base64url').
     */
    private static function decodeBase64Url(string $data): string
    {
        $b64 = strtr(trim($data), '-_', '+/');
        $remainder = strlen($b64) % 4;
        if ($remainder === 1) {
            $b64 = substr($b64, 0, -1);
        } elseif ($remainder === 2) {
            $b64 .= '==';
        } elseif ($remainder === 3) {
            $b64 .= '=';
        }

        $decoded = base64_decode($b64, false);

        return ($decoded !== false && $decoded !== '') ? $decoded : trim($data);
    }
}
