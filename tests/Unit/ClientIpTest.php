<?php

namespace Tests\Unit;

use App\Support\ClientIp;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientIpTest extends TestCase
{
    public function test_public_client_cannot_spoof_forwarded_ip_header(): void
    {
        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.10',
            'SERVER_ADDR' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        ]);

        $this->assertSame('198.51.100.10', ClientIp::resolve($request));
    }

    public function test_same_server_proxy_can_forward_the_original_client_ip(): void
    {
        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.1',
            'SERVER_ADDR' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.25, 198.51.100.1',
        ]);

        $this->assertSame('203.0.113.25', ClientIp::resolve($request));
    }

    public function test_loopback_proxy_can_forward_the_original_client_ip(): void
    {
        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.30',
        ]);

        $this->assertSame('203.0.113.30', ClientIp::resolve($request));
    }

    public function test_explicitly_configured_public_proxy_can_forward_the_original_client_ip(): void
    {
        config()->set('app.checkout_trusted_proxies', ['198.51.100.10']);

        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.10',
            'SERVER_ADDR' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.40',
        ]);

        $this->assertSame('203.0.113.40', ClientIp::resolve($request));
    }
}
