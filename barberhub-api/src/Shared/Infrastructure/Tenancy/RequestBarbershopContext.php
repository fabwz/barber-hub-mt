<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Tenancy;

use Illuminate\Auth\AuthenticationException;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class RequestBarbershopContext implements BarbershopContext
{
    /** Attribute of the request with the verified barbershop; the middleware sets it. */
    public const string BARBERSHOP_ATTRIBUTE = 'barbershop_id';

    /** Attribute of the request with the role of the person in that barbershop; empty on public routes. */
    public const string ROLE_ATTRIBUTE = 'barbershop_role';

    /** Read the barbershop that the middleware verified and set on the request; without it, nothing is reachable. */
    public function current(): BarbershopId
    {
        $barbershopId = request()->attributes->get(self::BARBERSHOP_ATTRIBUTE);

        return new BarbershopId(is_string($barbershopId) ? $barbershopId : throw new AuthenticationException);
    }
}
