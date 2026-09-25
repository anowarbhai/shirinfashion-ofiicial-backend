<?php

namespace Digitrix\OmniBarta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OmniBartaClient
{
    public function sendOrder(array $payload, string $topic = 'order.created', ?string $eventId = null): Response
    {
        $url = (string) config('omnibarta.webhook_url');
        $secret = (string) config('omnibarta.webhook_secret');
        if ($url === '' || $secret === '') {
            throw new InvalidArgumentException('OmniBarta webhook URL and secret are required.');
        }
        $payload['schema_version'] = 1;
        $payload['provider'] = 'laravel';
        $payload['store_url'] ??= url('/');
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Http::timeout(15)->retry(3, 1000)->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Digitrix-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            'X-Digitrix-Event-ID' => $eventId ?: 'laravel-'.Str::uuid(),
            'X-Digitrix-Topic' => $topic,
            'User-Agent' => 'OmniBarta-Laravel/1.0.0',
        ])->withBody($body, 'application/json')->post($url)->throw();
    }
}
