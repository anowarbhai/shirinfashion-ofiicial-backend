<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FraudCheckerService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function check(string $phone): array
    {
        $config = $this->settings->getGroup('fraud_checker');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $apiUrl = trim((string) ($config['api_url'] ?? 'https://fraudchecker.ocs-api.top/api/v3'));

        if ($apiKey === '') {
            throw new RuntimeException('Fraud checker API key is not configured.');
        }

        $normalizedPhone = $this->normalizePhone($phone);

        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(1, 300)
            ->get($apiUrl, [
                'phone' => $normalizedPhone,
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->status()));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Fraud checker returned an invalid response.');
        }

        return $this->normalizeResponse($payload, $normalizedPhone);
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (Str::startsWith($digits, '8801') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        }

        if (Str::startsWith($digits, '1') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        if (! preg_match('/^01[3-9]\d{8}$/', $digits)) {
            throw new RuntimeException('Invalid phone number. Use an 11 digit Bangladeshi number.');
        }

        return $digits;
    }

    private function normalizeResponse(array $payload, string $phone): array
    {
        $couriers = [];

        foreach (($payload['response'] ?? []) as $name => $source) {
            if (! is_array($source)) {
                continue;
            }

            $data = is_array($source['data'] ?? null) ? $source['data'] : [];

            $couriers[] = [
                'name' => ucfirst((string) $name),
                'status' => (bool) ($source['status'] ?? false),
                'message' => (string) ($source['message'] ?? ''),
                'success' => (int) ($data['success'] ?? 0),
                'cancel' => (int) ($data['cancel'] ?? 0),
                'total' => (int) ($data['total'] ?? 0),
                'delivered_percentage' => (int) ($data['deliveredPercentage'] ?? 0),
                'return_percentage' => (int) ($data['returnPercentage'] ?? 0),
            ];
        }

        return [
            'phone' => (string) ($payload['phone'] ?? $phone),
            'status' => (string) ($payload['status'] ?? 'Unknown'),
            'score' => (int) ($payload['score'] ?? 0),
            'total_parcel' => (int) ($payload['total_parcel'] ?? 0),
            'success_parcel' => (int) ($payload['success_parcel'] ?? 0),
            'cancel_parcel' => (int) ($payload['cancel_parcel'] ?? 0),
            'source' => (string) ($payload['source'] ?? 'LIVE'),
            'couriers' => $couriers,
            'raw' => $payload,
        ];
    }

    private function errorMessage(int $status): string
    {
        return match ($status) {
            400 => 'Fraud checker request is missing required parameters.',
            401 => 'Invalid fraud checker API key.',
            403 => 'Fraud checker account is inactive.',
            429 => 'Fraud checker limit exceeded.',
            default => 'Fraud checker service is unavailable right now.',
        };
    }
}
