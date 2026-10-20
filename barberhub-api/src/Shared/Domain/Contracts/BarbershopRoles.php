<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

interface BarbershopRoles
{
    /** Role a person holds in a barbershop through an active membership, or nothing when they hold none. */
    public function roleOf(UserId $userId, BarbershopId $barbershopId): ?string;
}
