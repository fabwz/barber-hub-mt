<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class PaymentDue
{
    /** Carry what a customer owes now, the balance left for the barbershop and the deadline of the temporary reservation; the deadline is null when there is nothing to pay. */
    public function __construct(
        public int $amount,
        public int $balanceAtShop,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
