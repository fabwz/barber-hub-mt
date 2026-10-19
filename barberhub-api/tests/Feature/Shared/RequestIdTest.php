<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestIdTest extends TestCase
{
    private const string HEADER = 'X-Request-Id';

    /** Every response carries a generated identifier. */
    public function test_response_carries_a_generated_identifier(): void
    {
        $requestId = $this->getJson('/api/v1')->assertOk()->headers->get(self::HEADER);

        $this->assertTrue(Str::isUuid($requestId));
    }

    /** The health route carries the identifier too. */
    public function test_health_route_carries_the_identifier(): void
    {
        $requestId = $this->getJson('/salud')->headers->get(self::HEADER);

        $this->assertTrue(Str::isUuid($requestId));
    }

    /** Error responses carry the identifier too. */
    public function test_error_response_carries_the_identifier(): void
    {
        $requestId = $this->getJson('/api/v1/desconocido')->assertNotFound()->headers->get(self::HEADER);

        $this->assertTrue(Str::isUuid($requestId));
    }

    /** A well-formed incoming identifier is propagated. */
    public function test_valid_incoming_identifier_is_reused(): void
    {
        $incoming = '0192F0C4-7B1E-7C3A-9F1D-2B6A4E8C0D11';

        $this->getJson('/api/v1', [self::HEADER => $incoming])
            ->assertHeader(self::HEADER, Str::lower($incoming));
    }

    /** A malformed incoming identifier is replaced. */
    public function test_malformed_incoming_identifier_is_replaced(): void
    {
        $requestId = $this->getJson('/api/v1', [self::HEADER => 'drop table'])->headers->get(self::HEADER);

        $this->assertTrue(Str::isUuid($requestId));
        $this->assertNotSame('drop table', $requestId);
    }

    /** Log entries of the request share the identifier through the context. */
    public function test_identifier_is_shared_with_the_log_context(): void
    {
        $requestId = $this->getJson('/api/v1')->headers->get(self::HEADER);

        $this->assertSame($requestId, Context::get('request_id'));
    }
}
