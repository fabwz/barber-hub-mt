<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\UserId;

interface PlatformOperators
{
    /** Whether the account is a platform operator: a condition of the account, not a role of any barbershop (RF-55, PL-01). */
    public function isOperator(UserId $userId): bool;
}
