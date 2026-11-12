<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Money;

/** An active service of the barbershop a phrase may refer to, with the price in force now (SV-03, SV-04). */
final readonly class ProposableService
{
    /** Carry the identifier, the name and the price with VAT included. */
    public function __construct(
        public string $id,
        public string $name,
        public Money $price,
    ) {}
}
