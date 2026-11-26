<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/proxy-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]));
    }

    /** A request that comes from the proxy of the Docker network is seen as the HTTPS request of the real client. */
    public function test_forwarded_headers_of_a_trusted_proxy_are_honoured(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.28.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/proxy-probe')
            ->assertOk()
            ->assertExactJson(['secure' => true, 'ip' => '203.0.113.7']);
    }

    /** Anyone else cannot claim to be behind HTTPS or to have another address. */
    public function test_forwarded_headers_of_any_other_address_are_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.1'])
            ->getJson('/proxy-probe')
            ->assertOk()
            ->assertExactJson(['secure' => false, 'ip' => '203.0.113.9']);
    }
}
