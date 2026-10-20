<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;
use Tests\TestCase;

final class RequestBarbershopContextTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-0000-7000-8000-0000000000aa';

    /** The port is bound to the adapter that reads the request. */
    public function test_port_is_bound_to_the_request_adapter(): void
    {
        $this->assertInstanceOf(RequestBarbershopContext::class, $this->app->make(BarbershopContext::class));
    }

    /** The barbershop the middleware set on the request is the one the port answers. */
    public function test_answers_the_barbershop_of_the_request(): void
    {
        $this->requestWith(self::BARBERSHOP_ID);

        $this->assertSame(self::BARBERSHOP_ID, $this->app->make(BarbershopContext::class)->current()->value);
    }

    /** Without the middleware there is no barbershop, and asking for it fails instead of guessing. */
    public function test_fails_closed_without_a_barbershop(): void
    {
        $this->requestWith(null);

        $this->expectException(AuthenticationException::class);

        $this->app->make(BarbershopContext::class)->current();
    }

    /** A value that is not a barbershop identifier is not accepted either. */
    public function test_fails_closed_with_a_value_that_is_not_an_identifier(): void
    {
        $this->requestWith(['0192f0c4']);

        $this->expectException(AuthenticationException::class);

        $this->app->make(BarbershopContext::class)->current();
    }

    /** Make a request with the given barbershop attribute the current one. */
    private function requestWith(mixed $barbershopId): void
    {
        $request = Request::create('/');

        if ($barbershopId !== null) {
            $request->attributes->set(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE, $barbershopId);
        }

        $this->app->instance('request', $request);
    }
}
