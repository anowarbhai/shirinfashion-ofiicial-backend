<?php

namespace Tests\Feature;

use App\Services\AdminSettingsService;
use App\Services\SmsGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SmsGatewayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upgrades_the_saved_legacy_onecodesoft_base_url(): void
    {
        $settings = app(AdminSettingsService::class);
        $saved = $settings->saveGroup('sms_integration', [
            'enabled' => true,
            'provider' => 'onecodesoft',
            'api_key' => 'test-token',
            'base_url' => 'https://sms.onecodesoft.com/api/',
        ]);

        $this->assertSame('https://sms.ocs-api.top/api', $saved['base_url']);
        $this->assertSame(
            'https://sms.ocs-api.top/api',
            $settings->getGroup('sms_integration')['base_url']
        );
    }

    public function test_it_sends_v3_sms_with_bearer_auth_and_json_body(): void
    {
        $this->configureSms();
        Http::fake([
            'https://sms.example.test/api/v3/send-sms' => Http::response([
                'status' => 'success',
                'message' => 'SMS request processed.',
                'data' => [
                    'total_cost' => 0.35,
                    'balance' => 10.65,
                    'results' => [[
                        'to' => '8801712345678',
                        'status' => 'sent',
                        'message_id' => 'message-1',
                        'sms_count' => 1,
                    ]],
                ],
            ]),
        ]);

        $result = app(SmsGatewayService::class)->sendMessage('01712345678', 'Test message');

        $this->assertSame('sent', $result['status_code']);
        $this->assertSame('message-1', $result['message_id']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sms.example.test/api/v3/send-sms'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['number'] === '8801712345678'
                && $request['message'] === 'Test message'
                && ! isset($request['api_key'])
                && ! isset($request['senderid']);
        });
    }

    public function test_it_uses_v3_custom_otp_sandbox_endpoint_without_sender_id(): void
    {
        $this->configureSms(['sandbox_mode' => true, 'sender_id' => 'BRAND']);
        Http::fake([
            'https://sms.example.test/api/v3/sandbox/otp' => Http::response([
                'status' => 'success',
                'message' => 'Sandbox simulation complete.',
                'data' => ['message_id' => 'otp-1'],
            ]),
        ]);

        $result = app(SmsGatewayService::class)->sendOtpMessage('01712345678', 'OTP 123456');

        $this->assertTrue($result['sandbox']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sms.example.test/api/v3/sandbox/otp'
            && $request['number'] === '8801712345678'
            && ! isset($request['senderid'])
        );
    }

    public function test_it_reads_balance_from_v3_response_envelope(): void
    {
        $this->configureSms();
        Http::fake([
            'https://sms.example.test/api/v3/balance' => Http::response([
                'status' => 'success',
                'message' => 'Balance fetched.',
                'data' => ['balance' => '500.00', 'estimate_sms' => 1428],
            ]),
        ]);

        $balance = app(SmsGatewayService::class)->getBalance();

        $this->assertSame('500.00', $balance['balance']);
        $this->assertSame(1428, $balance['estimate_sms']);
    }

    public function test_it_treats_http_200_error_envelope_as_failure(): void
    {
        $this->configureSms();
        Http::fake([
            '*' => Http::response([
                'status' => 'error',
                'message' => 'Insufficient balance.',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OneCodeSoft rejected the request: Insufficient balance.');

        app(SmsGatewayService::class)->sendMessage('01712345678', 'Test message');
    }

    /** @param array<string, mixed> $overrides */
    private function configureSms(array $overrides = []): void
    {
        app(AdminSettingsService::class)->saveGroup('sms_integration', array_merge([
            'enabled' => true,
            'provider' => 'onecodesoft',
            'api_key' => 'test-token',
            'sender_id' => '',
            'base_url' => 'https://sms.example.test/api',
            'sandbox_mode' => false,
        ], $overrides));
    }
}
