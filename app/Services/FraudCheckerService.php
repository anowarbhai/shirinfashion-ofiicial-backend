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
        $provider = (string) ($config['provider'] ?? 'onesoftcode');
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($apiKey === '') {
            throw new RuntimeException('Fraud checker API key is not configured.');
        }

        $normalizedPhone = $this->normalizePhone($phone);

        if ($provider === 'bd_courier') {
            return $this->checkBdCourier($normalizedPhone, $apiKey, $config);
        }

        return $this->checkOneSoftCode($normalizedPhone, $apiKey, $config);
    }

    private function checkOneSoftCode(string $normalizedPhone, string $apiKey, array $config): array
    {
        $apiUrl = trim((string) ($config['api_url'] ?? 'https://fraudchecker.ocs-api.top/api/v3'));

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

        return $this->filterCouriers($this->normalizeOneSoftCodeResponse($payload, $normalizedPhone), $config);
    }

    private function checkBdCourier(string $normalizedPhone, string $apiKey, array $config): array
    {
        $apiUrl = rtrim(trim((string) ($config['bd_courier_api_url'] ?? 'https://api.bdcourier.com')), '/');

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->timeout(15)
            ->retry(1, 300)
            ->post($apiUrl.'/courier-check', [
                'phone' => $normalizedPhone,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->status()));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('BD Courier returned an invalid response.');
        }

        return $this->filterCouriers($this->normalizeBdCourierResponse($payload, $normalizedPhone), $config);
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

    private function normalizeOneSoftCodeResponse(array $payload, string $phone): array
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
            'source' => (string) ($payload['source'] ?? 'OneSoftCode'),
            'couriers' => $couriers,
            'raw' => $payload,
        ];
    }

    private function normalizeBdCourierResponse(array $payload, string $phone): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $couriers = [];

        foreach ($data as $key => $source) {
            if ($key === 'summary' || ! is_array($source)) {
                continue;
            }

            $successRatio = (float) ($source['success_ratio'] ?? 0);

            $couriers[] = [
                'key' => (string) $key,
                'name' => (string) ($source['name'] ?? ucfirst((string) $key)),
                'logo' => (string) ($source['logo'] ?? ''),
                'status' => true,
                'message' => '',
                'success' => (int) ($source['success_parcel'] ?? 0),
                'cancel' => (int) ($source['cancelled_parcel'] ?? 0),
                'total' => (int) ($source['total_parcel'] ?? 0),
                'delivered_percentage' => $successRatio,
                'return_percentage' => max(0, 100 - $successRatio),
            ];
        }

        return [
            'phone' => $phone,
            'status' => (string) ($payload['status'] ?? 'success'),
            'score' => (float) ($summary['success_ratio'] ?? 0),
            'total_parcel' => (int) ($summary['total_parcel'] ?? 0),
            'success_parcel' => (int) ($summary['success_parcel'] ?? 0),
            'cancel_parcel' => (int) ($summary['cancelled_parcel'] ?? 0),
            'source' => 'BD Courier',
            'couriers' => $couriers,
            'reports' => is_array($payload['reports'] ?? null) ? $payload['reports'] : [],
            'raw' => $payload,
        ];
    }

    private function filterCouriers(array $result, array $config): array
    {
        $enabledCouriers = is_array($config['couriers'] ?? null) ? $config['couriers'] : [];

        if ($enabledCouriers === []) {
            return $result;
        }

        $result['couriers'] = array_values(array_filter(
            $result['couriers'] ?? [],
            function (array $courier) use ($enabledCouriers): bool {
                $key = (string) ($courier['key'] ?? Str::of((string) ($courier['name'] ?? ''))->lower()->replace(' ', '')->value());

                return (bool) ($enabledCouriers[$key] ?? true);
            },
        ));

        $total = array_sum(array_map(fn (array $courier): int => (int) ($courier['total'] ?? 0), $result['couriers']));
        $success = array_sum(array_map(fn (array $courier): int => (int) ($courier['success'] ?? 0), $result['couriers']));
        $cancel = array_sum(array_map(fn (array $courier): int => (int) ($courier['cancel'] ?? 0), $result['couriers']));

        $result['total_parcel'] = $total;
        $result['success_parcel'] = $success;
        $result['cancel_parcel'] = $cancel;
        $result['score'] = $total > 0 ? round(($success / $total) * 100, 2) : 0;

        return $result;
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
