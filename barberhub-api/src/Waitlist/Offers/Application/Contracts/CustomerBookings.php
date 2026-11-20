<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use Src\Shared\Domain\ValueObjects\TimeInterval;

interface CustomerBookings
{
    /** Whether the customer role has a confirmed reservation of the current barbershop that shares some minute with the interval: a person with their own reservation then is not offered the space (LE-03, RE-06). */
    public function hasOverlapping(string $customerMembershipId, TimeInterval $interval): bool;
}
