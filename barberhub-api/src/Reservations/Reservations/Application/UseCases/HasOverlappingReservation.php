<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class HasOverlappingReservation
{
    /** Receive the reservation repository. */
    public function __construct(private ReservationRepository $reservations) {}

    /**
     * Whether a customer role has a confirmed reservation in the current barbershop that shares some minute with the interval, by the same rule
     * as the booking (RE-06). The waitlist asks it before it offers a space: a person with their own reservation then is not offered it (LE-03).
     */
    public function execute(string $customerMembershipId, TimeInterval $interval): bool
    {
        return $this->reservations->hasConfirmedOverlapping(new CustomerMembershipId($customerMembershipId), $interval);
    }
}
