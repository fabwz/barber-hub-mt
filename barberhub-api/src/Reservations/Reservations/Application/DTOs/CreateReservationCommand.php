<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class CreateReservationCommand
{
    /**
     * Carry the person who books, the service, collaborator (none means "any available", DI-04) and start they choose, and whether they pay with a session of a voucher
     * (RF-28). The barbershop comes from the context, never from here. The waitlist, when it books the space of an offer, names the identifier
     * the reservation will have, so the offer can point at it before it exists, and the instant until which its payment may arrive (LE-04). When the owner registers the reservation for an assisted client (RF-12), `$assistedClientId` names that person and `$createdByMembershipId` the
     * owner role; then `$userId` is the owner, who is the author, and nobody pays online (RE-07).
     */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public ?string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public bool $withVoucher = false,
        public ?string $reservationId = null,
        public ?DateTimeImmutable $payUntil = null,
        public ?string $assistedClientId = null,
        public ?string $createdByMembershipId = null,
    ) {}
}
