<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The base of the tests of the reservation by phrase: any HTTP request that a test did not simulate fails the test, so none of them
 * can reach Gemini (RF-37, CI stable). A test that wants the provider answers with `SimulatedGemini` or binds a fake extractor.
 */
abstract class AiTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
