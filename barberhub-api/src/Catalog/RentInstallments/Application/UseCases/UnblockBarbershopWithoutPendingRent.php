<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopBlockState;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;

final readonly class UnblockBarbershopWithoutPendingRent
{
    /** Receive the lock state, the count of pending months and the technical record. */
    public function __construct(
        private BarbershopBlockState $state,
        private CountPendingRentMonths $pendingMonths,
        private AuditLog $audit,
    ) {}

    /**
     * Unblock the barbershop when it is blocked and no pending months are left, and say whether it changed (RF-58, PL-11). The payment of
     * the rent calls it in the same transaction that settles the installments, with the barbershop already in context; it opens no
     * transaction of its own, so the payment and the unblock stand or fall together. It counts the pending months itself after taking the
     * lock of the barbershop, so it never unblocks on the word of its caller and never races with the daily block. Unblocking one that is
     * not blocked, or that still owes a month, changes nothing and records nothing. The entry in the technical record names the system
     * as author (PL-12).
     */
    public function execute(string $barbershopId, DateTimeImmutable $now): bool
    {
        $blockedAt = $this->state->lock($barbershopId);

        if ($blockedAt === null || $this->pendingMonths->execute($barbershopId, $now) > 0 || ! $this->state->unblock($barbershopId)) {
            return false;
        }

        $entry = AuditEntry::bySystem(AuditAction::BarbershopUnblocked, 'barbershop', $barbershopId, ['blocked_at' => RentCalendar::stamp($blockedAt)], ['blocked_at' => null], $now);

        if ($entry !== null) {
            $this->audit->record($entry);
        }

        return true;
    }
}
