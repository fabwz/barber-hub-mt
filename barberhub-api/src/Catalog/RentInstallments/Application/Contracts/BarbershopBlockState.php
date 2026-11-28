<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\Contracts;

use DateTimeImmutable;

/**
 * The lock of a barbershop for unpaid rent, kept in `barbershops.blocked_at` (RF-58, PL-07). The state is that column and nothing else:
 * the pending months are not stored, they are counted. The three operations act on the row of one barbershop and are meant to run in the
 * transaction that is open.
 */
interface BarbershopBlockState
{
    /**
     * Lock the row of the barbershop until the transaction ends and return the instant it was blocked, or null when it is not blocked
     * (or does not exist). The block and the unblock take it first, so a payment that clears the rent and the daily task that blocks it
     * take turns and each one counts the pending months after the other has finished.
     */
    public function lock(string $barbershopId): ?DateTimeImmutable;

    /** Block the barbershop at this instant unless it is already blocked; true only when it changed, so a second block changes nothing. */
    public function block(string $barbershopId, DateTimeImmutable $at): bool;

    /** Unblock the barbershop; true only when it was blocked. */
    public function unblock(string $barbershopId): bool;
}
