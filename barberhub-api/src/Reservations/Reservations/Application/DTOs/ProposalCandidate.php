<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** A service or a barber of the catalog of the barbershop that the person can choose when a phrase fits several (IA-02). */
final readonly class ProposalCandidate
{
    /** Carry the identifier and the name as the catalog has them. */
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
