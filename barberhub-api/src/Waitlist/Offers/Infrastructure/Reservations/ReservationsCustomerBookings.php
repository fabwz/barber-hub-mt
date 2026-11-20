<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Reservations;

use Src\Reservations\Reservations\Application\UseCases\HasOverlappingReservation;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\CustomerBookings;

final readonly class ReservationsCustomerBookings implements CustomerBookings
{
    /** Receive the Reservations use case that tells whether a customer has a reservation in an interval. */
    public function __construct(private HasOverlappingReservation $hasOverlapping) {}

    /** Whether the customer role has a confirmed reservation that shares some minute with the interval, through the Reservations module. */
    public function hasOverlapping(string $customerMembershipId, TimeInterval $interval): bool
    {
        return $this->hasOverlapping->execute($customerMembershipId, $interval);
    }
}
